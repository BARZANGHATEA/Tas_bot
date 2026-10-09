<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin Bot API client over Laravel's HTTP client (plain HTTPS, no long-running process).
 */
class TelegramClient
{
    public function isConfigured(): bool
    {
        return (string) config('dicegame.telegram.bot_token') !== '';
    }

    /**
     * @throws TelegramApiException
     */
    public function call(string $method, array $params = []): mixed
    {
        $token = (string) config('dicegame.telegram.bot_token');
        if ($token === '') {
            throw new TelegramApiException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $url = rtrim((string) config('dicegame.telegram.api_base'), '/')."/bot{$token}/{$method}";

        try {
            $response = Http::timeout((int) config('dicegame.telegram.timeout', 8))
                ->connectTimeout(5)
                ->asJson()
                ->acceptJson()
                ->post($url, $params);
        } catch (\Throwable $e) {
            // Never leak the token (it is part of the URL) into logs or messages.
            throw new TelegramApiException('Telegram API unreachable: '.str_replace($token, '***', $e->getMessage()));
        }

        $body = $response->json();

        if (! is_array($body) || ! ($body['ok'] ?? false)) {
            $description = is_array($body) ? (string) ($body['description'] ?? 'unknown error') : 'invalid response';
            throw new TelegramApiException("Telegram {$method} failed: {$description}", (int) ($body['error_code'] ?? $response->status()));
        }

        return $body['result'] ?? null;
    }

    public function sendMessage(string|int $chatId, string $text, ?array $replyMarkup = null): mixed
    {
        return $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $replyMarkup,
        ], fn ($v) => $v !== null));
    }

    /**
     * Returns true when the user is a current member of the chat.
     *
     * @throws TelegramApiException when the bot cannot see the chat (not an admin, wrong id, ...)
     */
    public function isChatMember(string|int $chatId, int $telegramUserId): bool
    {
        $member = $this->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $telegramUserId]);
        $status = $member['status'] ?? 'left';

        return in_array($status, ['creator', 'administrator', 'member'], true)
            || ($status === 'restricted' && ($member['is_member'] ?? false));
    }
}
