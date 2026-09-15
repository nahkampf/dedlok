<?php

declare(strict_types=1);

namespace Tests\Unit\Checks;

use App\Checks\CheckRoller;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The die.
 *
 * Every test here pins it. A probabilistic thing tested against real
 * randomness is a test that passes most of the time, which is worse than no
 * test at all — the same reason MarketSeeder and GridSeeder take their rolls as
 * arguments.
 */
final class CheckRollerTest extends TestCase
{
    private function roller(float $die = 0.5): CheckRoller
    {
        return CheckRoller::fromConfig(static fn (): float => $die);
    }

    // ---- the curve ---------------------------------------------------------

    /**
     * The property the whole design rests on: a difficulty is the level at
     * which a character is evenly matched, whatever the numbers are.
     */
    public function test_an_even_match_is_a_coin_flip(): void
    {
        $this->assertSame(0.5, $this->roller()->chance(40, 40));
        $this->assertSame(0.5, $this->roller()->chance(0, 0));
        $this->assertSame(0.5, $this->roller()->chance(999, 999));
    }

    /** What matters is the margin, never either number on its own. */
    public function test_only_the_margin_counts(): void
    {
        $roller = $this->roller();

        $this->assertSame(
            $roller->chance(20, 10),
            $roller->chance(120, 110),
            'ten points ahead is ten points ahead',
        );
    }

    public function test_the_curve_is_the_one_the_config_describes(): void
    {
        Config::set('checks.spread', 8);
        $roller = $this->roller();

        $this->assertEqualsWithDelta(0.73, $roller->chance(48, 40), 0.01);
        $this->assertEqualsWithDelta(0.27, $roller->chance(32, 40), 0.01);
        $this->assertEqualsWithDelta(0.88, $roller->chance(56, 40), 0.01);
    }

    /** More skill is never worse. */
    public function test_the_chance_rises_with_the_margin(): void
    {
        $roller = $this->roller();
        $last = 0.0;

        foreach (range(-40, 40, 4) as $margin) {
            $chance = $roller->chance(40 + $margin, 40);
            $this->assertGreaterThan($last, $chance, "margin {$margin} did not improve on the one below");
            $last = $chance;
        }
    }

    /** One dial between "skill decides" and "everything is a coin flip". */
    public function test_spread_is_how_decisive_the_game_is(): void
    {
        Config::set('checks.spread', 4);
        $sharp = $this->roller()->chance(48, 40);

        Config::set('checks.spread', 16);
        $soft = $this->roller()->chance(48, 40);

        $this->assertGreaterThan($sharp - 0.001, $sharp);
        $this->assertGreaterThan(0.85, $sharp, 'a small spread makes eight points decisive');
        $this->assertLessThan(0.65, $soft, 'a large spread makes the same eight points a nudge');
    }

    // ---- the floor ---------------------------------------------------------

    /**
     * NO CHECK RESOLVES TO A CERTAINTY. The best runner alive still trips a
     * household IDS, and the worst still gets past a military firewall.
     */
    public function test_nothing_is_ever_certain(): void
    {
        $roller = $this->roller();
        $floor = (float) config('checks.certainty_floor');

        $this->assertSame(1 - $floor, $roller->chance(1000, 0));
        $this->assertSame($floor, $roller->chance(0, 1000));
    }

    public function test_a_kind_may_name_its_own_floor(): void
    {
        Config::set('checks.floors', ['detection' => 0.1]);
        $roller = $this->roller();

        $this->assertSame(0.9, $roller->chance(1000, 0, 'detection'));
        $this->assertSame(0.1, $roller->chance(0, 1000, 'detection'));

        // And nothing else moves with it.
        $this->assertSame(1 - (float) config('checks.certainty_floor'), $roller->chance(1000, 0, 'egress'));
        $this->assertSame(1 - (float) config('checks.certainty_floor'), $roller->chance(1000, 0));
    }

    public function test_an_unlisted_kind_takes_the_default_floor(): void
    {
        Config::set('checks.floors', ['detection' => 0.1]);

        $this->assertSame(
            (float) config('checks.certainty_floor'),
            $this->roller()->floorFor('something_else'),
        );
    }

    // ---- rolling -----------------------------------------------------------

    public function test_a_roll_under_the_chance_passes(): void
    {
        $outcome = $this->roller(die: 0.49)->roll(40, 40);

        $this->assertTrue($outcome->passed);
        $this->assertSame(0.5, $outcome->chance);
        $this->assertSame(0.49, $outcome->rolled);
    }

    public function test_a_roll_on_or_above_the_chance_fails(): void
    {
        $this->assertFalse($this->roller(die: 0.5)->roll(40, 40)->passed);
        $this->assertFalse($this->roller(die: 0.99)->roll(40, 40)->passed);
    }

    /** The sliver at the top: hopeless, and it still comes off sometimes. */
    public function test_the_floor_is_reachable_in_both_directions(): void
    {
        $floor = (float) config('checks.certainty_floor');

        $this->assertTrue($this->roller(die: $floor / 2)->roll(0, 1000)->passed);
        $this->assertFalse($this->roller(die: 1 - $floor / 2)->roll(1000, 0)->passed);
    }

    // ---- what it hands back ------------------------------------------------

    public function test_the_outcome_carries_what_a_log_would_need(): void
    {
        Config::set('checks.floors', ['detection' => 0.1]);
        $outcome = $this->roller(die: 0.2)->roll(48, 40, 'detection');

        $this->assertSame('detection', $outcome->kind);
        $this->assertSame(8.0, $outcome->margin());
        $this->assertEqualsWithDelta(0.73, $outcome->chance, 0.01);
        $this->assertTrue($outcome->passed);
        $this->assertFalse($outcome->wasFloored(0.1), 'that was skill, not luck');
    }

    public function test_an_outcome_knows_when_luck_decided_it(): void
    {
        $floor = (float) config('checks.certainty_floor');

        $this->assertTrue($this->roller(die: $floor / 2)->roll(0, 1000)->wasFloored($floor));
        $this->assertTrue($this->roller(die: 1 - $floor / 2)->roll(1000, 0)->wasFloored($floor));
    }

    // ---- the real die ------------------------------------------------------

    /** The default die is uniform in [0, 1) and is the only unpinned thing here. */
    public function test_the_shipped_die_stays_in_range(): void
    {
        $roller = CheckRoller::fromConfig();

        for ($i = 0; $i < 200; $i++) {
            $rolled = $roller->roll(40, 40)->rolled;

            $this->assertGreaterThanOrEqual(0.0, $rolled);
            $this->assertLessThan(1.0, $rolled);
        }
    }
}
