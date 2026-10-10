<?php

use App\Services\Settings;
use App\Support\Money;

if (! function_exists('usdt')) {
    /** Format a stored decimal amount for display (never used for arithmetic). */
    function usdt(mixed $amount, int $decimals = 2): string
    {
        return Money::format(is_numeric($amount) || $amount === null ? (string) $amount : $amount, $decimals);
    }
}

if (! function_exists('usdt_input')) {
    /** Exact amount for an input field: 0.020000 -> 0.02, 5.000000 -> 5.00. */
    function usdt_input(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }
        $text = (string) Money::of((string) $amount);
        [$int, $frac] = array_pad(explode('.', $text), 2, '');

        return $int.'.'.str_pad(rtrim($frac, '0'), 2, '0');
    }
}

if (! function_exists('biz_date')) {
    /** Format a timestamp in the configured business time zone and format. */
    function biz_date(?DateTimeInterface $date): string
    {
        return app(Settings::class)->formatDate($date);
    }
}
