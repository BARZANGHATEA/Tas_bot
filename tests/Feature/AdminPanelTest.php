<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\LedgerType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\FraudFlag;
use App\Models\GameMatch;
use App\Models\Mission;
use App\Models\MissionCompletion;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Settings;
use App\Services\WalletService;
use Database\Seeders\MissionSeeder;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    private Admin $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = Admin::factory()->create(['password' => 'correct-horse-123']);
        $this->fundBudget('500');
        $this->setSettings(['rewards.user_daily_cap' => '0', 'rewards.platform_daily_cap' => '0']);
    }

    private function seedActivity(): array
    {
        $this->seed(MissionSeeder::class);
        $alice = User::factory()->create();
        $bob = User::factory()->referredBy($alice)->create();
        app(WalletService::class)->credit($bob, '20', LedgerType::AdminCredit, 'seed-bob');

        $this->dice->queue(3, 3, 1, 2);
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($bob));
        $this->postJson('/api/miniapp/games/single/play', [], $this->playerHeaders($bob));

        $match = $this->postJson('/api/miniapp/matches', ['visibility' => 'public'], $this->playerHeaders($alice))->json('match');
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/join", [], $this->playerHeaders($bob));
        $this->dice->queue(6, 6, 1, 1);
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($alice));
        $this->postJson("/api/miniapp/matches/{$match['uuid']}/roll", [], $this->playerHeaders($bob));

        $this->postJson('/api/miniapp/withdrawals', [
            'full_name' => 'Bob Stone', 'address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 'amount' => '10', 'network' => 'TRC20', 'confirm' => true,
        ], $this->playerHeaders($bob))->assertCreated();

        $custom = Mission::factory()->create(['type' => 'custom', 'verification' => 'admin_review', 'target' => null]);
        $this->postJson("/api/miniapp/missions/{$custom->id}/claim", ['proof' => 'done it'], $this->playerHeaders($bob))->assertOk();

        FraudFlag::query()->create(['user_id' => $bob->id, 'type' => 'manual', 'severity' => 'medium', 'status' => 'open']);
        ReferralReward::query()->create([
            'beneficiary_id' => $alice->id, 'source_user_id' => $bob->id, 'level' => 1, 'event' => 'qualification',
            'amount' => '0.10', 'status' => 'skipped', 'idempotency_key' => 'seed-ref',
        ]);

        return [$alice, $bob];
    }

    public function test_login_requires_valid_credentials_and_active_account(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $this->post('/admin/login', ['email' => $this->super->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest('admin');

        $inactive = Admin::factory()->create(['password' => 'correct-horse-123', 'is_active' => false]);
        $this->post('/admin/login', ['email' => $inactive->email, 'password' => 'correct-horse-123'])->assertSessionHasErrors('email');

        $this->post('/admin/login', ['email' => $this->super->email, 'password' => 'correct-horse-123'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($this->super, 'admin');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login', 'admin_id' => $this->super->id]);
    }

    public function test_every_admin_page_renders_with_real_data(): void
    {
        [$alice, $bob] = $this->seedActivity();
        $withdrawal = Withdrawal::query()->firstOrFail();
        $match = GameMatch::query()->firstOrFail();
        $mission = Mission::query()->firstOrFail();

        $pages = [
            '/admin', '/admin/account', '/admin/users', '/admin/users?q='.$bob->publicId(), '/admin/users/'.$bob->id,
            '/admin/withdrawals', '/admin/withdrawals?status=pending&q=WD', '/admin/withdrawals/'.$withdrawal->reference,
            '/admin/missions', '/admin/missions/create', '/admin/missions/'.$mission->id.'/edit', '/admin/missions/reviews',
            '/admin/games/rounds', '/admin/games/matches', '/admin/games/matches/'.$match->id,
            '/admin/referrals', '/admin/ledger', '/admin/ledger?user='.$bob->id, '/admin/budget', '/admin/fraud',
            '/admin/telegram', '/admin/admins', '/admin/admins/create', '/admin/admins/'.$this->super->id.'/edit', '/admin/audit',
        ];
        foreach (array_keys(app(Settings::class)->schema()) as $group) {
            $pages[] = '/admin/settings/'.$group;
        }

        foreach ($pages as $page) {
            $this->actingAs($this->super, 'admin')->get($page)->assertOk();
        }

        $this->actingAs($this->super, 'admin')->get('/admin/users/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($this->super, 'admin')->get('/admin/withdrawals/export')->assertOk();
        $this->actingAs($this->super, 'admin')->get('/admin/ledger/export')->assertOk();
    }

    public function test_roles_restrict_sensitive_actions(): void
    {
        [, $bob] = $this->seedActivity();
        $withdrawal = Withdrawal::query()->firstOrFail();

        $analyst = Admin::factory()->role(AdminRole::Analyst)->create(['password' => 'correct-horse-123']);
        $this->actingAs($analyst, 'admin')->get('/admin/withdrawals')->assertOk();
        $this->actingAs($analyst, 'admin')->post("/admin/withdrawals/{$withdrawal->reference}/approve", ['confirm_password' => 'correct-horse-123'])->assertForbidden();
        $this->actingAs($analyst, 'admin')->post("/admin/users/{$bob->id}/adjust", [])->assertForbidden();
        $this->actingAs($analyst, 'admin')->get('/admin/settings/general')->assertForbidden();
        $this->actingAs($analyst, 'admin')->get('/admin/admins')->assertForbidden();

        $support = Admin::factory()->role(AdminRole::Support)->create();
        $this->actingAs($support, 'admin')->put("/admin/users/{$bob->id}/note", ['admin_note' => 'Checked ID'])->assertRedirect();
        $this->actingAs($support, 'admin')->post('/admin/budget', [])->assertForbidden();

        // Admins (not super) cannot change sensitive settings groups.
        $admin = Admin::factory()->role(AdminRole::Admin)->create(['password' => 'correct-horse-123']);
        $this->actingAs($admin, 'admin')->put('/admin/settings/withdrawals', ['confirm_password' => 'correct-horse-123'])->assertForbidden();
    }

    public function test_withdrawal_workflow_requires_password_confirmation(): void
    {
        $this->seedActivity();
        $withdrawal = Withdrawal::query()->firstOrFail();
        $finance = Admin::factory()->role(AdminRole::Finance)->create(['password' => 'correct-horse-123']);

        $this->actingAs($finance, 'admin')->post("/admin/withdrawals/{$withdrawal->reference}/approve", ['confirm_password' => 'nope'])
            ->assertSessionHasErrors('confirm_password');
        $this->assertSame('pending', $withdrawal->fresh()->status->value);

        $this->actingAs($finance, 'admin')->post("/admin/withdrawals/{$withdrawal->reference}/approve", ['confirm_password' => 'correct-horse-123'])->assertRedirect();
        $this->actingAs($finance, 'admin')->post("/admin/withdrawals/{$withdrawal->reference}/processing", ['confirm_password' => 'correct-horse-123'])->assertRedirect();
        $this->actingAs($finance, 'admin')->post("/admin/withdrawals/{$withdrawal->reference}/paid", [
            'confirm_password' => 'correct-horse-123', 'tx_hash' => str_repeat('f0', 32), 'verified' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $withdrawal->refresh();
        $this->assertSame('paid', $withdrawal->status->value);
        $this->assertSame($finance->id, $withdrawal->paid_by);
        $this->assertSame(3, AuditLog::query()->where('subject_type', 'Withdrawal')->where('admin_id', $finance->id)->count());

        // Invalid transition is shown as a flash error, not a crash.
        $this->actingAs($finance, 'admin')->from("/admin/withdrawals/{$withdrawal->reference}")
            ->post("/admin/withdrawals/{$withdrawal->reference}/reject", ['confirm_password' => 'correct-horse-123', 'reason' => 'late'])
            ->assertRedirect("/admin/withdrawals/{$withdrawal->reference}")->assertSessionHas('error');
    }

    public function test_balance_adjustment_is_limited_audited_and_in_the_ledger(): void
    {
        $user = User::factory()->create();
        $this->setSettings(['admin.max_adjustment' => '50']);

        $this->actingAs($this->super, 'admin')->post("/admin/users/{$user->id}/adjust", [
            'direction' => 'credit', 'amount' => '60', 'reason' => 'Goodwill bonus', 'confirm_password' => 'correct-horse-123',
        ])->assertSessionHas('error');

        $this->actingAs($this->super, 'admin')->post("/admin/users/{$user->id}/adjust", [
            'direction' => 'credit', 'amount' => '12.5', 'reason' => 'Goodwill bonus', 'confirm_password' => 'correct-horse-123',
        ])->assertSessionHas('success');

        $this->assertMoney('12.5', $user->wallet->fresh()->available);
        $this->assertDatabaseHas('ledger_entries', ['user_id' => $user->id, 'type' => 'admin_credit', 'admin_id' => $this->super->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallet.adjusted', 'user_id' => $user->id]);

        $this->actingAs($this->super, 'admin')->post("/admin/users/{$user->id}/adjust", [
            'direction' => 'debit', 'amount' => '20', 'reason' => 'Correction', 'confirm_password' => 'correct-horse-123',
        ])->assertSessionHas('error');
        $this->assertMoney('12.5', $user->wallet->fresh()->available);
    }

    public function test_settings_are_validated_and_audited(): void
    {
        $this->actingAs($this->super, 'admin')->put('/admin/settings/game', [
            'confirm_password' => 'correct-horse-123',
            'settings' => ['game__single__reward' => 'abc', 'game__single__cooldown_seconds' => 5, 'game__single__daily_limit' => 10, 'game__single__min_account_age_minutes' => 0],
        ])->assertSessionHasErrors('game__single__reward');

        $this->actingAs($this->super, 'admin')->put('/admin/settings/game', [
            'confirm_password' => 'correct-horse-123',
            'settings' => ['game__single__enabled' => '1', 'game__single__reward' => '0.25', 'game__single__cooldown_seconds' => 5, 'game__single__daily_limit' => 10, 'game__single__min_account_age_minutes' => 0, 'game__single__rules_text' => 'Doubles win.'],
        ])->assertSessionHas('success');

        $this->assertSame('0.25', app(Settings::class)->get('game.single.reward'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        $this->actingAs($this->super, 'admin')->put('/admin/settings/withdrawals', [
            'confirm_password' => 'correct-horse-123',
            'settings' => ['withdraw__networks' => '{not json'],
        ])->assertSessionHasErrors('withdraw__networks');
    }

    public function test_mission_review_and_crud(): void
    {
        [, $bob] = $this->seedActivity();
        $completion = MissionCompletion::query()->where('status', 'pending_review')->firstOrFail();

        $this->actingAs($this->super, 'admin')->post("/admin/missions/reviews/{$completion->id}/approve")->assertSessionHas('success');
        $this->assertSame('rewarded', $completion->fresh()->status->value);

        $this->actingAs($this->super, 'admin')->post('/admin/missions', [
            'title' => 'Follow us', 'type' => 'instagram_follow', 'verification' => 'telegram_api', 'target' => 'brand',
            'reward' => '0.1', 'repeat' => 'once', 'status' => 'active',
        ])->assertSessionHasErrors('verification');

        $this->actingAs($this->super, 'admin')->post('/admin/missions', [
            'title' => 'Follow us', 'type' => 'instagram_follow', 'verification' => 'admin_review', 'target' => 'brand',
            'reward' => '0.1', 'repeat' => 'once', 'status' => 'active',
        ])->assertRedirect('/admin/missions');

        $mission = Mission::query()->where('title', 'Follow us')->firstOrFail();
        $this->actingAs($this->super, 'admin')->post("/admin/missions/{$mission->id}/duplicate")->assertRedirect();
        $this->assertSame('paused', Mission::query()->where('title', 'Follow us (copy)')->value('status'));
        $this->actingAs($this->super, 'admin')->delete("/admin/missions/{$mission->id}")->assertRedirect('/admin/missions');
        $this->assertSoftDeleted($mission);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $this->actingAs($this->super, 'admin')->put("/admin/admins/{$this->super->id}", [
            'name' => 'Me', 'role' => 'analyst', 'is_active' => '1', 'confirm_password' => 'correct-horse-123',
        ])->assertSessionHas('error');
        $this->assertSame(AdminRole::SuperAdmin, $this->super->fresh()->role);
    }

    public function test_admin_pages_send_security_headers(): void
    {
        $this->get('/admin/login')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/app')->assertOk()->assertHeaderMissing('X-Frame-Options');
    }
}
