<?php

namespace App\Support;

/**
 * Masks wallet addresses for public display. Only the configured number of
 * leading and trailing characters stay visible; everything else becomes "*".
 */
final class WalletMask
{
    public static function mask(string $address, int $head = 4, int $tail = 4): string
    {
        $address = trim($address);
        $length = mb_strlen($address);
        $head = max(0, $head);
        $tail = max(0, $tail);

        // Always hide at least half of the address, whatever the configuration.
        $maxVisible = intdiv($length, 2);
        if ($head + $tail > $maxVisible) {
            $head = min($head, intdiv($maxVisible, 2));
            $tail = min($tail, $maxVisible - $head);
        }

        $hidden = $length - $head - $tail;

        return mb_substr($address, 0, $head)
            .str_repeat('*', max(0, $hidden))
            .($tail > 0 ? mb_substr($address, -$tail) : '');
    }

    /** Public alias such as "Alex M." built from a full name. */
    public static function alias(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return 'User';
        }

        $first = mb_substr($parts[0], 0, 20);

        if (count($parts) === 1) {
            return $first;
        }

        return $first.' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.';
    }
}
