<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class RewardBudget extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:6',
            'total_funded' => 'decimal:6',
            'total_issued' => 'decimal:6',
            'total_returned' => 'decimal:6',
        ];
    }

    /** The platform has exactly one budget row, always id 1. */
    public static function current(): self
    {
        if ($budget = static::query()->find(1)) {
            return $budget;
        }

        try {
            $budget = new static;
            $budget->forceFill(['id' => 1])->save();

            return $budget;
        } catch (UniqueConstraintViolationException) {
            return static::query()->findOrFail(1); // created concurrently
        }
    }
}
