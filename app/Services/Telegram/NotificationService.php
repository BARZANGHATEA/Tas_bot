<?php

namespace App\Services\Telegram;

use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Telegram notifications go through a database outbox. Business code only
 * inserts a row (inside its own transaction if needed); delivery happens after
 * the HTTP response is sent and from the scheduler, with retries. A slow or
 * failing Telegram API therefore never breaks a game, payout or withdrawal.
 */
class NotificationService
{
    private const MAX_ATTEMPTS = 5;

    private bool $queuedThisRequest = false;

    public function __construct(
        private readonly Settings $settings,
        private readonly TelegramClient $client,
    ) {}

    public function render(string $templateKey, array $vars = []): string
    {
        $template = $this->settings->string($templateKey);
        $vars += ['app' => $this->settings->string('app.name')];

        $replacements = [];
        foreach ($vars as $name => $value) {
            $replacements['{'.$name.'}'] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return strtr($template, $replacements);
    }

    public function toUser(User $user, string $templateKey, array $vars = [], ?array $replyMarkup = null, ?string $dedupeKey = null): ?TelegramMessage
    {
        if ($user->isSuspended()) {
            return null;
        }

        return $this->queue((string) $user->telegram_id, $this->render($templateKey, $vars), $replyMarkup, $dedupeKey);
    }

    public function toChat(string $chatId, string $templateKey, array $vars = [], ?array $replyMarkup = null, ?string $dedupeKey = null): ?TelegramMessage
    {
        if (trim($chatId) === '') {
            return null;
        }

        return $this->queue(trim($chatId), $this->render($templateKey, $vars), $replyMarkup, $dedupeKey);
    }

    public function raw(string $chatId, string $html, ?array $replyMarkup = null): ?TelegramMessage
    {
        return $this->queue($chatId, $html, $replyMarkup, null);
    }

    public function queue(string $chatId, string $text, ?array $replyMarkup = null, ?string $dedupeKey = null): ?TelegramMessage
    {
        try {
            $message = TelegramMessage::query()->create([
                'chat_id' => $chatId,
                'text' => mb_substr($text, 0, 4096),
                'reply_markup' => $replyMarkup,
                'status' => 'pending',
                'dedupe_key' => $dedupeKey,
                'send_after' => now(),
            ]);
        } catch (QueryException $e) {
            // Same dedupe key already queued: this notification was already sent once.
            if ($dedupeKey !== null && TelegramMessage::query()->where('dedupe_key', $dedupeKey)->exists()) {
                return null;
            }
            throw $e;
        }

        $this->queuedThisRequest = true;

        return $message;
    }

    public function hasQueuedThisRequest(): bool
    {
        return $this->queuedThisRequest;
    }

    /** Deliver due messages. Safe to call concurrently (cache lock). */
    public function flush(int $limit = 20): int
    {
        if (! $this->client->isConfigured()) {
            return 0;
        }

        $lock = Cache::lock('telegram-outbox-flush', 55);
        if (! $lock->get()) {
            return 0;
        }

        $sent = 0;
        try {
            $messages = TelegramMessage::query()
                ->where('status', 'pending')
                ->where(fn ($q) => $q->whereNull('send_after')->orWhere('send_after', '<=', now()))
                ->orderBy('id')
                ->limit($limit)
                ->get();

            foreach ($messages as $message) {
                try {
                    $this->client->sendMessage($message->chat_id, $message->text, $message->reply_markup);
                    $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'attempts' => $message->attempts + 1, 'last_error' => null])->save();
                    $sent++;
                } catch (TelegramApiException $e) {
                    $attempts = $message->attempts + 1;
                    // 403 = user blocked the bot, 400 = bad chat: retrying will not help.
                    $permanent = in_array($e->getCode(), [400, 403], true);
                    $message->forceFill([
                        'attempts' => $attempts,
                        'last_error' => mb_substr($e->getMessage(), 0, 250),
                        'status' => ($permanent || $attempts >= self::MAX_ATTEMPTS) ? 'failed' : 'pending',
                        'send_after' => now()->addSeconds(30 * (2 ** $attempts)),
                    ])->save();
                    Log::warning('Telegram delivery failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
                }
            }
        } finally {
            $lock->release();
        }

        return $sent;
    }
}
