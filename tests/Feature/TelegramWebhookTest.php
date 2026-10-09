<?php

namespace Tests\Feature;

use App\Models\TelegramMessage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    private function update(int $updateId, string $text, int $fromId = 777001): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Dana', 'username' => 'dana_d'],
                'chat' => ['id' => $fromId, 'type' => 'private'],
                'date' => time(),
                'text' => $text,
            ],
        ];
    }

    private function deliver(array $update, ?string $secret = 'webhook-secret')
    {
        return $this->postJson('/api/telegram/webhook', $update, $secret ? ['X-Telegram-Bot-Api-Secret-Token' => $secret] : []);
    }

    public function test_requests_without_the_secret_token_are_rejected(): void
    {
        $this->deliver($this->update(1, '/start'), null)->assertForbidden();
        $this->deliver($this->update(1, '/start'), 'wrong')->assertForbidden();
        $this->assertSame(0, User::query()->count());
    }

    public function test_start_registers_user_with_referral_and_sends_welcome(): void
    {
        $inviter = User::factory()->create();

        $this->deliver($this->update(10, '/start ref_'.$inviter->referral_code))->assertOk();

        $user = User::query()->where('telegram_id', 777001)->firstOrFail();
        $this->assertSame($inviter->id, $user->referrer_id);
        $this->assertNotNull($user->wallet);

        $welcome = TelegramMessage::query()->where('chat_id', '777001')->firstOrFail();
        $this->assertStringContainsString('Dana', $welcome->text);
        $this->assertSame(url('/app'), $welcome->reply_markup['inline_keyboard'][0][0]['web_app']['url']);
    }

    public function test_duplicate_deliveries_are_processed_once(): void
    {
        $this->deliver($this->update(20, '/start'))->assertOk();
        $this->deliver($this->update(20, '/start'))->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, TelegramMessage::query()->count());
    }

    public function test_admin_commands_are_restricted(): void
    {
        $this->deliver($this->update(30, '/stats'))->assertOk();
        $this->assertStringNotContainsString('Stats', TelegramMessage::query()->latest('id')->first()->text);

        \App\Models\Admin::factory()->create(['telegram_id' => 777001]);
        $this->deliver($this->update(31, '/stats'))->assertOk();
        $this->assertStringContainsString('Stats', TelegramMessage::query()->latest('id')->first()->text);
    }

    public function test_outbox_is_delivered_with_retries(): void
    {
        $this->fakeTelegram(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500)
            ->push(['ok' => true, 'result' => ['message_id' => 5]])]);

        $this->deliver($this->update(40, '/help'))->assertOk();
        $message = TelegramMessage::query()->firstOrFail();
        $this->assertSame('pending', $message->fresh()->status, 'first attempt failed and will be retried');

        $this->travel(5)->minutes();
        $this->artisan('telegram:dispatch')->assertSuccessful();
        $this->assertSame('sent', $message->fresh()->status);
    }
}
