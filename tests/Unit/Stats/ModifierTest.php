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

    public function test_a_condition_is_read_and_the_modifier_knows_it_is_conditional(): void
    {
        $m = Modifier::fromArray([
            'op' => 'add',
            'amount' => 5,
            'target' => ['id' => 'focus'],
            'condition' => ['when' => 'si_below_pct', 'args' => ['pct' => 30]],
        ]);

        $this->assertTrue($m->isConditional());
        $this->assertSame('si_below_pct(pct=30)', $m->condition?->describe());
    }

    /**
     * A predicate is a NAME, and an unknown one fails where it is written
     * rather than the first time somebody happens to be under 30% SI. The
     * message lists what is available, because the usual cause is a typo.
     */
    public function test_an_unknown_predicate_is_rejected_at_the_point_it_is_written(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown condition predicate \[hp_belo\]/');

        Modifier::fromArray([
            'op' => 'add',
            'amount' => 5,
            'target' => ['id' => 'focus'],
            'condition' => ['when' => 'hp_belo', 'args' => ['pct' => 30]],
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
