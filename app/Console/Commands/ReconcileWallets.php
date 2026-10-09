<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FraudService;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileWallets extends Command
{
    protected $signature = 'wallet:reconcile';

    protected $description = 'Verify every wallet balance against the immutable ledger';

    public function handle(WalletService $wallet, FraudService $fraud, AuditLogger $audit): int
    {
        $problems = $wallet->reconcile();

        if ($problems === []) {
            $this->info('All wallets reconcile with the ledger.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error(sprintf('User %d %s: wallet %s ≠ ledger %s', $problem['user_id'], $problem['field'], $problem['wallet'], $problem['ledger']));
            if ($user = User::query()->find($problem['user_id'])) {
                $fraud->flag($user, 'ledger_mismatch', 'high', $problem);
            }
        }

        Log::critical('Wallet reconciliation found discrepancies', ['count' => count($problems)]);
        $audit->log('wallet.reconcile_failed', null, ['problems' => array_slice($problems, 0, 50)]);

        return self::FAILURE;
    }
}
