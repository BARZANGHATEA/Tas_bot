<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\AdminPermission;
use App\Http\Middleware\EnsureMiniAppAvailable;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'admin.can' => AdminPermission::class,
            'miniapp.available' => EnsureMiniAppAvailable::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => route('admin.login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('admin.dashboard'));
        // Shared hosting usually sits behind one reverse proxy / load balancer.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(BusinessRuleException::class);

        // Admin pages: show rule violations as a flash message instead of JSON.
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            if ($request->is('admin', 'admin/*') && ! $request->expectsJson()) {
                return back()->withInput($request->except(['password', 'confirm_password']))->with('error', $e->getMessage());
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
