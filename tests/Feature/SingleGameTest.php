<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\GameRound;
use App\Models\LedgerEntry;
use App\Models\User;
use Tests\TestCase;

class SingleGameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fundBudget('100');
        $this->setSettings(['game.single.reward' => '0.05']);
    }

    public function test_doubles_win_and_are_paid_once(): void
    {
        $user = User::factory()->create();
        $this->dice->queue(4, 4);

        $response = $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user) + ['Idempotency-Key' => 'round-key-0001'])
            ->assertOk()
            ->assertJsonPath('round.dice', [4, 4])
            ->assertJsonPath('round.is_win', true)
            ->assertJsonPath('round.reward', '0.05');

        $this->assertSame('0.05', $response->json('balance'));

        // Replaying the same request returns the same round and pays nothing more.
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user) + ['Idempotency-Key' => 'round-key-0001'])
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('round.id', $response->json('round.id'));

        $this->assertSame(1, GameRound::query()->count());
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertMoney('0.05', $user->wallet->fresh()->available);
    }

    public function test_non_doubles_lose_without_reward(): void
    {
        $user = User::factory()->create();
        $this->dice->queue(2, 5);

        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))
            ->assertOk()
            ->assertJsonPath('round.is_win', false)
            ->assertJsonPath('round.reward', '0.00');

        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_client_supplied_results_are_ignored(): void
    {
        $user = User::factory()->create();
        $this->dice->queue(1, 6);

        $this->postJson('/api/miniapp/games/single/play', ['dice' => [6, 6], 'is_win' => true, 'reward' => '1000'], $this->playerHeaders($user))
            ->assertOk()
            ->assertJsonPath('round.dice', [1, 6])
            ->assertJsonPath('round.is_win', false);

        $this->assertMoney('0', $user->wallet->fresh()->available);
    }

    public function test_cooldown_is_enforced_on_the_server(): void
    {
        $this->setSettings(['game.single.cooldown_seconds' => 30]);
        $user = User::factory()->create();
        $this->dice->queue(1, 2, 3, 3);

        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))->assertOk();
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))
            ->assertStatus(429)
            ->assertJsonPath('code', 'cooldown');

        $this->travel(31)->seconds();
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))->assertOk();
    }

    public function test_daily_limit_is_enforced(): void
    {
        $this->setSettings(['game.single.daily_limit' => 2]);
        $user = User::factory()->create();
        $this->dice->queue(1, 2, 3, 4);

        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))->assertOk();
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))->assertOk();
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))
            ->assertStatus(429)->assertJsonPath('code', 'daily_limit');
    }

    public function test_game_pauses_when_the_budget_is_empty(): void
    {
        $this->setSettings(['game.single.reward' => '500']);
        $user = User::factory()->create();

        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))
            ->assertStatus(503)->assertJsonPath('code', 'budget_exhausted');
        $this->assertSame(0, GameRound::query()->count());
    }

    public function test_win_over_daily_cap_is_recorded_as_unfunded(): void
    {
        $this->setSettings(['rewards.user_daily_cap' => '0.05']);
        $user = User::factory()->create();
        $this->dice->queue(2, 2, 3, 3);

        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))->assertJsonPath('round.reward_status', 'credited');
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))
            ->assertOk()
            ->assertJsonPath('round.is_win', true)
            ->assertJsonPath('round.reward_status', 'unfunded');

        $this->assertMoney('0.05', $user->wallet->fresh()->available);
    }

    public function test_restricted_users_cannot_play(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Restricted]);

        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))
            ->assertStatus(403)->assertJsonPath('code', 'account_restricted');
    }

    public function test_maintenance_mode_blocks_games(): void
    {
        $this->setSettings(['game.maintenance' => true]);
        $user = User::factory()->create();

        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))
            ->assertStatus(503)->assertJsonPath('code', 'game_maintenance');
    }

    public function test_api_requires_authentication(): void
    {
        $this->postJson('/api/miniapp/games/single/play')->assertUnauthorized();
        $this->getJson('/api/miniapp/home', ['Authorization' => 'Bearer '.str_repeat('a', 64)])->assertUnauthorized();
    }

    public function test_settled_rounds_are_immutable(): void
    {
        $user = User::factory()->create();
        $this->dice->queue(5, 5);
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user))->assertOk();

        $this->expectException(\LogicException::class);
        GameRound::query()->first()->update(['die_two' => 1]);
    }
}
