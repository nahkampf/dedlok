<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * What a flatline takes, by level.
 *
 * Nothing reads these numbers — the defeat tags are phase 5 — so this is not a
 * test of behaviour. It is a guard on a DESIGN RULE that is easy to break by
 * tuning: a flatline is permanent, it accumulates, and the whole shape of
 * losing depends on no single one of them being decisive.
 */
final class FlatlineLevelTest extends TestCase
{
    /**
     * NOT A FIFTH. CYBERSPACE.md: an event taking 20% of somebody's ceiling
     * would end characters rather than mark them, and the design leans on
     * accumulation instead — the fourth flatline is the one that matters.
     *
     * A grade 9's ICE gets its teeth from carrying Flatline III AND Surge III
     * AND Drain, which is what several tags on one program are for. If a level
     * here ever needs to break 20 to feel dangerous, the tag set is the thing
     * that is wrong.
     */
    public function test_no_single_flatline_takes_a_fifth_of_the_ceiling(): void
    {
        foreach (Config::get('cyberspace.defeat_tags.flatline') as $level => $band) {
            $this->assertLessThan(20, $band['max'], "Flatline {$level} reaches a fifth of maximum SI");
        }
    }

    /** Worse means worse. A level that did not out-rank the one below it is a label. */
    public function test_the_levels_climb(): void
    {
        $bands = Config::get('cyberspace.defeat_tags.flatline');

        $this->assertSame([1, 2, 3], array_keys($bands), 'three levels, and three is enough');

        foreach ([1, 2] as $level) {
            $this->assertGreaterThan($bands[$level]['min'], $bands[$level + 1]['min']);
            $this->assertGreaterThan($bands[$level]['max'], $bands[$level + 1]['max']);
        }

        foreach ($bands as $level => $band) {
            $this->assertLessThan($band['max'], $band['min'], "Flatline {$level} has an empty range");
        }
    }

    /**
     * A BURNOUT IS AN ACCIDENT, so it is the mildest flatline there is — it
     * must never take more of a ceiling than software built on purpose to.
     */
    public function test_a_freeze_deals_the_mildest_flatline(): void
    {
        $this->assertSame(1, Config::get('cyberspace.freeze.flatline_level'));
        $this->assertSame(
            min(array_keys(Config::get('cyberspace.defeat_tags.flatline'))),
            Config::get('cyberspace.freeze.flatline_level'),
        );
    }
}
