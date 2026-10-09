<?php

namespace App\Services;

use App\Contracts\DiceRoller;

/**
 * Uses PHP's CSPRNG (random_int → getrandom/urandom). Each die is independent.
 */
class SecureDiceRoller implements DiceRoller
{
    public function roll(int $sides = 6): int
    {
        return random_int(1, $sides);
    }
}
