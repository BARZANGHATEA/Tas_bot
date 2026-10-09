<?php

namespace App\Http\Middleware;

use App\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Global maintenance switch for players (the admin dashboard stays available). */
class EnsureMiniAppAvailable
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->settings->bool('app.maintenance')) {
            return response()->json([
                'message' => $this->settings->string('app.maintenance_message', 'Maintenance in progress.'),
                'code' => 'maintenance',
            ], 503);
        }

        return $next($request);
    }
}
