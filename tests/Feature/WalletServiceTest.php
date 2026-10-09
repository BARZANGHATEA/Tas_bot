<?php

namespace Tests\Feature;

use App\Enums\LedgerType;
use App\Exceptions\BudgetExhaustedException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientFundsException;
use App\Models\Admin;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\BudgetService;
use App\Services\WalletService;
use LogicException;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    private WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = app(WalletService::class);
        $this->setSettings(['rewards.platform_daily_cap' => '0', 'rewards.user_daily_cap' => '0']);
    }

    public function test_credit_is_idempotent_and_draws_from_budget(): void
    {
        $this->fundBudget('10');
        $user = User::factory()->create();

        $first = $this->wallet->credit($user, '1.25', LedgerType::GameReward, 'test:1');
        $second = $this->wallet->credit($user, '1.25', LedgerType::GameReward, 'test:1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertMoney('1.25', $user->wallet->fresh()->available);
        $this->assertMoney('1.25', $user->wallet->fresh()->total_earned);
        $this->assertMoney('8.75', app(BudgetService::class)->available());
    }

    public function test_rewards_cannot_exceed_the_funded_budget(): void
    {
        $this->fundBudget('1');
        $user = User::factory()->create();

        $this->expectException(BudgetExhaustedException::class);
        $this->wallet->credit($user, '1.000001', LedgerType::GameReward, 'test:big');
    }

    public function test_user_daily_cap_is_enforced(): void
    {
        $this->fundBudget('100');
        $this->setSettings(['rewards.user_daily_cap' => '1']);
        $user = User::factory()->create();

        $this->wallet->credit($user, '0.6', LedgerType::GameReward, 'cap:1');
        $this->expectException(BudgetExhaustedException::class);
        $this->wallet->credit($user, '0.6', LedgerType::GameReward, 'cap:2');
    }

    public function test_reserve_release_and_payout_settle_exactly_once(): void
    {
        $this->fundBudget('100');
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $this->wallet->credit($user, '10', LedgerType::GameReward, 'seed');

        $withdrawal = $this->makeWithdrawal($user, '6');
        $this->wallet->reserve($withdrawal);
        $this->wallet->reserve($withdrawal); // replay
        $wallet = $user->wallet->fresh();
        $this->assertMoney('4', $wallet->available);
        $this->assertMoney('6', $wallet->reserved);

        $this->wallet->payout($withdrawal, $admin);
        $this->wallet->payout($withdrawal, $admin); // replay is a no-op
        $wallet = $user->wallet->fresh();
        $this->assertMoney('4', $wallet->available);
        $this->assertMoney('0', $wallet->reserved);
        $this->assertMoney('6', $wallet->total_withdrawn);

        // A paid withdrawal can never be released afterwards.
        $this->expectException(BusinessRuleException::class);
        $this->wallet->release($withdrawal, $admin);
    }

    public function test_cannot_reserve_more_than_available(): void
    {
        $user = User::factory()->create();
        $this->expectException(InsufficientFundsException::class);
        $this->wallet->reserve($this->makeWithdrawal($user, '1'));
    }

    public function test_reversal_happens_once_and_returns_funds_to_budget(): void
    {
        $this->fundBudget('10');
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $entry = $this->wallet->credit($user, '2', LedgerType::ReferralReward, 'ref:x');

        $this->wallet->reverse($entry, 'fraud', $admin);
        $this->assertMoney('0', $user->wallet->fresh()->available);
        $this->assertMoney('10', app(BudgetService::class)->available());

        $this->expectException(BusinessRuleException::class);
        $this->wallet->reverse($entry, 'again', $admin);
    }

    public function test_ledger_entries_are_immutable(): void
    {
        $this->fundBudget('10');
        $entry = $this->wallet->credit(User::factory()->create(), '1', LedgerType::GameReward, 'imm');

        $this->expectException(LogicException::class);
        $entry->update(['available_delta' => '1000']);
    }

    public function test_reconciliation_detects_tampering(): void
    {
        $this->fundBudget('10');
        $user = User::factory()->create();
        $this->wallet->credit($user, '3', LedgerType::GameReward, 'rec');

        $this->assertSame([], $this->wallet->reconcile());

        // Simulate a direct database edit bypassing the wallet service.
        $user->wallet()->getQuery()->toBase()->update(['available' => '999']);
        $problems = $this->wallet->reconcile();
        $this->assertCount(1, $problems);
        $this->assertSame('available', $problems[0]['field']);
    }

    private function makeWithdrawal(User $user, string $amount): Withdrawal
    {
        return Withdrawal::query()->create([
            'reference' => 'WD-'.strtoupper(\Illuminate\Support\Str::random(10)),
            'user_id' => $user->id,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'full_name' => 'Test User',
            'network' => 'TRC20',
            'address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'amount' => $amount,
            'fee' => '0',
            'net_amount' => $amount,
            'status' => 'pending',
        ]);
    }
}
