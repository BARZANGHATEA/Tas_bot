<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InstallAndSystemTest extends TestCase
{
    public function test_requests_are_sent_to_the_installer_until_installed(): void
    {
        config(['dicegame.require_install' => true]);
        $lock = storage_path('installed.lock');
        $hadLock = is_file($lock);
        if ($hadLock) {
            rename($lock, $lock.'.bak');
        }

        try {
            $this->get('/admin/login')->assertRedirect('/install.php');
            $this->get('/app')->assertRedirect('/install.php');
            $this->postJson('/api/telegram/webhook', [])->assertStatus(503)->assertJsonPath('code', 'not_installed');

            file_put_contents($lock, '{}');
            $this->get('/admin/login')->assertOk();
        } finally {
            @unlink($lock);
            if ($hadLock) {
                rename($lock.'.bak', $lock);
            }
        }
    }

    public function test_install_command_creates_admin_and_lock(): void
    {
        $lock = storage_path('installed.lock');
        $backup = is_file($lock) ? file_get_contents($lock) : null;
        @unlink($lock);

        try {
            $this->artisan('app:install', ['--name' => 'Owner', '--email' => 'owner@example.com', '--password' => 'Correct-horse-123'])->assertSuccessful();
            $this->assertDatabaseHas('admins', ['email' => 'owner@example.com', 'role' => 'super_admin']);
            $this->assertFileExists($lock);
        } finally {
            $backup === null ? @unlink($lock) : file_put_contents($lock, $backup);
        }
    }

    public function test_system_page_runs_maintenance_tasks_for_super_admins_only(): void
    {
        $super = Admin::factory()->create(['password' => 'correct-horse-123']);
        Cache::forever('scheduler:last_run', now()->toIso8601String());

        $this->actingAs($super, 'admin')->get('/admin/system')->assertOk()->assertSee('schedule:run');

        $this->actingAs($super, 'admin')->post('/admin/system/run', ['action' => 'reconcile', 'confirm_password' => 'wrong'])
            ->assertSessionHasErrors('confirm_password');
        $this->actingAs($super, 'admin')->post('/admin/system/run', ['action' => 'rm -rf', 'confirm_password' => 'correct-horse-123'])
            ->assertSessionHasErrors('action');

        $this->actingAs($super, 'admin')->post('/admin/system/run', ['action' => 'reconcile', 'confirm_password' => 'correct-horse-123'])
            ->assertSessionHas('success')->assertSessionHas('command_output');
        $this->actingAs($super, 'admin')->post('/admin/system/run', ['action' => 'migrate', 'confirm_password' => 'correct-horse-123'])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.command']);

        $admin = Admin::factory()->role(AdminRole::Admin)->create();
        $this->actingAs($admin, 'admin')->get('/admin/system')->assertForbidden();
    }
}
