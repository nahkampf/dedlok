<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\ModifierField;
use App\Models\Item;
use App\Models\Skill;
use App\Models\User;
use App\Programs\ProgramKind;
use App\Stats\SkillStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authoring effects without writing JSON.
 *
 * Four tables carry the same modifier list and until now every one of them was
 * filled in by hand — a textarea for software and a migration for everything
 * else, which is a content pipeline only a programmer can use for the half of
 * the game MECHANICS insists must be data.
 *
 * What these tests hold is that the rows a form posts and the definitions a
 * resolver reads are the same thing, and that the one key the editor does NOT
 * understand survives the round trip.
 */
final class ModifierEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** @return array<string, mixed> */
    private function itemPayload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'test_item',
            'name' => 'Test item',
            'container' => Item::CONTAINER_PHYSICAL,
            'tier' => 1,
            'rarity' => 'common',
            'base_cost' => 100,
            'weight' => 1,
            'size' => 1,
            'capacity' => 0,
            'seed_quantity' => 0,
        ], $overrides);
    }

    // ---- the vocabulary ----------------------------------------------------

    /**
     * A picker offers stats by id and tag sets by the name somebody gave them.
     * A set nobody has named cannot be rendered on a card, so offering it would
     * be offering an effect that cannot be described.
     */
    public function test_the_picker_offers_stats_and_named_groups(): void
    {
        $targets = ModifierField::targets();
        $flat = array_merge(...array_values(array_map('array_keys', $targets)));

        $this->assertContains('hitpoints_max', $flat);
        $this->assertContains('deck_qflops', $flat);
        $this->assertContains('damage+hitpoints', $flat, 'the named tag sets are choosable');
        $this->assertNotContains('damage+ice', $flat, 'and the unnamed ones are not');
    }

    // ---- items -------------------------------------------------------------

    public function test_an_item_can_be_given_effects_from_the_form(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->itemPayload([
                'implant_region' => 'spine',
                'modifiers' => [
                    ['op' => 'add', 'amount' => '5', 'target' => 'hitpoints_max', 'carried' => ''],
                    ['op' => 'percentage_of_base', 'amount' => '-10', 'target' => 'damage+hitpoints', 'carried' => ''],
                ],
            ]))
            ->assertRedirect();

        $item = Item::query()->where('slug', 'test_item')->sole();

        $this->assertSame('spine', $item->implant_region->value);
        $this->assertSame([
            ['op' => 'add', 'amount' => 5, 'target' => ['id' => 'hitpoints_max']],
            ['op' => 'percentage_of_base', 'amount' => -10, 'target' => ['all_of' => ['damage', 'hitpoints']]],
        ], $item->modifiers);
    }

    /** An item that grants nothing grants nothing: null, not an empty list. */
    public function test_empty_rows_are_dropped_and_nothing_becomes_null(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->itemPayload([
                'modifiers' => [
                    ['op' => '', 'amount' => '', 'target' => '', 'carried' => ''],
                ],
            ]))
            ->assertRedirect();

        $this->assertNull(Item::query()->where('slug', 'test_item')->sole()->modifiers);
    }

    /**
     * Judged by BUILDING each one, so the message names the row and the
     * reason rather than saying the shape is wrong somewhere.
     */
    /**
     * A MISTYPED STAT IS REFUSED RATHER THAN STORED. It would build, resolve,
     * match nothing and change nothing — for ever, in silence.
     */
    public function test_a_target_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->itemPayload([
                'modifiers' => [
                    ['op' => 'add', 'amount' => '5', 'target' => 'not_a_stat', 'carried' => ''],
                ],
            ]))
            ->assertSessionHasErrors('modifiers');

        $this->assertSame(0, Item::query()->where('slug', 'test_item')->count());
    }

    public function test_software_effects_come_through_the_same_editor(): void
    {
        // The check has to EXIST to be targeted: a stat is generated per skill
        // row, and a target that reaches nothing is refused below.
        Skill::factory()->create(['slug' => 'systemprofiling']);
        SkillStats::forget();

        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->itemPayload([
                'container' => Item::CONTAINER_DATA,
                'program_kind' => ProgramKind::Analyser->value,
                'qflops_cost' => 4,
                'memory_cost' => 2,
                'storage_size' => 1,
                'background_process' => '1',
                'program_modifiers' => [
                    ['op' => 'percentage_of_base', 'amount' => '15', 'target' => SkillStats::checkId('systemprofiling'), 'carried' => ''],
                ],
            ]))
            ->assertRedirect();

        $program = Item::query()->where('slug', 'test_item')->sole()->program;

        $this->assertSame(
            [['op' => 'percentage_of_base', 'amount' => 15, 'target' => ['id' => 'check.systemprofiling']]],
            $program->modifiers,
        );
    }

    // ---- skills, and the extra key they carry ------------------------------

    public function test_a_skill_can_be_given_effects_with_a_level(): void
    {
        $skill = Skill::factory()->create(['slug' => 'ghostwalk']);

        $this->actingAs($this->admin())
            ->put(route('admin.skills.update', $skill), [
                'title' => $skill->title,
                'category' => $skill->category,
                'rank' => $skill->rank,
                'primary_attribute' => $skill->primary_attribute,
                'secondary_attribute' => $skill->secondary_attribute,
                'base_cost' => $skill->base_cost,
                'modifiers' => [
                    ['op' => 'add', 'amount' => '3', 'target' => 'memory', 'level' => '2', 'carried' => ''],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(
            [['op' => 'add', 'amount' => 3, 'target' => ['id' => 'memory'], 'level' => 2]],
            $skill->fresh()->modifiers,
        );
    }

    // ---- what the editor does not understand -------------------------------

    /**
     * A CONDITION IS NOT DROPPED. The editor does not take one apart, so it
     * travels in a hidden field and comes back as it was — silently losing one
     * is the kind of damage nobody notices until a drug stops wearing off.
     */
    public function test_a_condition_survives_a_round_trip(): void
    {
        $stored = [[
            'op' => 'add',
            'amount' => 5,
            'target' => ['id' => 'hitpoints_max'],
            'condition' => ['predicate' => 'below_half_synaptic_integrity'],
        ]];

        $rows = ModifierField::rows($stored);

        $this->assertSame(['condition' => ['predicate' => 'below_half_synaptic_integrity']], $rows[0]['carried']);

        // What the form would post back, with the carried half as its hidden
        // field holds it.
        $posted = [[
            'op' => $rows[0]['op'],
            'amount' => $rows[0]['amount'],
            'target' => $rows[0]['target'],
            'carried' => json_encode($rows[0]['carried']),
        ]];

        $this->assertSame($stored, ModifierField::normalise($posted));
    }
}
