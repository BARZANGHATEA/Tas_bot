<?php

namespace App\Console\Commands;

use App\Services\Telegram\NotificationService;
use Illuminate\Console\Command;

class DispatchTelegramMessages extends Command
{
    protected $signature = 'telegram:dispatch {--limit=60 : Maximum messages to send in this run}';

    protected $description = 'Deliver queued Telegram notifications (outbox)';

    public function handle(NotificationService $notifications): int
    {
        $this->info('Sent: '.$notifications->flush((int) $this->option('limit')));

        return self::SUCCESS;
    }
}
