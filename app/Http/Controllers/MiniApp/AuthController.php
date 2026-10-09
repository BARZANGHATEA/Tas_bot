<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Services\MiniAppAuth;
use App\Support\Ip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly MiniAppAuth $auth) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'init_data' => ['required_without:dev_user', 'nullable', 'string', 'max:8192'],
            'dev_user' => ['sometimes', 'array'],
        ]);

        $ipHash = Ip::hash($request->ip());

        if ($request->filled('dev_user') && $this->devLoginAllowed()) {
            // Local development only (APP_ENV=local + TELEGRAM_DEV_AUTH=true).
            $dev = $request->validate([
                'dev_user.id' => ['required', 'integer', 'min:1'],
                'dev_user.first_name' => ['required', 'string', 'max:64'],
                'dev_user.username' => ['nullable', 'string', 'max:32'],
                'start_param' => ['nullable', 'string', 'max:64'],
            ]);
            $result = $this->auth->loginTelegramUser($dev['dev_user'], $dev['start_param'] ?? null, $ipHash, $request->userAgent());
        } else {
            $result = $this->auth->login((string) $request->input('init_data'), $ipHash, $request->userAgent());
        }

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expires_at']->toIso8601String(),
            'start_param' => $result['start_param'],
            'new_user' => $result['created'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout((string) $request->bearerToken());

        return response()->json(['ok' => true]);
    }

    private function devLoginAllowed(): bool
    {
        return app()->environment('local') && config('dicegame.telegram.dev_auth') === true;
    }
}
