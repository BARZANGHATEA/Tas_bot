<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers and updates Telegram players. Identity always comes from data
 * Telegram signed (webhook updates authenticated by the secret token, or
 * Mini App init data validated with the bot token).
 */
class PlayerService
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly FraudService $fraud,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array  $tgUser  Telegram User object (id, first_name, last_name, username, ...)
     * @return array{0: User, 1: bool} [user, created]
     */
    public function findOrRegister(array $tgUser, ?string $startParam, ?string $ipHash, string $source): array
    {
        $telegramId = (int) $tgUser['id'];
        $profile = $this->profileAttributes($tgUser);

        $existing = User::query()->where('telegram_id', $telegramId)->first();
        if ($existing) {
            // Profile fields follow Telegram; referral attribution never changes.
            $existing->forceFill($profile + ['last_seen_at' => now(), 'last_ip_hash' => $ipHash ?? $existing->last_ip_hash])->save();

            return [$existing, false];
        }

        $referrer = $this->referrals->resolveReferrer($startParam, $telegramId);

        try {
            $user = DB::transaction(function () use ($telegramId, $profile, $referrer, $ipHash, $source) {
                $user = User::query()->create($profile + [
                    'telegram_id' => $telegramId,
                    'referral_code' => User::generateReferralCode(),
                    'referrer_id' => $referrer?->id,
                    'referred_at' => $referrer ? now() : null,
                    'status' => UserStatus::Active,
                    'registration_source' => $source,
                    'registration_ip_hash' => $ipHash,
                    'last_ip_hash' => $ipHash,
                    'last_seen_at' => now(),
                ]);
                Wallet::query()->create(['user_id' => $user->id]);

                return $user;
            });
        } catch (QueryException $e) {
            // Concurrent first contact (e.g. duplicate /start delivery): use the winner.
            $user = User::query()->where('telegram_id', $telegramId)->first();
            if (! $user) {
                throw $e;
            }

            return [$user, false];
        }

        $this->audit->log('user.registered', $user, ['source' => $source, 'referrer_id' => $referrer?->id], user: $user);
        $this->fraud->afterRegistration($user);

        return [$user, true];
    }

    private function profileAttributes(array $tgUser): array
    {
        $clean = fn ($value, int $max) => $value === null ? null : Str::limit(strip_tags(trim((string) $value)), $max, '');

        $photo = $tgUser['photo_url'] ?? null;
        if ($photo !== null && ! preg_match('#^https://[a-z0-9.-]*telegram\.(org|me)/#i', (string) $photo)) {
            $photo = null;
        }

        return [
            'first_name' => $clean($tgUser['first_name'] ?? 'Player', 128) ?: 'Player',
            'last_name' => $clean($tgUser['last_name'] ?? null, 128),
            'username' => isset($tgUser['username']) && preg_match('/^[A-Za-z0-9_]{3,64}$/', (string) $tgUser['username']) ? $tgUser['username'] : null,
            'language_code' => isset($tgUser['language_code']) ? Str::limit((string) $tgUser['language_code'], 12, '') : null,
            'is_premium' => (bool) ($tgUser['is_premium'] ?? false),
            'photo_url' => $photo ? Str::limit($photo, 500, '') : null,
        ];
    }
}
