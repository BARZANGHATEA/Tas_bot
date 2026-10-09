<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Balances are only ever changed by App\Services\WalletService, which writes
 * a matching ledger entry in the same database transaction.
 */
class Wallet extends Model
{
    protected $guarded = ['id', 'available', 'reserved', 'total_earned', 'total_withdrawn'];

    protected function casts(): array
    {
        return [
            'available' => 'decimal:6',
            'reserved' => 'decimal:6',
            'total_earned' => 'decimal:6',
            'total_withdrawn' => 'decimal:6',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
