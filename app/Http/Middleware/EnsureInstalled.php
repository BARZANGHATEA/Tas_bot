<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until the web installer (public/install.php) or `php artisan app:install`
 * has run, every request is sent to the installer instead of failing on a
 * missing APP_KEY or database.
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('dicegame.require_install') || is_file(storage_path('installed.lock'))) {
            return $next($request);
        }

        if ($request->is('api/*', 'up')) {
            return response()->json(['message' => 'The application is not installed yet.', 'code' => 'not_installed'], 503);
        }

        return redirect()->to(rtrim($request->getBaseUrl(), '/').'/install.php');
    }
}
