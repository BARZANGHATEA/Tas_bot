<?php

namespace App\Console\Commands;

use App\Services\MatchService;
use Illuminate\Console\Command;

class ExpireMatches extends Command
{
    protected $signature = 'matches:expire';

    protected $description = 'Cancel two-player matches whose inactivity deadline has passed';

    public function handle(MatchService $matches): int
    {
        $this->info('Expired matches: '.$matches->expireStale());

        return self::SUCCESS;
    }
}
