<?php

namespace App\Models;

use App\Enums\MissionType;
use App\Enums\MissionVerification;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mission extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id', 'budget_used', 'completions_count'];

    protected function casts(): array
    {
        return [
            'type' => MissionType::class,
            'verification' => MissionVerification::class,
            'reward' => 'decimal:6',
            'budget' => 'decimal:6',
            'budget_used' => 'decimal:6',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function completions(): HasMany
    {
        return $this->hasMany(MissionCompletion::class);
    }

    public function isLive(): bool
    {
        return $this->status === 'active'
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function budgetRemaining(): ?string
    {
        if ($this->budget === null) {
            return null;
        }

        $left = Money::of($this->budget)->minus(Money::of($this->budget_used));

        return Money::str($left->isNegative() ? Money::zero() : $left);
    }

    /** URL the user opens to perform the mission, if any. */
    public function actionUrl(): ?string
    {
        $target = trim((string) $this->target);
        if ($target === '') {
            return null;
        }

        return match ($this->type) {
            MissionType::TelegramChannel, MissionType::TelegramGroup => str_starts_with($target, 'http')
                ? $target
                : (str_starts_with($target, '@') ? 'https://t.me/'.ltrim($target, '@') : null),
            MissionType::Instagram => str_starts_with($target, 'http') ? $target : 'https://instagram.com/'.ltrim($target, '@'),
            MissionType::Website, MissionType::Custom => str_starts_with($target, 'https://') || str_starts_with($target, 'http://') ? $target : null,
            default => null,
        };
    }
}
