<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'amount' => 'decimal:6',
            'fee' => 'decimal:6',
            'net_amount' => 'decimal:6',
            'approved_at' => 'datetime',
            'processing_at' => 'datetime',
            'paid_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'processing_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'paid_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'rejected_by');
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }
}
