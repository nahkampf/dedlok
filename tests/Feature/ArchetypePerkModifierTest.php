<?php

namespace Tests\Feature;

use App\Models\Archetype;
use App\Models\ArchetypePerk;
use App\Models\Character;
use App\Models\User;
use App\Stats\Modifier;
use App\Stats\Op;
use App\Stats\Sources\ArchetypePerks;
use App\Stats\StatRegistry;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Scar tissue" is the first archetype perk routed through the real modifier
 * pipeline. These tests pin both halves: that it produces a Modifier the
 * Resolver acts on, and that the card text is generated from that same
 * Modifier rather than written twice.
 */
class ArchetypePerkModifierTest extends TestCase
{
    use RefreshDatabase;

    private const CRIM = 2;

    private const DECK_JOCKEY = 1;

    /** The label is on the perk's row; nothing else knows it. */
    private function perkLabel(string $key): string
    {
        return ArchetypePerk::query()->where('key', $key)->value('label');
    }

    public function test_the_perk_produces_a_real_modifier(): void
    {
        $modifiers = (new ArchetypePerks(self::CRIM))->modifiers();

        $this->assertCount(2, $modifiers, 'both of the Crim\'s perks are live');

        $modifier = collect($modifiers)->firstWhere(
            fn (Modifier $candidate): bool => $candidate->target->id === 'hitpoints_max',
        );
        $this->assertNotNull($modifier);
        $this->assertSame(Op::PercentageOfBase, $modifier->op);
        $this->assertSame(5.0, $modifier->amount);
        $this->assertSame('hitpoints_max', $modifier->target->id);
        $this->assertSame($this->perkLabel('scar_tissue'), $modifier->source);
    }

    /**
     * Hard knocks: the Crim is hard to hurt in a FIGHT, which is a different
     * thing from being hard to hurt by ICE. The tag set is what says so — every
     * origin, one resource — and it is the first perk in the game to target a
     * set rather than a stat.
     */
    public function test_the_crim_softens_physical_damage_only(): void
    {
        $modifier = collect((new ArchetypePerks(self::CRIM))->modifiers())
            ->firstWhere(fn (Modifier $candidate): bool => $candidate->target->id === null);

        $this->assertNotNull($modifier, 'the perk targets a tag set, not a stat id');
        $this->assertSame(Op::PercentageOfBase, $modifier->op);
        $this->assertSame(-5.0, $modifier->amount);
        $this->assertSame('damage+hitpoints', $modifier->target->nameKey());

        $matched = app(StatRegistry::class)->matching($modifier->target);

        sort($matched);
        $this->assertSame([
            // The eight damage types (COMBAT.md) are body damage too, and `neural` as well: the cross
            // product is per (resource, origin) and does not ask what deals which.
            'damage.hitpoints.blunt',
            'damage.hitpoints.burnout',
            'damage.hitpoints.chemical',
            'damage.hitpoints.electrical',
            'damage.hitpoints.encounter',
            'damage.hitpoints.fragmentation',
            'damage.hitpoints.ice',
            'damage.hitpoints.neural',
            'damage.hitpoints.overdose',
            'damage.hitpoints.penetrating',
            'damage.hitpoints.readiness_loss',
            // The cross product is per (resource, origin) and does not ask
            // whether anything deals that pair — a cancellation only ever
            // costs Synaptic Integrity, and the perk still matches the
            // physical half of the origin because a tag set is not a guess
            // about what exists.
            'damage.hitpoints.run_cancelled',
            'damage.hitpoints.slashing',
            'damage.hitpoints.thermal',
        ], $matched, 'every way of being hurt in the body, and nothing in the mind');
    }

    /**
     * NOTHING SHIPS AS DISPLAY-ONLY ANY MORE, and the shape is kept anyway —
     * content may want a perk that describes itself without doing anything, and
     * a mechanism with no user rots unless something holds it down. This is
     * that something: a perk row that exists only here.
     */
    public function test_a_display_only_perk_renders_and_contributes_nothing(): void
    {
        ArchetypePerk::query()->create([
            'archetype_id' => self::CRIM,
            'key' => 'looks_the_part',
            'label' => 'Looks the part',
            'display_modifier' => -15,
            'display_unit' => 'percent',
            'position' => 9,
        ]);

        Archetype::forgetRoster();

        $perks = Archetype::fromRoster(self::CRIM)->perks;

        $this->assertCount(3, $perks);
        $this->assertCount(2, $perks->filter->isLive());
        $this->assertFalse($perks->firstWhere('key', 'looks_the_part')->isLive());

        // Two live perks reach the resolver. The third is scenery.
        $this->assertCount(2, (new ArchetypePerks(self::CRIM))->modifiers());
    }

    public function test_an_archetype_with_no_perks_contributes_nothing(): void
    {
        $this->assertSame([], (new ArchetypePerks(3))->modifiers());   // fixer
    }

    public function test_the_deck_jockey_perk_targets_synaptic_integrity(): void
    {
        $modifiers = (new ArchetypePerks(self::DECK_JOCKEY))->modifiers();

        $this->assertCount(1, $modifiers);
        $this->assertSame('synaptic_integrity_max', $modifiers[0]->target->id);
        $this->assertSame($this->perkLabel('grey_matter'), $modifiers[0]->source);
    }

