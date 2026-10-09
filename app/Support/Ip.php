<?php

namespace App\Support;

/**
 * IP addresses are only ever stored as keyed hashes: good enough to correlate
 * accounts for fraud review, useless to anyone who reads the database.
 */
final class Ip
{
    public static function hash(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        // Group IPv6 addresses by /64 so privacy extensions don't hide clusters.
        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);
            if ($packed !== false) {
                $ip = bin2hex(substr($packed, 0, 8));
            }
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }
}
