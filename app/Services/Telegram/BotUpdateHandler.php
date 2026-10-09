<?php

namespace App\Services\Telegram;

use App\Enums\WithdrawalStatus;
use App\Models\Admin;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\BudgetService;
use App\Services\PlayerService;
use App\Services\Settings;
use App\Support\Money;

/**
 * Handles webhook updates. The controller has already authenticated the
 * request (secret token) and de-duplicated the update_id.
 */
class BotUpdateHandler
{
    public function __construct(
        private readonly PlayerService $players,
        private readonly NotificationService $notifications,
        private readonly Links $links,
        private readonly Settings $settings,
        private readonly BudgetService $budget,
    ) {}

    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ! isset($message['from']['id'], $message['chat']['id'])) {
            return;
        }

        // Only private chats with real users; ignore bots, groups and channels.
        if (($message['chat']['type'] ?? '') !== 'private' || ($message['from']['is_bot'] ?? false)) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        if (! str_starts_with($text, '/')) {
            $this->sendMenu($message);

            return;
        }

        [$command, $argument] = array_pad(preg_split('/\s+/', $text, 2), 2, null);
        $command = strtolower(explode('@', $command)[0]);

        match ($command) {
            '/start' => $this->start($message, $argument),
            '/menu', '/app' => $this->sendMenu($message),
            '/help' => $this->help($message),
            '/stats', '/pending', '/budget' => $this->adminCommand($message, $command),
            default => $this->sendMenu($message),
        };
    }

    private function start(array $message, ?string $payload): void
    {
        $payload = $payload !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $payload) ? $payload : null;

        [$user] = $this->players->findOrRegister($message['from'], $payload, null, 'bot');

        if ($user->isSuspended()) {
            return;
        }

        $this->notifications->toUser($user, 'tpl.welcome', ['name' => $user->first_name], $this->links->mainMenuKeyboard($user));

        if ($payload !== null && str_starts_with($payload, 'm_')) {
            $code = substr($payload, 2);
            $match = \App\Models\GameMatch::query()->where('invite_code', $code)->first();
            if ($match) {
                $this->notifications->raw((string) $user->telegram_id, '🎲 You have been invited to a dice match.',
                    $this->links->openAppKeyboard(['match' => $match->uuid, 'code' => $code], '⚔️ Open match'));
            }
        }
    }

    private function sendMenu(array $message): void
    {
        $user = User::query()->where('telegram_id', (int) $message['from']['id'])->first();
        if ($user?->isSuspended()) {
            return;
        }
        if (! $user) {
            $this->start($message, null);

            return;
        }

        $this->notifications->raw((string) $message['chat']['id'], 'Choose where to go 👇', $this->links->mainMenuKeyboard($user));
    }

    private function help(array $message): void
    {
        $support = $this->settings->string('app.support_url');
        $text = "<b>{$this->escape($this->settings->string('app.name'))}</b>\n\n"
            ."🎲 Roll two dice – doubles win USDT rewards.\n"
            ."⚔️ Challenge friends in free two-player matches.\n"
            ."🎯 Complete missions for bonuses.\n"
            ."👥 Invite friends and earn when they qualify.\n\n"
            .'Use /menu to open the app.'.($support ? "\nSupport: ".$this->escape($support) : '');

        $this->notifications->raw((string) $message['chat']['id'], $text);
    }

    private function adminCommand(array $message, string $command): void
    {
        $admin = Admin::query()
            ->where('telegram_id', (int) $message['from']['id'])
            ->where('is_active', true)
            ->first();

        // Non-admins get the normal menu: the commands' existence is not revealed.
        if (! $admin || ! $admin->hasPermission('dashboard.view')) {
            $this->sendMenu($message);

            return;
        }

        $chatId = (string) $message['chat']['id'];

        $text = match ($command) {
            '/stats' => sprintf(
                "📊 <b>Stats</b>\nUsers: %d (today %d)\nRounds today: %d\nPending withdrawals: %d",
                User::query()->count(),
                User::query()->where('created_at', '>=', $this->settings->startOfToday())->count(),
                \App\Models\GameRound::query()->where('created_at', '>=', $this->settings->startOfToday())->count(),
                Withdrawal::query()->where('status', WithdrawalStatus::Pending)->count(),
            ),
            '/pending' => $this->pendingWithdrawalsText(),
            '/budget' => sprintf(
                "💰 <b>Reward budget</b>\nAvailable: %s USDT\nIssued today: %s USDT",
                Money::format($this->budget->available()),
                Money::format($this->budget->issuedToday()),
            ),
        };

        $this->notifications->raw($chatId, $text, ['inline_keyboard' => [[['text' => 'Open dashboard', 'url' => url('/admin')]]]]);
    }

    private function pendingWithdrawalsText(): string
    {
        $pending = Withdrawal::query()->with('user')->where('status', WithdrawalStatus::Pending)->oldest()->limit(10)->get();
        if ($pending->isEmpty()) {
            return '✅ No withdrawals waiting for review.';
        }

        $lines = $pending->map(fn (Withdrawal $w) => sprintf('• %s – %s USDT (%s) – %s',
            $w->reference, Money::format($w->amount), $w->network, $this->escape($w->user->publicId())));

        return "🕒 <b>Pending withdrawals</b>\n".$lines->implode("\n");
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
