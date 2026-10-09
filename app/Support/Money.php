<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic for USDT amounts. Floats are never used for money.
 * Amounts are stored with 6 decimal places (USDT's on-chain precision).
 */
final class Money
{
    public const SCALE = 6;

    public static function of(BigDecimal|string|int|float|null $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value->toScale(self::SCALE, RoundingMode::DOWN);
        }

        if ($value === null || $value === '') {
            return BigDecimal::zero()->toScale(self::SCALE);
        }

        if (is_float($value)) {
            // Only reachable for values read back from loosely typed drivers.
            $value = number_format($value, self::SCALE, '.', '');
        }

        return BigDecimal::of(trim((string) $value))->toScale(self::SCALE, RoundingMode::DOWN);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(self::SCALE);
    }

    /** Canonical storage string, e.g. "12.500000". */
    public static function str(BigDecimal|string|int|null $value): string
    {
        return (string) self::of($value);
    }

    /** Human-readable amount, e.g. "12.50". Rounds down so we never overstate. */
    public static function format(BigDecimal|string|int|null $value, int $decimals = 2): string
    {
        $amount = self::of($value);
        $formatted = (string) $amount->toScale($decimals, RoundingMode::DOWN);

        // Show more precision for tiny non-zero amounts rather than "0.00".
        if ($decimals < self::SCALE && ! $amount->isZero() && BigDecimal::of($formatted)->isZero()) {
            return rtrim(rtrim((string) $amount, '0'), '.');
        }

        return $formatted;
    }

    public static function percentOf(BigDecimal|string $amount, BigDecimal|string $percent): BigDecimal
    {
        return self::of($amount)
            ->multipliedBy(BigDecimal::of((string) $percent))
            ->dividedBy(100, self::SCALE, RoundingMode::DOWN);
    }

    public static function min(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isLessThan($b) ? $a : $b;
    }

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        return (bool) preg_match('/^\d{1,13}(\.\d{1,6})?$/', (string) $value);
    }
}
