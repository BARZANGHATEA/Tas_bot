<?php

namespace App\Console\Commands;

use App\Models\AppSession;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;

/** Removes operational data only. Financial and audit records are never pruned. */
class PruneRecords extends Command
{
    protected $signature = 'maintenance:prune {--days=30}';

    protected $description = 'Prune expired sessions, processed webhook ids and delivered notifications';

    public function handle(): int
    {
        $before = now()->subDays(max(7, (int) $this->option('days')));

        $sessions = AppSession::query()->where('expires_at', '<', now())->delete();
        $updates = TelegramUpdate::query()->where('created_at', '<', $before)->delete();
        $messages = TelegramMessage::query()->whereIn('status', ['sent', 'failed'])->where('created_at', '<', $before)->delete();

        $this->info("Pruned {$sessions} sessions, {$updates} webhook ids, {$messages} notifications.");

        return self::SUCCESS;
    }
}
