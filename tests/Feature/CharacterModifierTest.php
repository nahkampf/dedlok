<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterModifier;
use App\Stats\EffectDefinition;
use App\Stats\InjuryLedger;
use App\Stats\Sources\CharacterModifiers;
use App\Stats\Stacking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * What a character is carrying in their own right.
 *
 * The third modifier source, and the first that is not derived from something
 * else — an archetype perk exists because of the archetype, a program's effect
 * because the program runs, and these exist because something happened and
 * stayed.
 */
final class CharacterModifierTest extends TestCase
{
    use RefreshDatabase;

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
    }

    private function ledger(): InjuryLedger
    {
        return new InjuryLedger;
    }

    private function row(Character $character, array $attributes = []): CharacterModifier
    {
        return CharacterModifier::query()->create(array_merge([
            'character_id' => $character->id,
            'definition_key' => InjuryLedger::NEURAL,
            'data' => ['amount' => -4],
        ], $attributes));
    }

    // ---- definitions -------------------------------------------------------

    public function test_the_shipped_definitions_load(): void
    {
        $neural = EffectDefinition::find(InjuryLedger::NEURAL);

        $this->assertNotNull($neural);
        $this->assertSame(Stacking::Unique, $neural->stacking);
        $this->assertFalse($neural->retired);
        $this->assertTrue($neural->takesAmountFromData());

        $this->assertNotNull(EffectDefinition::find(InjuryLedger::TRAUMA));
        $this->assertNull(EffectDefinition::find('no_such_effect'));
    }

    /** A definition wanting an amount and given none contributes nothing. */
    public function test_a_missing_amount_applies_nothing_rather_than_zero(): void
    {
        $definition = EffectDefinition::find(InjuryLedger::NEURAL);

        $this->assertSame([], $definition->modifiers(null));
        $this->assertCount(1, $definition->modifiers(-6.0));
        $this->assertSame(-6.0, $definition->modifiers(-6.0)[0]->amount);
    }

    // ---- the source --------------------------------------------------------

    public function test_a_carried_injury_reaches_the_resolver(): void
    {
        $character = $this->character();
        $before = $character->stat('synaptic_integrity_max')->value;

        $this->ledger()->inflict($character, InjuryLedger::NEURAL, 5);

        $after = $character->fresh()->stat('synaptic_integrity_max')->value;

        $this->assertSame($before - 5, $after, 'the injury moves the ceiling');
    }

    public function test_an_expired_modifier_is_ignored_even_if_nobody_swept_it(): void
    {
        $character = $this->character();
        $this->row($character, ['expires_at' => now()->subMinute()]);

        $this->assertSame([], (new CharacterModifiers($character->id))->modifiers());
        $this->assertSame(1, CharacterModifier::query()->count(), 'still on the table, just not applied');
    }

    public function test_a_modifier_that_has_not_expired_still_applies(): void
    {
        $character = $this->character();
        $this->row($character, ['expires_at' => now()->addHour()]);

        $this->assertCount(1, (new CharacterModifiers($character->id))->modifiers());
    }

    /** A dangling key must not break a character's sheet. */
    public function test_a_row_whose_definition_is_gone_contributes_nothing(): void
    {
        $character = $this->character();
        $this->row($character, ['definition_key' => 'deleted_by_somebody']);

        $this->assertSame([], (new CharacterModifiers($character->id))->modifiers());
        $this->assertIsFloat($character->stat('synaptic_integrity_max')->value);
    }

    public function test_one_characters_injuries_are_not_anothers(): void
    {
        $mine = $this->character();
        $theirs = $this->character();

        $this->ledger()->inflict($mine, InjuryLedger::NEURAL, 5);

        $this->assertSame(-5.0, $this->ledger()->carried($mine, InjuryLedger::NEURAL));
        $this->assertSame(0.0, $this->ledger()->carried($theirs, InjuryLedger::NEURAL));
    }

    // ---- stacking ----------------------------------------------------------

    public function test_sum_applies_every_instance(): void
    {
        Config::set('effects.test_stack', [
            'stacking' => 'sum',
            'modifiers' => [['op' => 'add', 'amount' => ':amount', 'target' => ['id' => 'perception']]],
        ]);

        $character = $this->character();
        $this->row($character, ['definition_key' => 'test_stack', 'data' => ['amount' => 2]]);
        $this->row($character, ['definition_key' => 'test_stack', 'data' => ['amount' => 3]]);

        $modifiers = (new CharacterModifiers($character->id))->modifiers();

        $this->assertCount(2, $modifiers);
        $this->assertSame(5.0, array_sum(array_map(static fn ($m) => $m->amount, $modifiers)));
    }

    public function test_highest_applies_only_the_strongest(): void
    {
        Config::set('effects.test_stack', [
            'stacking' => 'highest',
            'modifiers' => [['op' => 'add', 'amount' => ':amount', 'target' => ['id' => 'perception']]],
        ]);

        $character = $this->character();
        $this->row($character, ['definition_key' => 'test_stack', 'data' => ['amount' => -2]]);
        $this->row($character, ['definition_key' => 'test_stack', 'data' => ['amount' => -7]]);
        $this->row($character, ['definition_key' => 'test_stack', 'data' => ['amount' => -4]]);

        $modifiers = (new CharacterModifiers($character->id))->modifiers();

        $this->assertCount(1, $modifiers);
        $this->assertSame(-7.0, $modifiers[0]->amount, 'strongest EFFECT, not largest number');
    }

    public function test_unique_applies_one_however_many_rows_there_are(): void
    {
        $character = $this->character();
        $this->row($character, ['data' => ['amount' => -4]]);
        $this->row($character, ['data' => ['amount' => -9]]);

        $this->assertCount(1, (new CharacterModifiers($character->id))->modifiers());
    }

    public function test_unique_by_source_counts_one_per_source(): void
    {
        Config::set('effects.test_stack', [
            'stacking' => 'unique_by_source',
            'modifiers' => [['op' => 'add', 'amount' => ':amount', 'target' => ['id' => 'perception']]],
        ]);

        $character = $this->character();
        $shared = ['definition_key' => 'test_stack', 'data' => ['amount' => 1]];

        // Two implants, and one of them counted twice.
        $this->row($character, $shared + ['source_type' => 'item', 'source_id' => 1]);
        $this->row($character, $shared + ['source_type' => 'item', 'source_id' => 1]);
        $this->row($character, $shared + ['source_type' => 'item', 'source_id' => 2]);

        $this->assertCount(2, (new CharacterModifiers($character->id))->modifiers());
    }

    /** Different definitions never compete with each other. */
    public function test_stacking_groups_by_definition(): void
    {
        $character = $this->character();
        $this->ledger()->inflict($character, InjuryLedger::NEURAL, 5);
        $this->ledger()->inflict($character, InjuryLedger::TRAUMA, 3);

        $this->assertCount(2, (new CharacterModifiers($character->id))->modifiers());
    }

    // ---- the ledger --------------------------------------------------------

    /** ONE ROW, DEEPENED. The nerves do not care what caused the damage. */
    public function test_injuries_accumulate_into_a_single_row(): void
    {
        $character = $this->character();

        $this->assertSame(-5.0, $this->ledger()->inflict($character, InjuryLedger::NEURAL, 5));
        $this->assertSame(-8.0, $this->ledger()->inflict($character, InjuryLedger::NEURAL, 3));

        $this->assertSame(1, CharacterModifier::query()->count());
        $this->assertSame(-8.0, $this->ledger()->carried($character, InjuryLedger::NEURAL));
    }

    public function test_healing_reduces_the_same_row(): void
    {
        $character = $this->character();
        $this->ledger()->inflict($character, InjuryLedger::NEURAL, 8);

        $this->assertSame(-3.0, $this->ledger()->heal($character, InjuryLedger::NEURAL, 5));
        $this->assertSame(1, CharacterModifier::query()->count());
    }

    /** A row that reaches zero is deleted: nobody needs to know you were hurt. */
    public function test_healing_to_zero_removes_the_row(): void
    {
        $character = $this->character();
        $this->ledger()->inflict($character, InjuryLedger::NEURAL, 4);

        $this->assertSame(0.0, $this->ledger()->heal($character, InjuryLedger::NEURAL, 4));
        $this->assertSame(0, CharacterModifier::query()->count());
    }

    /** A clinic cannot make somebody better than well. */
    public function test_healing_more_than_is_carried_does_not_overshoot(): void
    {
        $character = $this->character();
        $this->ledger()->inflict($character, InjuryLedger::NEURAL, 3);

        $this->assertSame(0.0, $this->ledger()->heal($character, InjuryLedger::NEURAL, 99));
        $this->assertSame(0, CharacterModifier::query()->count());
    }

    /** Callers say how much HARM, never a sign. */
    public function test_inflicting_takes_a_positive_quantity_of_harm(): void
    {
        $character = $this->character();

        $this->assertSame(-6.0, $this->ledger()->inflict($character, InjuryLedger::NEURAL, 6));
        $this->assertSame(-6.0, $this->ledger()->inflict($character, InjuryLedger::NEURAL, 0));
        $this->assertSame(-6.0, $this->ledger()->inflict($character, InjuryLedger::NEURAL, -3));
    }

    /** The injuries are unsourced on purpose: they outlive their cause. */
    public function test_an_injury_carries_no_source(): void
    {
        $character = $this->character();
        $this->ledger()->inflict($character, InjuryLedger::NEURAL, 2);

        $row = CharacterModifier::query()->sole();

        $this->assertNull($row->source_type);
        $this->assertNull($row->source_id);
        $this->assertNull($row->expires_at, 'permanent until a clinic says otherwise');
    }

    /** The floor is what stops injury ever finishing a character by arithmetic. */
    public function test_accumulated_injury_converges_on_one_and_never_zero(): void
    {
        $character = $this->character();
        $this->ledger()->inflict($character, InjuryLedger::NEURAL, 9999);

        $this->assertSame(1.0, $character->fresh()->stat('synaptic_integrity_max')->value);
    }

    public function test_deleting_a_character_takes_their_modifiers(): void
    {
        $character = $this->character();
        $this->ledger()->inflict($character, InjuryLedger::NEURAL, 5);

        $character->delete();

        $this->assertSame(0, CharacterModifier::query()->count());
    }
}
