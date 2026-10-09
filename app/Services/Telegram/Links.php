<?php

namespace App\Services\Telegram;

use App\Models\User;
use App\Services\Settings;

/** Builds Telegram deep links and Mini App buttons. */
class Links
{
    public function __construct(private readonly Settings $settings) {}

    public function botUsername(): string
    {
        return (string) config('dicegame.telegram.bot_username');
    }

    public function miniAppUrl(array $query = []): string
    {
        $url = url('/app');

        return $query ? $url.'?'.http_build_query($query) : $url;
    }

    /** Link that opens the bot (or the Mini App directly) with a start parameter. */
    public function deepLink(string $startParam): string
    {
        $bot = $this->botUsername();
        $app = (string) config('dicegame.telegram.mini_app_short_name');

        if ($bot === '') {
            return $this->miniAppUrl(['start' => $startParam]);
        }

        return $app !== ''
            ? "https://t.me/{$bot}/{$app}?startapp=".rawurlencode($startParam)
            : "https://t.me/{$bot}?start=".rawurlencode($startParam);
    }

    public function referralLink(User $user): string
    {
        return $this->deepLink('ref_'.$user->referral_code);
    }

    public function matchLink(string $inviteCode): string
    {
        return $this->deepLink('m_'.$inviteCode);
    }

    public function shareUrl(string $link, string $text): string
    {
        return 'https://t.me/share/url?'.http_build_query(['url' => $link, 'text' => $text]);
    }

    /** Inline keyboard with a single "Open app" Mini App button. */
    public function openAppKeyboard(array $query = [], ?string $label = null): array
    {
        return ['inline_keyboard' => [[[
            'text' => $label ?? $this->settings->string('telegram.open_button', 'Open app'),
            'web_app' => ['url' => $this->miniAppUrl($query)],
        ]]]];
    }

    /**
     * Main menu as inline Mini App buttons. (Reply-keyboard web_app buttons are
     * not used: Telegram launches those without signed init data.)
     */
    public function mainMenuKeyboard(?User $user = null): array
    {
        $labels = [
            'home' => '🏠 '.$this->settings->string('nav.home', 'Home'),
            'games' => '🎲 '.$this->settings->string('nav.games', 'Games'),
            'missions' => '🎯 '.$this->settings->string('nav.missions', 'Missions'),
            'withdraw' => '💸 '.$this->settings->string('nav.withdraw', 'Withdraw'),
        ];
        $button = fn (string $tab) => ['text' => $labels[$tab], 'web_app' => ['url' => $this->miniAppUrl(['tab' => $tab])]];

        $rows = [
            [['text' => $this->settings->string('telegram.open_button', 'Open app'), 'web_app' => ['url' => $this->miniAppUrl()]]],
            [$button('games'), $button('missions')],
            [$button('home'), $button('withdraw')],
        ];

        if ($user) {
            $rows[] = [['text' => '👥 Invite friends', 'url' => $this->shareUrl($this->referralLink($user), 'Roll dice with me and earn USDT rewards!')]];
        }

        return ['inline_keyboard' => $rows];
    }
}
