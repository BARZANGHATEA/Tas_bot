<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AppSession;
use App\Models\User;
use App\Services\Telegram\InitDataValidator;
use Illuminate\Support\Str;

/**
 * Mini App authentication.
 *
 * The client posts Telegram's signed init data; after server-side validation
 * we create a server-side session and hand back a random bearer token (only
 * its SHA-256 hash is stored). Bearer tokens avoid third-party-cookie problems
 * inside Telegram's web clients and are immune to CSRF.
 */
class MiniAppAuth
{
    public function __construct(
        private readonly InitDataValidator $validator,
        private readonly PlayerService $players,
        private readonly Settings $settings,
    ) {}

    /** @return array{user: User, token: string, expires_at: \DateTimeInterface, start_param: ?string, created: bool} */
    public function login(string $initData, ?string $ipHash, ?string $userAgent): array
    {
        $data = $this->validator->validate(
            $initData,
            (string) config('dicegame.telegram.bot_token'),
            $this->settings->int('auth.init_data_max_age', 86400),
        );

        return $this->loginTelegramUser($data['user'], $data['start_param'], $ipHash, $userAgent);
    }

    /** Shared by the real flow and the local-only development login. */
    public function loginTelegramUser(array $tgUser, ?string $startParam, ?string $ipHash, ?string $userAgent): array
    {
        [$user, $created] = $this->players->findOrRegister($tgUser, $startParam, $ipHash, 'miniapp');

        if ($user->isSuspended()) {
            throw new BusinessRuleException('This account is suspended. Please contact support.', 'account_suspended', 403);
        }

        $token = Str::random(64);
        $session = AppSession::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(max(1, (int) config('dicegame.session_ttl_hours', 12))),
            'last_used_at' => now(),
            'ip_hash' => $ipHash,
            'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
        ]);

        // Housekeeping: keep a handful of sessions per user.
        AppSession::query()->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('expires_at', '<', now())->orWhereNotIn('id',
                AppSession::query()->where('user_id', $user->id)->latest('id')->limit(5)->pluck('id')))
            ->delete();

        return [
            'user' => $user,
            'token' => $token,
            'expires_at' => $session->expires_at,
            'start_param' => $startParam,
            'created' => $created,
        ];
    }

    public function resolve(?string $token): ?User
    {
        if ($token === null || strlen($token) !== 64) {
            return null;
        }

        $session = AppSession::query()->with('user')->where('token_hash', hash('sha256', $token))->first();
        if (! $session || $session->expires_at->isPast() || ! $session->user || $session->user->isSuspended()) {
            return null;
        }

        if (! $session->last_used_at || $session->last_used_at->lt(now()->subMinute())) {
            $session->forceFill(['last_used_at' => now()])->save();
            $session->user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $session->user;
    }

    public function logout(string $token): void
    {
        AppSession::query()->where('token_hash', hash('sha256', $token))->delete();
    }
}
