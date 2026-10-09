<?php

namespace App\Models;

use App\Enums\LedgerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Append-only financial ledger. Entries can never be updated or deleted;
 * corrections are new entries (reversals) that reference the original.
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => LedgerType::class,
            'available_delta' => 'decimal:6',
            'reserved_delta' => 'decimal:6',
            'available_after' => 'decimal:6',
            'reserved_after' => 'decimal:6',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(LedgerEntry::class, 'reverses_entry_id');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'reverses_entry_id');
    }
}
