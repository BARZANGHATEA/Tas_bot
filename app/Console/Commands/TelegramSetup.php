<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramSetupService;
use Illuminate\Console\Command;

class TelegramSetup extends Command
{
    protected $signature = 'telegram:setup {--info : Only show the current webhook status}';

    protected $description = 'Register the webhook (with secret token), bot commands and the Mini App menu button';

    public function handle(TelegramSetupService $setup): int
    {
        try {
            if (! $this->option('info')) {
                foreach ($setup->configure() as $step => $result) {
                    $this->line("✔ {$step}");
                }
            }

            $info = $setup->webhookInfo();
            $this->table(['Field', 'Value'], collect($info)->map(fn ($v, $k) => [$k, is_scalar($v) ? (string) $v : json_encode($v)])->values()->all());
        } catch (TelegramApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
