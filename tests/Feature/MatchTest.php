<?php

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\LedgerEntry;
use App\Models\User;
use Tests\TestCase;

class MatchTest extends TestCase
{
    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fundBudget('100');
        $this->setSettings([
            'game.multi.rounds' => 1,
            'game.multi.dice_count' => 2,
            'game.multi.reward_mode' => 'fixed',
            'game.multi.reward' => '0.10',
            'game.multi.tie_rule' => 'extra_round',
            'game.multi.max_extra_rounds' => 1,
            'game.multi.cooldown_seconds' => 0,
        ]);
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    private function createMatch(User $user, string $visibility = 'public'): array
    {
        return $this->postJson('/api/miniapp/matches', ['visibility' => $visibility], $this->playerHeaders($user))
            ->assertCreated()->json('match');
    }

    public function test_full_match_flow_pays_the_winner_once(): void
    {
        $match = $this->createMatch($this->alice);
        $this->assertSame('waiting', $match['status']);

        $this->postJson("/api/miniapp/matches/{$match['uuid']}/join", [], $this->playerHeaders($this->bob))
            ->assertOk()->assertJsonPath('match.status', 'ready');

        $this->dice->queue(6, 5, 1, 2);
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($this->alice))
            ->assertOk()->assertJsonPath('match.status', 'playing')->assertJsonPath('roll.total', 11);

        // Rolling twice in the same round is refused.
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($this->alice))
            ->assertStatus(409)->assertJsonPath('code', 'already_rolled');

        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($this->bob))
            ->assertOk()
            ->assertJsonPath('match.status', 'completed')
            ->assertJsonPath('match.result', 'lost');

        $this->getJson("/api/miniapp/matches/{$match['uuid']}", $this->playerHeaders($this->alice))
            ->assertJsonPath('match.result', 'won')
            ->assertJsonPath('match.score.you', 1);

        $this->assertMoney('0.10', $this->alice->wallet->fresh()->available);
        $this->assertMoney('0', $this->bob->wallet->fresh()->available);
        $this->assertSame(1, LedgerEntry::query()->where('type', 'match_reward')->count());

        // A completed match cannot be rolled or settled again.
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($this->bob))
            ->assertStatus(409);
    }

    public function test_tie_triggers_extra_round_then_draw(): void
    {
        $match = $this->createMatch($this->alice);
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/join", [], $this->playerHeaders($this->bob))->assertOk();

        // Round 1 tie (7 vs 7) → extra round; round 2 tie again → max extra reached → draw.
        $this->dice->queue(3, 4, 5, 2, 1, 1, 1, 1);

        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($this->alice))->assertOk();
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($this->bob))
            ->assertOk()->assertJsonPath('match.total_rounds', 2)->assertJsonPath('match.current_round', 2);

        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($this->alice))->assertOk();
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($this->bob))
            ->assertOk()->assertJsonPath('match.status', 'completed')->assertJsonPath('match.result', 'tie');

        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_split_tie_rule_shares_the_reward(): void
    {
        $this->setSettings(['game.multi.tie_rule' => 'split']);
        $match = $this->createMatch($this->alice);
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/join", [], $this->playerHeaders($this->bob))->assertOk();
        $this->dice->queue(2, 2, 1, 3);

        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($this->alice))->assertOk();
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($this->bob))->assertJsonPath('match.result', 'tie');

        $this->assertMoney('0.05', $this->alice->wallet->fresh()->available);
        $this->assertMoney('0.05', $this->bob->wallet->fresh()->available);
    }

    public function test_cannot_join_own_match_or_join_twice_or_roll_for_others(): void
    {
        $match = $this->createMatch($this->alice);
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/join", [], $this->playerHeaders($this->alice))
            ->assertStatus(422)->assertJsonPath('code', 'own_match');

        $this->postJson("/api/miniapp/matches/{$match['uuid']}/join", [], $this->playerHeaders($this->bob))->assertOk();
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/join", [], $this->playerHeaders($this->bob))->assertOk();

        $carol = User::factory()->create();
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/join", [], $this->playerHeaders($carol))
            ->assertStatus(409)->assertJsonPath('code', 'match_closed');
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($carol))
            ->assertStatus(403)->assertJsonPath('code', 'not_participant');
        $this->getJson("/api/miniapp/matches/{$match['uuid']}", $this->playerHeaders($carol))->assertNotFound();
    }

    public function test_private_match_requires_the_invite_code(): void
    {
        $match = $this->createMatch($this->alice, 'private');
        $code = GameMatch::query()->where('uuid', $match['uuid'])->value('invite_code');
        $this->assertSame($code, $match['invite_code']);

        $this->getJson("/api/miniapp/matches/{$match['uuid']}", $this->playerHeaders($this->bob))->assertNotFound();
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/join", ['code' => 'wrong-code'], $this->playerHeaders($this->bob))
            ->assertStatus(403)->assertJsonPath('code', 'invite_required');
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/join", ['code' => $code], $this->playerHeaders($this->bob))
            ->assertOk()->assertJsonPath('match.status', 'ready');

        // Private matches are not listed publicly.
        $this->getJson('/api/miniapp/matches', $this->playerHeaders(User::factory()->create()))->assertJsonCount(0, 'open');
    }

    public function test_invitation_to_specific_player(): void
    {
        $this->postJson('/api/miniapp/matches', ['visibility' => 'public', 'invite' => '@'.$this->bob->username], $this->playerHeaders($this->alice))
            ->assertCreated()->assertJsonPath('match.visibility', 'private');

        $match = GameMatch::query()->firstOrFail();
        $this->assertSame($this->bob->id, $match->invited_user_id);
        $this->assertDatabaseHas('telegram_messages', ['chat_id' => (string) $this->bob->telegram_id]);

        $carol = User::factory()->create();
        $this->postJson("/api/miniapp/matches/{$match->uuid}/join", ['code' => $match->invite_code], $this->playerHeaders($carol))
            ->assertStatus(403);
    }

    public function test_creator_can_cancel_only_while_waiting_and_stale_matches_expire(): void
    {
        $match = $this->createMatch($this->alice);
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/cancel", [], $this->playerHeaders($this->bob))->assertStatus(403);
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/cancel", [], $this->playerHeaders($this->alice))
            ->assertOk()->assertJsonPath('match.status', 'cancelled');

        $second = $this->createMatch($this->alice);
        $this->travel(31)->minutes();
        $this->artisan('matches:expire')->assertSuccessful();
        $this->assertSame(MatchStatus::Cancelled, GameMatch::query()->where('uuid', $second['uuid'])->first()->status);
    }

    public function test_open_match_limit(): void
    {
        $this->setSettings(['game.multi.max_open' => 1]);
        $this->createMatch($this->alice);
        $this->postJson('/api/miniapp/matches', ['visibility' => 'public'], $this->playerHeaders($this->alice))
            ->assertStatus(422)->assertJsonPath('code', 'too_many_open');
    }
}
