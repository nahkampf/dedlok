<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * A die that returns exactly what it was given, in order — and complains if it is asked for more,
 * or if the test ends with some unused. A fight's dice have a documented order, so the order IS
 * the thing under test, and a die that quietly repeats would hide the day it drifts.
 */
final class QueueDice
{
    /** @var list<float> */
    private array $queue;

    private int $asked = 0;

    /** @param  list<float|int>  $values */
    public function __construct(array $values)
    {
        $this->queue = array_map('floatval', $values);
    }

    public function __invoke(): float
    {
        if ($this->queue === []) {
            throw new \LogicException('The die was asked for a value after '.$this->asked.' it was given.');
        }

        $this->asked++;

        return array_shift($this->queue);
    }

    public function assertSpent(): void
    {
        Assert::assertSame([], $this->queue, 'Dice were left over: the engine rolled fewer than the test expected.');
    }
}
