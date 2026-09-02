<?php

namespace Tests\Feature;

use App\Models\Archetype;
use App\Models\Character;
use App\Models\User;
use App\Stats\Op;
use App\Stats\Sources\ArchetypePerks;
use App\Stats\StatRegistry;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DerivedStatTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: array<string,int>, 1: int, 2: int}> */
    public static function formulaCases(): array
    {
        //                                                          attributes,                                              HP,  SI
        return [
            'all zero' => [[], 0, 0],
            'opening spread of 15' => [array_fill_keys(Character::ATTRIBUTES, 15), 31, 32],
            'odd values round each term up' => [['meat' => 21, 'muscle' => 7, 'focus' => 9, 'memory' => 3, 'intellect' => 5], 30, 21],
            'creation cap' => [array_fill_keys(Character::ATTRIBUTES, 50), 100, 100],
            'meat only' => [['meat' => 10], 10, 5],
            'muscle only' => [['muscle' => 10], 5, 0],
            'one point rounds up to one' => [['muscle' => 1], 1, 0],
        ];
    }

    /** @param  array<string, int>  $attributes */
    #[DataProvider('formulaCases')]
    public function test_the_formulas_produce_the_specified_numbers(array $attributes, int $hp, int $si): void
    {
        $filled = array_merge(array_fill_keys(Character::ATTRIBUTES, 0), $attributes);

        $this->assertSame(
            (float) $hp,
            Character::applyFormula(config('stats.formulas.hitpoints_max'), $filled),
            'HP = meat + ceil(muscle/2) + ceil(focus/2)'
        );

        $this->assertSame(
            (float) $si,
            Character::applyFormula(config('stats.formulas.synaptic_integrity_max'), $filled),
            'SI = ceil(focus/2) + ceil(memory/2) + ceil(intellect/2) + ceil(meat/2)'
        );
    }

    public function test_a_character_resolves_both_derived_stats(): void
    {
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 15),
            ['archetype' => 3],   // Fixer: no perks at all
        ));

        $this->assertSame(31.0, $character->stat('hitpoints_max')->value);
        $this->assertSame(32.0, $character->stat('synaptic_integrity_max')->value);
    }

    public function test_hitpoints_floor_at_one_but_synaptic_integrity_can_be_zero(): void
    {
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 0),
            ['archetype' => 3],   // Fixer: no perks, so these are bare formulas
        ));

        $this->assertSame(1.0, $character->stat('hitpoints_max')->value, 'a character is never born dead');
        $this->assertSame(0.0, $character->stat('synaptic_integrity_max')->value);
    }

    // ---- the page ---------------------------------------------------------

    public function test_the_creation_screen_shows_both_readouts(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        $response->assertOk()
            ->assertSee(Text::get('character.derived.section'))
            ->assertSee(Text::get('character.derived_names.hitpoints_max').' ('.Text::get('character.abbreviations.hitpoints_max').')')
            ->assertSee(Text::get('character.derived_names.synaptic_integrity_max').' ('.Text::get('character.abbreviations.synaptic_integrity_max').')')
            ->assertSee('id="derived-hitpoints_max"', false)
            ->assertSee('id="derived-synaptic_integrity_max"', false);
    }

    /**
     * The browser recomputes these on every slider move, so the formula would
     * otherwise exist twice. It is shipped as data instead — this asserts the
     * page carries exactly what config declares, so the two cannot drift.
     */
    public function test_the_page_ships_the_formulas_rather_than_restating_them(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        foreach (config('stats.formulas') as $statId => $formula) {
            $response->assertSee(json_encode($formula), false);
        }

        $response->assertSee('Math.ceil((attributes[attribute] || 0) / stat.terms[attribute])', false);
    }

    /**
     * The browser applies archetype perks as a flattened (add, percent) pair,
     * which is a SUBSET of the resolver. If a live perk on a derived stat ever
     * uses multiplier or override, that subset would silently show the wrong
     * number — so fail here instead.
     */
    public function test_no_live_perk_on_a_derived_stat_uses_an_op_the_browser_cannot_apply(): void
    {
        $registry = StatRegistry::fromConfig();
        $derived = Character::derivedStats();
        $checked = 0;

        foreach (Archetype::roster()->keys() as $id) {
            foreach ((new ArchetypePerks((int) $id))->modifiers() as $modifier) {
                foreach ($registry->matching($modifier->target) as $statId) {
                    if (! in_array($statId, $derived, true)) {
                        continue;
                    }

                    $checked++;
                    $this->assertContains(
                        $modifier->op,
                        [Op::Add, Op::PercentageOfBase],
                        "perk modifier on [{$statId}] uses [{$modifier->op->value}], which the ".
                        'creation screen cannot apply client-side'
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'expected at least Scar tissue to be checked');
    }
}