    public function test_grey_matter_raises_synaptic_integrity_by_five_percent(): void
    {
        // SI = ceil(20/2)*4 = 40, +5% = 42.
        $runner = $this->character(self::DECK_JOCKEY, [
            'focus' => 20, 'memory' => 20, 'intellect' => 20, 'meat' => 20,
        ]);

        $si = $runner->stat('synaptic_integrity_max');

        $this->assertSame(40.0, $si->base);
        $this->assertSame(42.0, $si->value);
        $this->assertSame([$this->perkLabel('grey_matter')], $si->steps[0]->sources);
    }

    public function test_each_live_perk_affects_only_its_own_stat(): void
    {
        $attrs = ['meat' => 20, 'muscle' => 20, 'focus' => 20, 'memory' => 20, 'intellect' => 20];

        $solo = $this->character(self::CRIM, $attrs);
        $runner = $this->character(self::DECK_JOCKEY, $attrs);

        // The Crim boosts HP only; the Deck Jockey boosts SI only.
        $this->assertSame([], $solo->stat('synaptic_integrity_max')->steps);
        $this->assertSame([], $runner->stat('hitpoints_max')->steps);
        $this->assertNotSame([], $solo->stat('hitpoints_max')->steps);
        $this->assertNotSame([], $runner->stat('synaptic_integrity_max')->steps);
    }

    public function test_a_character_with_no_archetype_has_no_modifiers(): void
    {
        $this->assertSame([], (new ArchetypePerks(null))->modifiers());
        $this->assertSame([], (new ArchetypePerks(9999))->modifiers());
    }

    /**
     * Every attribute is pinned explicitly: the factory rolls them randomly,
     * and hitpoints_max reads meat, muscle AND focus, so leaving any of them
     * to chance would make this flaky.
     *
     * @param  array<string, int>  $overrides
     */
    private function character(int $archetype, array $overrides = []): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 0),
            ['archetype' => $archetype],
            $overrides,
        ));
    }

    public function test_it_raises_max_hitpoints_by_five_percent(): void
    {
        // HP = meat 20 + ceil(muscle 10/2) + ceil(focus 10/2) = 30. +5% = 31.5.
        $solo = $this->character(self::CRIM, ['meat' => 20, 'muscle' => 10, 'focus' => 10]);

        $hp = $solo->stat('hitpoints_max');

        $this->assertSame(30.0, $hp->base);
        $this->assertEqualsWithDelta(31.5, $hp->raw, 0.0001);
        $this->assertSame(31.5, $hp->value, 'kept to two decimals, per the stat definition');
    }

    public function test_another_archetype_gets_the_base_value(): void
    {
        // The Deck Jockey has a live perk, but it targets synaptic_integrity_max,
        // so hit points are untouched.
        $runner = $this->character(self::DECK_JOCKEY, ['meat' => 20, 'muscle' => 10, 'focus' => 10]);

        $this->assertSame(30.0, $runner->stat('hitpoints_max')->value);
    }

    public function test_it_does_not_touch_synaptic_integrity(): void
    {
        $solo = $this->character(self::CRIM, ['focus' => 10, 'memory' => 10, 'intellect' => 10, 'meat' => 10]);

        // SI = 5+5+5+5 = 20, unmodified: Scar tissue targets hitpoints_max only.
        $this->assertSame(20.0, $solo->stat('synaptic_integrity_max')->value);
        $this->assertSame([], $solo->stat('synaptic_integrity_max')->steps);
    }

    public function test_the_breakdown_names_the_perk(): void
    {
        $solo = $this->character(self::CRIM, ['meat' => 20]);

        $steps = $solo->stat('hitpoints_max')->steps;

        $this->assertSame('percentage_of_base', $steps[0]->op);
        $this->assertSame([$this->perkLabel('scar_tissue')], $steps[0]->sources);
    }

    public function test_the_perk_scales_with_the_base_it_modifies(): void
    {
        $weak = $this->character(self::CRIM);                                             // all zero
        $strong = $this->character(self::CRIM, ['meat' => 50, 'muscle' => 50, 'focus' => 50]);

        // 0 +5% = 0, but hitpoints_max floors at 1.
        $this->assertSame(1.0, $weak->stat('hitpoints_max')->value);

        // 50 + 25 + 25 = 100, +5% = 105.
        $this->assertSame(105.0, $strong->stat('hitpoints_max')->value);
    }

    public function test_max_hitpoints_cannot_be_driven_below_one(): void
    {
        $stat = app(StatRegistry::class)->get('hitpoints_max');

        // Answers MECHANICS.md open question 2: losing an implant must not kill.
        $this->assertSame(1.0, $stat->min);
        $this->assertSame(1.0, $stat->clamp(-500.0));
    }

    public function test_the_card_effect_is_generated_from_the_modifier(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('character.create'))
            ->assertOk()
            ->assertSee($this->perkLabel('scar_tissue'))
            ->assertSee('+5% '.Text::get('character.names.hitpoints_max'), false);
    }
}
