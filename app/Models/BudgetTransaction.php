<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BudgetTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'balance_after' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Budget transactions are immutable.'));
        static::deleting(fn () => throw new LogicException('Budget transactions cannot be deleted.'));
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
