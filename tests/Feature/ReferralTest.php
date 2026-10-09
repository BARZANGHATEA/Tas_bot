<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\PlayerService;
use App\Services\ReferralService;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fundBudget('100');
        $this->setSettings([
            'referral.qualify_min_games' => 2,
            'referral.qualify_min_age_hours' => 0,
            'referral.max_depth' => 3,
            'referral.levels' => [
                ['fixed' => '0.10', 'percent' => '10'],
                ['fixed' => '0.05', 'percent' => '5'],
                ['fixed' => '0.01', 'percent' => '0'],
            ],
            'game.single.reward' => '1.00',
        ]);
    }

    private function register(int $telegramId, ?string $start = null): User
    {
        [$user] = app(PlayerService::class)->findOrRegister(['id' => $telegramId, 'first_name' => 'U'.$telegramId], $start, null, 'bot');

        return $user;
    }

    public function test_referral_is_attributed_once_and_never_reassigned(): void
    {
        $alice = $this->register(1001);
        $bob = $this->register(1002, 'ref_'.$alice->referral_code);
        $this->assertSame($alice->id, $bob->referrer_id);

        $carol = $this->register(1003);
        $bobAgain = $this->register(1002, 'ref_'.$carol->referral_code);
        $this->assertSame($alice->id, $bobAgain->referrer_id, 'existing relationship is not reassigned');
    }

    public function test_self_referral_and_unknown_codes_are_ignored(): void
    {
        $alice = $this->register(2001);
        $this->assertNull($alice->referrer_id);
        $this->assertNull($this->register(2002, 'ref_ZZZZZZZZ')->referrer_id);
        $this->assertNull($this->register(2003, 'garbage')->referrer_id);

        // Re-opening your own link never creates a self-referral.
        $this->assertNull($this->register(2001, 'ref_'.$alice->referral_code)->referrer_id);
    }

    public function test_multi_level_qualification_bonus_is_paid_once(): void
    {
        $a = $this->register(3001);
        $b = $this->register(3002, 'ref_'.$a->referral_code);
        $c = $this->register(3003, 'ref_'.$b->referral_code);
        $d = $this->register(3004, 'ref_'.$c->referral_code);

        // d loses twice → qualifies; upline c (L1), b (L2), a (L3) are paid.
        $this->dice->queue(1, 2, 3, 4);
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($d))->assertOk();
        $this->assertNull($d->fresh()->referral_qualified_at);
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($d))->assertOk();
        $this->assertNotNull($d->fresh()->referral_qualified_at);

        $this->assertMoney('0.10', $c->wallet->fresh()->available);
        $this->assertMoney('0.05', $b->wallet->fresh()->available);
        $this->assertMoney('0.01', $a->wallet->fresh()->available);

        // Running the check again (e.g. retried request) pays nothing more.
        app(ReferralService::class)->checkQualification($d->fresh());
        $this->assertSame(3, ReferralReward::query()->where('event', 'qualification')->count());
    }

    public function test_commission_on_rewards_of_qualified_referrals(): void
    {
        $a = $this->register(4001);
        $b = $this->register(4002, 'ref_'.$a->referral_code);

        $this->dice->queue(1, 2, 3, 4, 6, 6);
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($b))->assertOk();
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($b))->assertOk(); // qualifies
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($b))->assertJsonPath('round.is_win', true);

        // 0.10 qualification bonus + 10% of 1.00 = 0.20
        $this->assertMoney('0.20', $a->wallet->fresh()->available);
        $this->assertMoney('1.00', $b->wallet->fresh()->available, 'the referred user keeps their full reward');
    }

    public function test_admin_can_reverse_a_referral_reward_once(): void
    {
        $a = $this->register(5001);
        $b = $this->register(5002, 'ref_'.$a->referral_code);
        $this->dice->queue(1, 2, 3, 4);
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($b));
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($b));

        $reward = ReferralReward::query()->where('beneficiary_id', $a->id)->firstOrFail();
        $admin = Admin::factory()->create();
        app(ReferralService::class)->reverse($reward, 'Duplicate account', $admin);

        $this->assertSame('reversed', $reward->fresh()->status);
        $this->assertMoney('0', $a->wallet->fresh()->available);
        $this->assertDatabaseHas('audit_logs', ['action' => 'referral.reward_reversed']);

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        app(ReferralService::class)->reverse($reward->fresh(), 'again', $admin);
    }

    public function test_referral_velocity_is_flagged(): void
    {
        $this->setSettings(['referral.fraud_max_per_hour' => 2]);
        $a = $this->register(6001);
        foreach (range(1, 3) as $i) {
            $this->register(6100 + $i, 'ref_'.$a->referral_code);
        }

        $this->assertDatabaseHas('fraud_flags', ['user_id' => $a->id, 'type' => 'referral_velocity']);
        $this->assertTrue($a->fresh()->is_flagged);
    }

    public function test_flagged_users_do_not_qualify(): void
    {
        $a = $this->register(7001);
        $b = $this->register(7002, 'ref_'.$a->referral_code);
        $b->forceFill(['is_flagged' => true])->save();

        $this->dice->queue(1, 2, 3, 4);
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($b));
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($b));

        $this->assertNull($b->fresh()->referral_qualified_at);
        $this->assertSame(0, ReferralReward::query()->count());
    }
}
