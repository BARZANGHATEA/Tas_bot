<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Telegram\InitDataValidator;
use Tests\TestCase;

class MiniAppAuthTest extends TestCase
{
    private function initData(array $user, array $extra = []): string
    {
        return InitDataValidator::sign(array_merge([
            'user' => json_encode($user),
            'auth_date' => (string) time(),
        ], $extra), '123456:TEST-TOKEN');
    }

    public function test_valid_init_data_creates_a_session_and_registers_the_user(): void
    {
        $inviter = User::factory()->create();

        $response = $this->postJson('/api/miniapp/auth', [
            'init_data' => $this->initData(['id' => 555, 'first_name' => 'Eve'], ['start_param' => 'ref_'.$inviter->referral_code]),
        ])->assertOk()->assertJsonPath('new_user', true);

        $token = $response->json('token');
        $this->assertSame(64, strlen($token));

        $user = User::query()->where('telegram_id', 555)->firstOrFail();
        $this->assertSame($inviter->id, $user->referrer_id);
        $this->assertDatabaseMissing('app_sessions', ['token_hash' => $token]);

        $this->getJson('/api/miniapp/home', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('user.first_name', 'Eve')
            ->assertJsonPath('stats.available', '0.00');
    }

    public function test_invalid_init_data_is_rejected(): void
    {
        $this->postJson('/api/miniapp/auth', ['init_data' => 'user=%7B%22id%22%3A1%7D&auth_date=1&hash='.str_repeat('0', 64)])
            ->assertUnauthorized()->assertJsonPath('code', 'auth_invalid');
        $this->assertSame(0, User::query()->count());
    }

    public function test_dev_login_is_disabled_outside_local(): void
    {
        config(['dicegame.telegram.dev_auth' => true]);

        $this->postJson('/api/miniapp/auth', ['dev_user' => ['id' => 1, 'first_name' => 'Hacker']])
            ->assertStatus(401);
        $this->assertSame(0, User::query()->count());
    }

    public function test_suspended_users_cannot_sign_in(): void
    {
        User::factory()->create(['telegram_id' => 556, 'status' => 'suspended']);

        $this->postJson('/api/miniapp/auth', ['init_data' => $this->initData(['id' => 556, 'first_name' => 'Sus'])])
            ->assertForbidden()->assertJsonPath('code', 'account_suspended');
    }

    public function test_global_maintenance_mode(): void
    {
        $this->setSettings(['app.maintenance' => true, 'app.maintenance_message' => 'Back at noon']);

        $this->getJson('/api/miniapp/home', $this->playerHeaders(User::factory()->create()))
            ->assertStatus(503)->assertJsonPath('message', 'Back at noon');
    }

    public function test_expired_sessions_are_rejected(): void
    {
        $user = User::factory()->create();
        $headers = $this->playerHeaders($user);
        $this->travel(2)->hours();

        $this->getJson('/api/miniapp/home', $headers)->assertUnauthorized();
    }
}
