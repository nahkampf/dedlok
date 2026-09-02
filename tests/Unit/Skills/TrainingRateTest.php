<?php

namespace Tests\Unit\Skills;

use App\Skills\TrainingRate;
use PHPUnit\Framework\TestCase;

class TrainingRateTest extends TestCase
{
    public function test_the_documented_formula(): void
    {
        // 60 * (0.121 * primary + 0.0762 * secondary)
        $rate = new TrainingRate;

        $this->assertEqualsWithDelta(177.48, $rate->perMinute(15, 15), 0.001);
        $this->assertEqualsWithDelta(213.78, $rate->perMinute(20, 15), 0.001);
        $this->assertEqualsWithDelta(591.60, $rate->perMinute(50, 50), 0.001);
    }

    public function test_the_primary_attribute_contributes_more_than_the_secondary(): void
    {
        $rate = new TrainingRate;

        $this->assertGreaterThan($rate->perMinute(0, 10), $rate->perMinute(10, 0));
    }

    public function test_zero_attributes_produce_no_progress(): void
    {
        $rate = new TrainingRate;

        $this->assertSame(0.0, $rate->perMinute(0, 0));
        $this->assertNull($rate->minutesFor(242, 0, 0), 'training would never finish');
    }

    public function test_it_reports_how_long_a_target_takes(): void
    {
        $rate = new TrainingRate;

        $minutes = $rate->minutesFor(242726, 15, 15);

        $this->assertNotNull($minutes);
        $this->assertEqualsWithDelta(22.8, $minutes / 60, 0.1);
    }

    /** The weighting is expected to change, so it has to be an input. */
    public function test_the_weighting_is_configurable(): void
    {
        $tuned = new TrainingRate(primaryWeight: 1.0, secondaryWeight: 0.0, secondsPerMinute: 1);

        $this->assertSame(10.0, $tuned->perMinute(10, 999));
    }
}
