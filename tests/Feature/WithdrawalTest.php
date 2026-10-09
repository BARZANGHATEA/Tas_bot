<?php

namespace Tests\Feature;

use App\Enums\LedgerType;
use App\Enums\WithdrawalStatus;
use App\Models\Admin;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\WalletService;
use App\Services\WithdrawalService;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    private const ADDRESS = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private User $user;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fundBudget('1000');
        $this->setSettings([
            'withdraw.min' => '5',
            'withdraw.max' => '100',
            'telegram.public_channel_id' => '@payouts',
            'telegram.review_channel_id' => '-100123',
            'rewards.user_daily_cap' => '0',
            'rewards.platform_daily_cap' => '0',
        ]);
        $this->user = User::factory()->create();
        $this->admin = Admin::factory()->create();
        app(WalletService::class)->credit($this->user, '50', LedgerType::AdminCredit, 'seed:'.$this->user->id);
    }

    private function submit(array $overrides = [], ?string $key = null)
    {
        return $this->postJson('/api/miniapp/withdrawals', array_merge([
            'full_name' => 'Alex Morgan',
            'address' => self::ADDRESS,
            'amount' => '20',
            'network' => 'TRC20',
            'confirm' => true,
        ], $overrides), $this->playerHeaders($this->user) + ($key ? ['Idempotency-Key' => $key] : []));
    }

    public function test_request_reserves_funds_and_notifies(): void
    {
        $this->submit([], 'withdraw-key-1')->assertCreated()
            ->assertJsonPath('withdrawal.status', 'pending')
            ->assertJsonPath('withdrawal.fee', '1.00')
            ->assertJsonPath('withdrawal.net_amount', '19.00');

        // Retried submission with the same key creates nothing new.
        $this->submit([], 'withdraw-key-1')->assertCreated();
        $this->assertSame(1, Withdrawal::query()->count());

        $wallet = $this->user->wallet->fresh();
        $this->assertMoney('30', $wallet->available);
        $this->assertMoney('20', $wallet->reserved);

        $this->assertDatabaseHas('telegram_messages', ['chat_id' => (string) $this->user->telegram_id]);
        $this->assertDatabaseHas('telegram_messages', ['chat_id' => '-100123']);
    }

    public function test_validation_rules(): void
    {
        $this->submit(['amount' => '60'])->assertStatus(422)->assertJsonPath('code', 'insufficient_funds');
        $this->submit(['amount' => '4'])->assertStatus(422)->assertJsonPath('code', 'below_minimum');
        $this->submit(['amount' => '1000'])->assertStatus(422)->assertJsonPath('code', 'above_maximum');
        $this->submit(['address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6u'])->assertStatus(422)->assertJsonPath('code', 'invalid_address');
        $this->submit(['network' => 'BEP20'])->assertStatus(422)->assertJsonPath('code', 'network_unavailable');
        $this->submit(['amount' => '-5'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->submit(['confirm' => false])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->submit(['full_name' => '<script>'])->assertStatus(422)->assertJsonValidationErrors('full_name');

        $this->assertSame(0, Withdrawal::query()->count());
    }

    public function test_only_one_open_request_at_a_time(): void
    {
        $this->submit()->assertCreated();
        $this->submit(['amount' => '5'])->assertStatus(409)->assertJsonPath('code', 'pending_exists');
    }

    public function test_rejection_releases_funds_exactly_once(): void
    {
        $this->submit()->assertCreated();
        $withdrawal = Withdrawal::query()->firstOrFail();
        $service = app(WithdrawalService::class);

        $service->reject($withdrawal, $this->admin, 'Wrong name');
        $this->assertMoney('50', $this->user->wallet->fresh()->available);

        try {
            $service->reject($withdrawal->fresh(), $this->admin, 'again');
            $this->fail('Second rejection must fail');
        } catch (\App\Exceptions\BusinessRuleException) {
        }
        $this->assertMoney('50', $this->user->wallet->fresh()->available);
        $this->assertMoney('0', $this->user->wallet->fresh()->reserved);
    }

    public function test_full_payment_flow_publishes_masked_confirmation(): void
    {
        $this->submit()->assertCreated();
        $withdrawal = Withdrawal::query()->firstOrFail();
        $service = app(WithdrawalService::class);

        // Cannot jump straight to paid.
        try {
            $service->markPaid($withdrawal, $this->admin, str_repeat('a', 64), null);
            $this->fail('pending → paid must be refused');
        } catch (\App\Exceptions\BusinessRuleException) {
        }

        $service->approve($withdrawal, $this->admin);
        $this->assertFalse(TelegramMessage::query()->where('chat_id', '@payouts')->exists(), 'approval is not announced as paid');

        $service->markProcessing($withdrawal->fresh(), $this->admin);
        $paid = $service->markPaid($withdrawal->fresh(), $this->admin, str_repeat('ab12', 16), 'BATCH-7');

        $this->assertSame(WithdrawalStatus::Paid, $paid->status);
        $wallet = $this->user->wallet->fresh();
        $this->assertMoney('30', $wallet->available);
        $this->assertMoney('0', $wallet->reserved);
        $this->assertMoney('20', $wallet->total_withdrawn);

        $public = TelegramMessage::query()->where('chat_id', '@payouts')->sole();
        $this->assertStringContainsString('Alex M.', $public->text);
        $this->assertStringContainsString('19.00 USDT', $public->text);
        $this->assertStringContainsString('TR7N', $public->text);
        $this->assertStringContainsString('Lj6t', $public->text);
        $this->assertStringNotContainsString(self::ADDRESS, $public->text);
        $this->assertStringNotContainsString('Morgan', $public->text);

        // Paid withdrawals cannot be rejected or paid twice, and are published once.
        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        try {
            $service->reject($paid, $this->admin, 'oops');
        } finally {
            $service->publish($paid->fresh());
            $this->assertSame(1, TelegramMessage::query()->where('chat_id', '@payouts')->count());
            $this->assertMoney('30', $this->user->wallet->fresh()->available);
        }
    }

    public function test_user_can_cancel_a_pending_request(): void
    {
        $reference = $this->submit()->json('withdrawal.reference');

        $other = User::factory()->create();
        $this->postJson("/api/miniapp/withdrawals/{$reference}/cancel", [], $this->playerHeaders($other))->assertNotFound();

        $this->postJson("/api/miniapp/withdrawals/{$reference}/cancel", [], $this->playerHeaders($this->user))
            ->assertOk()->assertJsonPath('withdrawal.status', 'cancelled');
        $this->assertMoney('50', $this->user->wallet->fresh()->available);

        $this->postJson("/api/miniapp/withdrawals/{$reference}/cancel", [], $this->playerHeaders($this->user))->assertStatus(409);
    }

    public function test_shared_payout_address_is_flagged(): void
    {
        $this->submit()->assertCreated();

        $other = User::factory()->create();
        app(WalletService::class)->credit($other, '10', LedgerType::AdminCredit, 'seed-other');
        $this->postJson('/api/miniapp/withdrawals', [
            'full_name' => 'Sam Lee', 'address' => self::ADDRESS, 'amount' => '6', 'network' => 'TRC20', 'confirm' => true,
        ], $this->playerHeaders($other))->assertCreated();

        $this->assertDatabaseHas('fraud_flags', ['user_id' => $other->id, 'type' => 'shared_payout_address']);
        $this->assertDatabaseHas('fraud_flags', ['user_id' => $this->user->id, 'type' => 'shared_payout_address']);
    }

    public function test_restricted_users_cannot_withdraw(): void
    {
        $this->user->forceFill(['status' => 'restricted'])->save();
        $this->submit()->assertStatus(403)->assertJsonPath('code', 'account_restricted');
    }
}
