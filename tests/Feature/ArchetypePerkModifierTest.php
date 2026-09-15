<?php

namespace Tests\Feature;

use App\Models\Archetype;
use App\Models\ArchetypePerk;
use App\Models\Character;
use App\Models\User;
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

    private const SOLO = 2;

    private const NETRUNNER = 1;

    /** The label is on the perk's row; nothing else knows it. */
    private function perkLabel(string $key): string
    {
        return ArchetypePerk::query()->where('key', $key)->value('label');
    }

    public function test_the_perk_produces_a_real_modifier(): void
    {
        $modifiers = (new ArchetypePerks(self::SOLO))->modifiers();

        $this->assertCount(1, $modifiers, 'only scar_tissue is migrated so far');

        $modifier = $modifiers[0];
        $this->assertSame(Op::PercentageOfBase, $modifier->op);
        $this->assertSame(5.0, $modifier->amount);
        $this->assertSame('hitpoints_max', $modifier->target->id);
        $this->assertSame($this->perkLabel('scar_tissue'), $modifier->source);
    }

    public function test_display_only_perks_contribute_nothing(): void
    {
        // The Crim carries two perks: scar_tissue is live, damage_taken is the
        // retained display-only example. Only one reaches the resolver.
        $perks = Archetype::fromRoster(self::SOLO)->perks;

        $this->assertCount(2, $perks);
        $this->assertCount(1, $perks->filter->isLive());
        $this->assertCount(1, (new ArchetypePerks(self::SOLO))->modifiers());
    }

    public function test_an_archetype_with_no_perks_contributes_nothing(): void
    {
        $this->assertSame([], (new ArchetypePerks(3))->modifiers());   // fixer
    }

    public function test_the_netrunner_perk_targets_synaptic_integrity(): void
    {
        $modifiers = (new ArchetypePerks(self::NETRUNNER))->modifiers();

        $this->assertCount(1, $modifiers);
        $this->assertSame('synaptic_integrity_max', $modifiers[0]->target->id);
        $this->assertSame($this->perkLabel('grey_matter'), $modifiers[0]->source);
    }

    public function test_grey_matter_raises_synaptic_integrity_by_five_percent(): void
    {
        // SI = ceil(20/2)*4 = 40, +5% = 42.
        $runner = $this->character(self::NETRUNNER, [
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

        $solo = $this->character(self::SOLO, $attrs);
        $runner = $this->character(self::NETRUNNER, $attrs);

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
        $solo = $this->character(self::SOLO, ['meat' => 20, 'muscle' => 10, 'focus' => 10]);

        $hp = $solo->stat('hitpoints_max');

        $this->assertSame(30.0, $hp->base);
        $this->assertEqualsWithDelta(31.5, $hp->raw, 0.0001);
        $this->assertSame(31.0, $hp->value, 'floored, per the stat definition');
    }

    public function test_another_archetype_gets_the_base_value(): void
    {
        // The Deck Jockey has a live perk, but it targets synaptic_integrity_max,
        // so hit points are untouched.
        $runner = $this->character(self::NETRUNNER, ['meat' => 20, 'muscle' => 10, 'focus' => 10]);

        $this->assertSame(30.0, $runner->stat('hitpoints_max')->value);
    }

    public function test_it_does_not_touch_synaptic_integrity(): void
    {
        $solo = $this->character(self::SOLO, ['focus' => 10, 'memory' => 10, 'intellect' => 10, 'meat' => 10]);

        // SI = 5+5+5+5 = 20, unmodified: Scar tissue targets hitpoints_max only.
        $this->assertSame(20.0, $solo->stat('synaptic_integrity_max')->value);
        $this->assertSame([], $solo->stat('synaptic_integrity_max')->steps);
    }

    public function test_the_breakdown_names_the_perk(): void
    {
        $solo = $this->character(self::SOLO, ['meat' => 20]);

        $steps = $solo->stat('hitpoints_max')->steps;

        $this->assertSame('percentage_of_base', $steps[0]->op);
        $this->assertSame([$this->perkLabel('scar_tissue')], $steps[0]->sources);
    }

    public function test_the_perk_scales_with_the_base_it_modifies(): void
    {
        $weak = $this->character(self::SOLO);                                             // all zero
        $strong = $this->character(self::SOLO, ['meat' => 50, 'muscle' => 50, 'focus' => 50]);

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
