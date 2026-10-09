<?php

namespace Tests\Feature;

use App\Enums\MissionType;
use App\Enums\MissionVerification;
use App\Models\Admin;
use App\Models\Mission;
use App\Models\MissionCompletion;
use App\Models\User;
use App\Services\MissionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fundBudget('100');
    }

    public function test_telegram_membership_is_verified_with_the_bot_api(): void
    {
        $user = User::factory()->create();
        $mission = Mission::factory()->create(['reward' => '0.20']);

        $this->fakeTelegram(['api.telegram.org/*/getChatMember' => Http::sequence()
            ->push(['ok' => true, 'result' => ['status' => 'left']])
            ->push(['ok' => true, 'result' => ['status' => 'member']])]);

        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))
            ->assertStatus(422)->assertJsonPath('code', 'not_member');

        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))
            ->assertOk()->assertJsonPath('status', 'rewarded');

        $this->assertMoney('0.20', $user->wallet->fresh()->available);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'getChatMember')
            && $request['user_id'] === $user->telegram_id && $request['chat_id'] === '@dice_news');

        // Claiming again is refused and pays nothing.
        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))
            ->assertStatus(409)->assertJsonPath('code', 'already_claimed');
        $this->assertMoney('0.20', $user->wallet->fresh()->available);
    }

    public function test_instagram_missions_require_moderator_review(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $mission = Mission::factory()->create([
            'type' => MissionType::Instagram, 'verification' => MissionVerification::AdminReview,
            'target' => 'dice_official', 'reward' => '0.30',
        ]);

        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))
            ->assertStatus(422)->assertJsonPath('code', 'proof_required');

        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", ['proof' => '@my_insta'], $this->playerHeaders($user))
            ->assertOk()->assertJsonPath('status', 'pending_review');
        $this->assertMoney('0', $user->wallet->fresh()->available, 'no reward before review');

        $completion = MissionCompletion::query()->firstOrFail();
        app(MissionService::class)->approve($completion, $admin);
        $this->assertMoney('0.30', $user->wallet->fresh()->available);

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        app(MissionService::class)->approve($completion->fresh(), $admin);
    }

    public function test_rejected_submission_can_be_resubmitted(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $mission = Mission::factory()->create(['type' => MissionType::Custom, 'verification' => MissionVerification::AdminReview]);

        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", ['proof' => 'done'], $this->playerHeaders($user))->assertOk();
        app(MissionService::class)->reject(MissionCompletion::query()->firstOrFail(), $admin, 'Screenshot unreadable');

        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", ['proof' => 'done again'], $this->playerHeaders($user))
            ->assertOk()->assertJsonPath('status', 'pending_review');
        $this->assertSame(1, MissionCompletion::query()->count());
    }

    public function test_website_visit_requires_opening_and_waiting(): void
    {
        $this->setSettings(['missions.visit_min_seconds' => 20]);
        $user = User::factory()->create();
        $mission = Mission::factory()->create([
            'type' => MissionType::Website, 'verification' => MissionVerification::VisitTimer, 'target' => 'https://example.com', 'reward' => '0.02',
        ]);

        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))
            ->assertStatus(422)->assertJsonPath('code', 'visit_required');

        $this->postJson("/api/miniapp/missions/{$mission->id}/start", [], $this->playerHeaders($user))->assertOk();
        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))
            ->assertStatus(429)->assertJsonPath('code', 'visit_too_short');

        $this->travel(21)->seconds();
        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))
            ->assertOk()->assertJsonPath('status', 'rewarded');
    }

    public function test_daily_activity_mission_repeats_each_day(): void
    {
        $user = User::factory()->create();
        $mission = Mission::factory()->create([
            'type' => MissionType::DailyActivity, 'verification' => MissionVerification::Automatic,
            'target_count' => 2, 'repeat' => 'daily', 'reward' => '0.01',
        ]);

        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))
            ->assertStatus(422)->assertJsonPath('code', 'requirements_not_met');

        $this->dice->queue(1, 2, 3, 4);
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user));
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user));

        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))->assertOk();
        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))->assertStatus(409);

        $this->travel(1)->days();
        $this->dice->queue(1, 2, 3, 4);
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user));
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($user));
        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders($user))->assertOk();

        $this->assertMoney('0.02', $user->wallet->fresh()->available);
    }

    public function test_mission_budget_and_limits(): void
    {
        $mission = Mission::factory()->create(['reward' => '0.20', 'budget' => '0.40']);
        $this->fakeTelegram(['api.telegram.org/*/getChatMember' => Http::response(['ok' => true, 'result' => ['status' => 'member']])]);

        foreach (range(1, 2) as $i) {
            $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders(User::factory()->create()))->assertOk();
        }

        $this->assertSame('completed', $mission->fresh()->status, 'mission closes when its budget is used');
        $this->postJson("/api/miniapp/missions/{$mission->id}/claim", [], $this->playerHeaders(User::factory()->create()))
            ->assertStatus(404)->assertJsonPath('code', 'mission_unavailable');
    }

    public function test_paused_and_scheduled_missions_are_hidden(): void
    {
        Mission::factory()->create(['status' => 'paused', 'title' => 'Paused']);
        Mission::factory()->create(['starts_at' => now()->addDay(), 'title' => 'Future']);
        Mission::factory()->create(['title' => 'Live']);

        $this->getJson('/api/miniapp/missions', $this->playerHeaders(User::factory()->create()))
            ->assertOk()->assertJsonCount(1, 'missions')->assertJsonPath('missions.0.title', 'Live');
    }
}
