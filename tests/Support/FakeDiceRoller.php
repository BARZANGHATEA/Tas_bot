<?php

namespace Tests\Support;

use App\Contracts\DiceRoller;
use RuntimeException;

/** Deterministic dice for tests: returns queued values in order. */
class FakeDiceRoller implements DiceRoller
{
    /** @var list<int> */
    private array $queue = [];

    public function queue(int ...$values): self
    {
        array_push($this->queue, ...$values);

        return $this;
    }

    public function roll(int $sides = 6): int
    {
        if ($this->queue === []) {
            throw new RuntimeException('FakeDiceRoller ran out of values.');
        }

        return array_shift($this->queue);
    }
}
