<?php

namespace App\Support;

use Brick\Math\BigInteger;

/**
 * Base58Check validation for TRON (TRC20) addresses, so a typo is caught
 * before an irreversible payment is ever attempted.
 */
final class TronAddress
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function isValid(string $address): bool
    {
        if (! preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return false;
        }

        $bytes = self::decode($address);
        if ($bytes === null || strlen($bytes) !== 25 || $bytes[0] !== "\x41") {
            return false;
        }

        $payload = substr($bytes, 0, 21);
        $checksum = substr($bytes, 21, 4);

        return hash_equals(substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4), $checksum);
    }

    private static function decode(string $input): ?string
    {
        $number = BigInteger::zero();
        foreach (str_split($input) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return null;
            }
            $number = $number->multipliedBy(58)->plus($index);
        }

        $hex = $number->toBase(16);
        if (strlen($hex) % 2 === 1) {
            $hex = '0'.$hex;
        }
        $bytes = $number->isZero() ? '' : hex2bin($hex);

        $leadingZeros = strspn($input, '1');

        return str_repeat("\x00", $leadingZeros).$bytes;
    }
}
