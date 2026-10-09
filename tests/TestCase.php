<?php

namespace Tests;

use App\Contracts\DiceRoller;
use App\Models\Admin;
use App\Models\AppSession;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\Settings;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeDiceRoller;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected FakeDiceRoller $dice;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dicegame.telegram.bot_token' => '123456:TEST-TOKEN',
            'dicegame.telegram.bot_username' => 'DiceTestBot',
            'dicegame.telegram.webhook_secret' => 'webhook-secret',
        ]);

        // No real network calls from tests.
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->dice = new FakeDiceRoller;
        $this->app->instance(DiceRoller::class, $this->dice);

        // Deterministic defaults for tests; individual tests override as needed.
        $this->setSettings([
            'game.single.cooldown_seconds' => 0,
            'withdraw.min_account_age_hours' => 0,
            'withdraw.min_games' => 0,
            'referral.qualify_min_age_hours' => 0,
        ]);
    }

    protected function setSettings(array $values): void
    {
        app(Settings::class)->update($values);
    }

    protected function fundBudget(string $amount = '1000'): void
    {
        app(BudgetService::class)->fund(Admin::factory()->create(), Money::of($amount), 'test funding');
    }

    /** Bearer-token headers for a player (as issued after init-data validation). */
    protected function playerHeaders(User $user): array
    {
        $token = Str::random(64);
        AppSession::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
        ]);

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    protected function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertTrue(Money::of($expected)->isEqualTo(Money::of((string) $actual)), "Expected {$expected}, got {$actual}");
    }
}
