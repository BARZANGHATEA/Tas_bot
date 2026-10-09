<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A settled single-player round. Written once, never modified. */
class GameRound extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_win' => 'boolean',
            'reward' => 'decimal:6',
            'rules' => 'array',
            'die_one' => 'integer',
            'die_two' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Settled game rounds are immutable.'));
        static::deleting(fn () => throw new LogicException('Game rounds cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
