<?php

namespace Tests\Unit\Stats;

use App\Stats\Modifier;
use App\Stats\Op;
use App\Stats\Target;
use PHPUnit\Framework\TestCase;

class ModifierTest extends TestCase
{
    public function test_it_builds_from_config_data(): void
    {
        $m = Modifier::fromArray([
            'op' => 'percentage_of_base',
            'amount' => -10,
            'target' => ['id' => 'focus'],
            'source' => 'Sandevistan',
        ]);

        $this->assertSame(Op::PercentageOfBase, $m->op);
        $this->assertSame(-10.0, $m->amount);
        $this->assertSame('Sandevistan', $m->source);
    }

    public function test_it_builds_a_tag_target(): void
    {
        $m = Modifier::fromArray([
            'op' => 'add',
            'amount' => 5,
            'target' => ['all_of' => ['attribute', 'mental']],
        ]);

        $this->assertSame('attribute+mental', $m->target->describe());
    }

    /**
     * Conditions are specified in MECHANICS.md but not implemented. A condition
     * written today must fail loudly rather than be silently ignored, which
     * would leave a modifier applying unconditionally and quietly unbalance the
     * game.
     */
    public function test_an_unimplemented_condition_key_is_rejected_not_ignored(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown modifier keys: condition/');

        Modifier::fromArray([
            'op' => 'add',
            'amount' => 5,
            'target' => ['id' => 'focus'],
            'condition' => ['when' => 'hp_below_pct', 'args' => ['pct' => 30]],
        ]);
    }

    public function test_a_typo_in_an_op_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown modifier op \[pct_base\]/');

        Modifier::fromArray(['op' => 'pct_base', 'amount' => 5, 'target' => ['id' => 'focus']]);
    }

    public function test_missing_keys_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Modifier::fromArray(['op' => 'add', 'amount' => 5]);
    }

    public function test_a_target_cannot_be_both_an_id_and_a_tag_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Target::fromArray(['id' => 'focus', 'all_of' => ['attribute']]);
    }

    public function test_an_empty_tag_set_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Target::allOf([]);
    }
}
