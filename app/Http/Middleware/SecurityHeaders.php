<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($request->is('app')) {
            // The Mini App is embedded by Telegram's web clients in an iframe.
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' https://telegram.org",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "connect-src 'self'",
                "font-src 'self'",
                "frame-ancestors 'self' https://web.telegram.org https://*.telegram.org",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        } elseif (! $request->is('api/*')) {
            $headers->set('X-Frame-Options', 'DENY');
            $headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        }

        return $response;
    }
}
