<?php

namespace App\Enums;

enum MatchStatus: string
{
    case Waiting = 'waiting';
    case Ready = 'ready';
    case Playing = 'playing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Waiting => [self::Ready, self::Cancelled],
            self::Ready => [self::Playing, self::Cancelled],
            self::Playing => [self::Playing, self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        }, true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Waiting, self::Ready, self::Playing], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Waiting->value, self::Ready->value, self::Playing->value];
    }
}
