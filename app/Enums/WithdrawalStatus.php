<?php

namespace App\Enums;

enum WithdrawalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Allowed transitions of the withdrawal state machine. */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Processing, self::Rejected],
            self::Processing => [self::Paid, self::Rejected],
            self::Paid, self::Rejected, self::Cancelled => [],
        }, true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Approved, self::Processing], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Approved => 'Approved for payment',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Approved->value, self::Processing->value];
    }
}
