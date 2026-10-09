<?php

namespace App\Http\Controllers\Admin;

use App\Models\TelegramMessage;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Maintenance without a terminal: everything an operator would otherwise do
 * with `php artisan ...` on the server, behind the super-administrator role
 * and password re-confirmation.
 */
class SystemController extends AdminController
{
    /** Commands that may be run from the dashboard. */
    private const ACTIONS = [
        'migrate' => ['migrate', ['--force' => true], 'Database updated'],
        'clear-cache' => ['optimize:clear', [], 'Caches cleared'],
        'schedule' => ['schedule:run', [], 'Scheduled tasks executed'],
        'dispatch' => ['telegram:dispatch', [], 'Notification queue processed'],
        'reconcile' => ['wallet:reconcile', [], 'Wallet reconciliation finished'],
        'expire-matches' => ['matches:expire', [], 'Stale matches expired'],
    ];

    public function index(): View
    {
        $lastRun = Cache::get('scheduler:last_run');
        $cronToken = (string) config('dicegame.cron_token');

        return view('admin.system.index', [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database' => $this->databaseVersion(),
            'pending' => $this->pendingMigrations(),
            'lastRun' => $lastRun ? CarbonImmutable::parse($lastRun) : null,
            'outboxPending' => TelegramMessage::query()->where('status', 'pending')->count(),
            'installed' => is_file(storage_path('installed.lock')) ? json_decode((string) file_get_contents(storage_path('installed.lock')), true) : null,
            'cronCommand' => '* * * * * /usr/local/bin/php '.base_path('artisan').' schedule:run >> /dev/null 2>&1',
            'cronUrl' => strlen($cronToken) >= 24 ? url('/cron/run/'.$cronToken) : null,
            'fallback' => (bool) config('dicegame.scheduler_fallback'),
            'debug' => (bool) config('app.debug'),
            'environment' => app()->environment(),
            'installerPresent' => is_file(public_path('install.php')),
            'output' => session('command_output'),
        ]);
    }

    public function run(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate($this->confirmRules() + ['action' => ['required', 'in:'.implode(',', array_keys(self::ACTIONS))]]);
        [$command, $arguments, $message] = self::ACTIONS[$data['action']];

        @set_time_limit(300);

        try {
            $code = Artisan::call($command, $arguments);
            $output = trim(Artisan::output());
        } catch (Throwable $e) {
            $code = 1;
            $output = $e->getMessage();
        }

        $audit->log('system.command', null, ['command' => $command, 'exit_code' => $code]);

        return back()
            ->with($code === 0 ? 'success' : 'error', $code === 0 ? $message.'.' : "The command \"{$command}\" reported a problem.")
            ->with('command_output', $output ?: '(no output)');
    }

    /** @return list<string> */
    private function pendingMigrations(): array
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return ['(migration table missing)'];
            }
            $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));

            return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable $e) {
            return ['(could not check: '.$e->getMessage().')'];
        }
    }

    private function databaseVersion(): string
    {
        try {
            return DB::connection()->getDriverName().' '.DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
