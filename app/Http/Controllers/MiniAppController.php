<?php

namespace App\Http\Controllers;

use App\Services\Settings;
use App\Services\Telegram\Links;
use Illuminate\Contracts\View\View;

class MiniAppController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Links $links,
    ) {}

    /** The Mini App shell. All data is loaded from the authenticated JSON API. */
    public function show(): View
    {
        return view('miniapp', [
            'settings' => $this->settings,
            'config' => [
                'app_name' => $this->settings->string('app.name'),
                'tagline' => $this->settings->string('app.tagline'),
                'support_url' => $this->settings->string('app.support_url'),
                'api' => url('/api/miniapp'),
                'bot_url' => $this->links->botUsername() ? 'https://t.me/'.$this->links->botUsername() : null,
                'nav' => [
                    'home' => $this->settings->string('nav.home'),
                    'games' => $this->settings->string('nav.games'),
                    'missions' => $this->settings->string('nav.missions'),
                    'withdraw' => $this->settings->string('nav.withdraw'),
                ],
                'use_telegram_theme' => $this->settings->bool('brand.use_telegram_theme'),
                'disclaimer' => $this->settings->string('legal.disclaimer'),
                'terms_url' => route('legal', 'terms'),
                'privacy_url' => route('legal', 'privacy'),
                'dev_auth' => app()->environment('local') && config('dicegame.telegram.dev_auth') === true,
            ],
        ]);
    }

    public function landing(): View
    {
        return view('landing', [
            'settings' => $this->settings,
            'botUrl' => $this->links->botUsername() ? 'https://t.me/'.$this->links->botUsername() : null,
        ]);
    }

    public function legal(string $page): View
    {
        return view('legal', [
            'settings' => $this->settings,
            'title' => $page === 'terms' ? 'Terms of use' : 'Privacy policy',
            'body' => $this->settings->string('legal.'.$page),
        ]);
    }
}
