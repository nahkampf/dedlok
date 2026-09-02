<?php

namespace Tests\Unit\Skills;

use App\Skills\SkillProgression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure maths, so no framework boot and no database. */
class SkillProgressionTest extends TestCase
{
    private function progression(): SkillProgression
    {
        return new SkillProgression;   // defaults match config/skills.php
    }

    public function test_the_multipliers_follow_the_curve(): void
    {
        // round(0.8 * 5.95^(L-1))
        $this->assertSame([1, 5, 28, 169, 1003], array_map(
            fn (int $level): int => $this->progression()->multiplier($level),
            [1, 2, 3, 4, 5],
        ));
    }

    public function test_level_one_is_the_documented_breakpoint(): void
    {
        $this->assertSame(242, $this->progression()->threshold(1, 1));
        $this->assertSame(484, $this->progression()->threshold(1, 2));
        $this->assertSame(3872, $this->progression()->threshold(1, 16));
    }

    /** @return array<string, array{0: int, 1: array<int, int>}> */
    public static function rankThresholds(): array
    {
        return [
            'rank 1' => [1, [1 => 242, 2 => 1210, 3 => 6776, 4 => 40898, 5 => 242726]],
            'rank 2' => [2, [1 => 484, 2 => 2420, 3 => 13552, 4 => 81796, 5 => 485452]],
            'rank 16' => [16, [1 => 3872, 2 => 19360, 3 => 108416, 4 => 654368, 5 => 3883616]],
        ];
    }

    /** @param  array<int, int>  $expected */
    #[DataProvider('rankThresholds')]
    public function test_thresholds_scale_with_rank(int $rank, array $expected): void
    {
        $this->assertSame($expected, $this->progression()->thresholds($rank));
    }

    public function test_rank_is_a_flat_multiplier_on_every_level(): void
    {
        $p = $this->progression();

        foreach ([1, 2, 3, 4, 5] as $level) {
            $this->assertSame(
                $p->threshold($level, 1) * 4,
                $p->threshold($level, 4),
                'rank 4 must cost exactly four times rank 1'
            );
        }
    }

    public function test_the_cap_is_the_level_five_threshold(): void
    {
        $this->assertSame(242726, $this->progression()->cap(1));
        $this->assertSame(3883616, $this->progression()->cap(16));
    }

    public function test_level_is_derived_from_points(): void
    {
        $p = $this->progression();

        $this->assertSame(0, $p->levelFor(0, 1));
        $this->assertSame(0, $p->levelFor(241, 1));
        $this->assertSame(1, $p->levelFor(242, 1), 'exactly on the breakpoint counts');
        $this->assertSame(1, $p->levelFor(1209, 1));
        $this->assertSame(2, $p->levelFor(1210, 1));
        $this->assertSame(5, $p->levelFor(242726, 1));
        $this->assertSame(5, $p->levelFor(999999, 1), 'overspill stays level 5');
    }

    public function test_rank_changes_the_level_the_same_points_reach(): void
    {
        $p = $this->progression();

        $this->assertSame(1, $p->levelFor(484, 1));
        $this->assertSame(1, $p->levelFor(484, 2));
        $this->assertSame(2, $p->levelFor(1210, 1));
        $this->assertSame(0, $p->levelFor(483, 2));
    }

    public function test_points_to_next_level(): void
    {
        $p = $this->progression();

        $this->assertSame(242, $p->pointsToNextLevel(0, 1));
        $this->assertSame(1, $p->pointsToNextLevel(241, 1));
        $this->assertSame(968, $p->pointsToNextLevel(242, 1));   // 1210 - 242
        $this->assertNull($p->pointsToNextLevel(242726, 1), 'nothing after the cap');
    }

    public function test_invalid_ranks_are_rejected(): void
    {
        $p = $this->progression();

        $this->assertTrue($p->isValidRank(8));
        $this->assertFalse($p->isValidRank(3));

        $this->expectException(\InvalidArgumentException::class);
        $p->threshold(1, 3);
    }

    public function test_a_level_outside_the_range_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->progression()->multiplier(6);
    }

    /** The curve is meant to be tuned, so the constants must actually be inputs. */
    public function test_the_curve_is_configurable(): void
    {
        $tuned = new SkillProgression(levelOneBase: 100, scalingFactor: 1.0, growthMultiplier: 2.0);

        $this->assertSame([1 => 100, 2 => 200, 3 => 400, 4 => 800, 5 => 1600], $tuned->thresholds(1));
    }
}
