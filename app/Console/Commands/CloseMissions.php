<?php

namespace App\Console\Commands;

use App\Services\MissionService;
use Illuminate\Console\Command;

class CloseMissions extends Command
{
    protected $signature = 'missions:close';

    protected $description = 'Mark missions past their end date or out of budget as completed';

    public function handle(MissionService $missions): int
    {
        $this->info('Closed missions: '.$missions->closeFinished());

        return self::SUCCESS;
    }
}
