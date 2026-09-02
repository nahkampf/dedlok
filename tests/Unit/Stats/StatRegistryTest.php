<?php

namespace Tests\Unit\Stats;

use App\Stats\Stat;
use App\Stats\StatRegistry;
use App\Stats\Target;
use PHPUnit\Framework\TestCase;

class StatRegistryTest extends TestCase
{
    private function registry(): StatRegistry
    {
        return StatRegistry::fromDefinitions([
            'focus' => ['tags' => ['attribute', 'mental'], 'min' => 0, 'max' => 100, 'rounding' => 'round'],
            'muscle' => ['tags' => ['attribute', 'physical'], 'min' => 0, 'max' => 100, 'rounding' => 'round'],
            'hitpoints_max' => ['tags' => ['derived', 'vital'], 'min' => 1, 'rounding' => 'floor'],
        ]);
    }

    public function test_it_finds_stats_by_tag(): void
    {
        $this->assertSame(['focus', 'muscle'], $this->registry()->tagged('attribute'));
        $this->assertSame(['focus'], $this->registry()->tagged('attribute', 'mental'));
        $this->assertSame([], $this->registry()->tagged('nonexistent'));
    }

    public function test_it_finds_a_stat_by_id(): void
    {
        $this->assertSame(['muscle'], $this->registry()->matching(Target::id('muscle')));
    }

    public function test_an_unknown_stat_is_rejected_with_a_useful_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Declare it in config\/stats\.php/');

        $this->registry()->get('nope');
    }

    public function test_unknown_keys_in_a_definition_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown keys: clamp/');

        Stat::fromArray('focus', ['tags' => ['attribute'], 'clamp' => [0, 100]]);
    }

    public function test_an_unknown_rounding_rule_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Stat::fromArray('focus', ['rounding' => 'nearest']);
    }

    public function test_rounding_rules(): void
    {
        $this->assertSame(14.0, (new Stat('x', rounding: 'round'))->round(13.6));
        $this->assertSame(13.0, (new Stat('x', rounding: 'floor'))->round(13.6));
        $this->assertSame(14.0, (new Stat('x', rounding: 'ceil'))->round(13.2));
        $this->assertSame(13.6, (new Stat('x', rounding: 'none'))->round(13.6));
    }
}
