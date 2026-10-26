<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Run;
use PHPUnit\Framework\TestCase;

/** An ICE with a fraction of its integrity left still swings, so the screen must still show it. */
final class RunInAFightTest extends TestCase
{
    public function test_a_fraction_of_integrity_is_still_a_fight(): void
    {
        $run = new Run;
        $run->ice_id = 1;
        $run->ice_integrity = 0.4;

        $this->assertTrue($run->inAFight());
    }
}
