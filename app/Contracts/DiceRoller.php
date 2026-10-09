<?php

namespace App\Contracts;

interface DiceRoller
{
    /** Returns an integer in [1, $sides]. Must be unpredictable to clients. */
    public function roll(int $sides = 6): int;
}
