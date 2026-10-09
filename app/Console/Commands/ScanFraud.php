<?php

namespace App\Console\Commands;

use App\Services\FraudService;
use Illuminate\Console\Command;

class ScanFraud extends Command
{
    protected $signature = 'fraud:scan';

    protected $description = 'Look for suspicious referral clusters and raise review flags';

    public function handle(FraudService $fraud): int
    {
        $this->info('Flags raised: '.$fraud->scan());

        return self::SUCCESS;
    }
}
