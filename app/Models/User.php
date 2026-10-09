<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A Telegram player. Players have no password: identity is proven by
 * Telegram-signed init data, validated server side.
 */
class User extends Model implements Authenticatable
{
    use AuthenticatableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['registration_ip_hash', 'last_ip_hash', 'admin_note'];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'is_premium' => 'boolean',
            'is_flagged' => 'boolean',
            'status' => UserStatus::class,
            'referred_at' => 'datetime',
            'referral_qualified_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public static function generateReferralCode(): string
    {
        do {
            // Unambiguous alphabet: no 0/O, 1/I/L.
            $code = '';
            $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referrer_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function gameRounds(): HasMany
    {
        return $this->hasMany(GameRound::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function missionCompletions(): HasMany
    {
        return $this->hasMany(MissionCompletion::class);
    }

    public function referralRewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class, 'beneficiary_id');
    }

    public function fraudFlags(): HasMany
    {
        return $this->hasMany(FraudFlag::class);
    }

    public function displayName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? '')) ?: 'Player';
    }

    public function handle(): string
    {
        return $this->username ? '@'.$this->username : $this->displayName();
    }

    /** Short public identifier shown in the app and used by support. */
    public function publicId(): string
    {
        return 'U'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function initials(): string
    {
        return Str::upper(Str::substr($this->first_name, 0, 1).Str::substr((string) $this->last_name, 0, 1)) ?: 'P';
    }
}
