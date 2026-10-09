<?php

namespace App\Services\Telegram;

use App\Services\Settings;
use RuntimeException;

/** One-click bot configuration: webhook, commands and the Mini App menu button. */
class TelegramSetupService
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly Settings $settings,
        private readonly Links $links,
    ) {}

    public function webhookUrl(): string
    {
        return route('telegram.webhook');
    }

    /** @return array<string, mixed> */
    public function configure(): array
    {
        $secret = (string) config('dicegame.telegram.webhook_secret');
        if (strlen($secret) < 16 || ! preg_match('/^[A-Za-z0-9_-]+$/', $secret)) {
            throw new TelegramApiException('Set TELEGRAM_WEBHOOK_SECRET in .env (16-256 characters: A-Z, a-z, 0-9, _ and -).');
        }

        $url = $this->webhookUrl();
        if (! str_starts_with($url, 'https://')) {
            throw new TelegramApiException("Telegram requires HTTPS. Set APP_URL to your https:// address (current webhook URL: {$url}).");
        }

        return [
            'setWebhook' => $this->client->call('setWebhook', [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['message'],
                'drop_pending_updates' => false,
                'max_connections' => 20,
            ]),
            'setMyCommands' => $this->client->call('setMyCommands', [
                'commands' => [
                    ['command' => 'start', 'description' => 'Start / open the main menu'],
                    ['command' => 'menu', 'description' => 'Open the app'],
                    ['command' => 'help', 'description' => 'How it works'],
                ],
            ]),
            'setChatMenuButton' => $this->client->call('setChatMenuButton', [
                'menu_button' => [
                    'type' => 'web_app',
                    'text' => mb_substr($this->settings->string('nav.games', 'Play'), 0, 16) ?: 'Play',
                    'web_app' => ['url' => $this->links->miniAppUrl()],
                ],
            ]),
        ];
    }

    public function webhookInfo(): array
    {
        $info = (array) $this->client->call('getWebhookInfo');
        $info['expected_url'] = $this->webhookUrl();

        return $info;
    }

    public function botInfo(): array
    {
        return (array) $this->client->call('getMe');
    }
}
