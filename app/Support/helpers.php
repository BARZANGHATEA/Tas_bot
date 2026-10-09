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

if (! function_exists('biz_date')) {
    /** Format a timestamp in the configured business time zone and format. */
    function biz_date(?DateTimeInterface $date): string
    {
        return app(Settings::class)->formatDate($date);
    }
}
