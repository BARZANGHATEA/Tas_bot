<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
