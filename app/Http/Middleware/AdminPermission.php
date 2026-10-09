<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $admin = $request->user('admin');

        foreach ($permissions as $permission) {
            if (! $admin || ! $admin->hasPermission($permission)) {
                abort(403, 'Your role does not allow this action.');
            }
        }

        return $next($request);
    }
}
