<?php

namespace App\Providers;

use App\Contracts\DiceRoller;
use App\Services\MiniAppAuth;
use App\Services\SecureDiceRoller;
use App\Services\Settings;
use App\Services\Telegram\NotificationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(NotificationService::class);
        $this->app->bind(DiceRoller::class, SecureDiceRoller::class);
    }

    public function boot(): void
    {
        // Mini App API: bearer token → server-side session → player.
        Auth::viaRequest('miniapp-token', fn (Request $request) => app(MiniAppAuth::class)->resolve($request->bearerToken()));

        // Behind shared-hosting proxies PHP may see plain HTTP: generate links from APP_URL.
        $appUrl = (string) config('app.url');
        if (str_starts_with($appUrl, 'https://') && ! $this->app->environment('local', 'testing')) {
            URL::forceRootUrl($appUrl);
            URL::forceScheme('https');
        }

        $this->configureRateLimiting();

        Paginator::defaultView('admin.partials.pagination');
        Paginator::defaultSimpleView('admin.partials.pagination');

        Blade::if('adminCan', fn (string $permission) => (bool) Auth::guard('admin')->user()?->hasPermission($permission));

        // Deliver Telegram notifications after the response has been sent.
        $this->app->terminating(function () {
            $notifications = $this->app->make(NotificationService::class);
            if ($notifications->hasQueuedThisRequest()) {
                try {
                    $notifications->flush(10);
                } catch (Throwable $e) {
                    Log::warning('Outbox flush failed: '.$e->getMessage());
                }
            }

            // Hosts without any cron: piggy-back the scheduler on web traffic.
            if (config('dicegame.scheduler_fallback') && ! $this->app->runningInConsole()
                && Cache::add('scheduler-fallback-tick', 1, 60)) {
                try {
                    Artisan::call('schedule:run');
                } catch (Throwable $e) {
                    Log::warning('Fallback scheduler failed: '.$e->getMessage());
                }
            }
        });
    }

    private function configureRateLimiting(): void
    {
        $settings = fn () => $this->app->make(Settings::class);
        $key = fn (Request $request) => $request->user('miniapp')?->id ?: $request->ip();

        RateLimiter::for('miniapp', fn (Request $r) => Limit::perMinute($settings()->int('rate.api_per_minute', 120))->by('api:'.$key($r)));
        RateLimiter::for('game', fn (Request $r) => Limit::perMinute($settings()->int('rate.game_per_minute', 30))->by('game:'.$key($r)));
        RateLimiter::for('mission', fn (Request $r) => Limit::perMinute($settings()->int('rate.mission_per_minute', 10))->by('mission:'.$key($r)));
        RateLimiter::for('withdraw', fn (Request $r) => Limit::perHour($settings()->int('rate.withdraw_per_hour', 5))->by('withdraw:'.$key($r)));
        RateLimiter::for('miniapp-auth', fn (Request $r) => Limit::perMinute($settings()->int('rate.auth_per_minute', 20))->by('auth:'.$r->ip()));
        RateLimiter::for('webhook', fn (Request $r) => Limit::perMinute(600)->by('webhook'));
        RateLimiter::for('admin-login', fn (Request $r) => [
            Limit::perMinute(5)->by('admin-login:'.$r->ip()),
            Limit::perHour(20)->by('admin-login-email:'.strtolower((string) $r->input('email'))),
        ]);
        RateLimiter::for('admin-actions', fn (Request $r) => Limit::perMinute(60)->by('admin:'.($r->user('admin')?->id ?: $r->ip())));
    }
}
