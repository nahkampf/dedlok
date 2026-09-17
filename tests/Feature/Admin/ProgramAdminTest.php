<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Decks\Hardware;
use App\Models\Item;
use App\Models\Program;
use App\Models\Skill;
use App\Models\User;
use App\Programs\ProgramKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authoring software in the item editor.
 *
 * The same shape the cyberdeck editor has: an item is a program because a
 * `programs` row points at it, the editor writes that row, and everything the
 * schema cannot check about it lives in ItemRequest.
 */
final class ProgramAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'ghostwire',
            'name' => 'Ghostwire',
            'flavor' => null,
            'container' => Item::CONTAINER_DATA,
            'tier' => 2,
            'rarity' => 'rare',
            'stackable' => '1',
            'use_op' => '',
            'use_skill_id' => '',
            'weight' => '0',
            'size' => 4,
            'capacity' => 0,
            'accepts' => '',
            'base_cost' => 9_000,
            'on_market' => '1',
            'seed_quantity' => 4,

            'hardware' => '',
            'variant' => '',
            'power_draw' => '',
            'thermal_load' => '',
            'qflops' => '',
            'quantabits' => '',
            'bandwidth' => '',
            'watts' => '',
            'cooling' => '',
            'slots' => [],

            'program_kind' => ProgramKind::Evasion->value,
            'qflops_cost' => 12,
            'memory_cost' => 8,
            'storage_size' => 4,
            'background_process' => '0',
            'program_modifiers' => '',
        ], $overrides);
    }

    public function test_an_item_becomes_a_program_by_being_given_a_kind(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->payload())
            ->assertRedirect();

        $program = Item::where('slug', 'ghostwire')->firstOrFail()->program;

        $this->assertNotNull($program);
        $this->assertSame(ProgramKind::Evasion, $program->kind);
        $this->assertSame(12, $program->qflops_cost);
        $this->assertSame(8, $program->memory_cost);
        $this->assertSame(4, $program->storage_size);
        $this->assertFalse($program->background_process);
        $this->assertNull($program->modifiers, 'a program that only gates modifies nothing');
    }

    public function test_modifiers_arrive_as_json_and_are_stored_as_data(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->payload([
                'program_modifiers' => json_encode([
                    ['op' => 'add', 'amount' => 12, 'target' => ['id' => 'focus']],
                ]),
            ]))
            ->assertRedirect();

        $program = Item::where('slug', 'ghostwire')->firstOrFail()->program;

        $this->assertCount(1, $program->modifiers);
        $this->assertCount(1, $program->modifiers());
        $this->assertSame(12.0, $program->modifiers()[0]->amount);
    }

    /**
     * Validated by BUILDING each one. A shape that parses as JSON but names an
     * op that does not exist is a modifier that throws the first time somebody
     * runs the program — on their screen, hours later, with nothing pointing
     * back at the editor.
     */
    public function test_a_modifier_naming_an_op_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->payload([
                'program_modifiers' => json_encode([
                    ['op' => 'pct_base', 'amount' => 5, 'target' => ['id' => 'focus']],
                ]),
            ]))
            ->assertSessionHasErrors('program_modifiers');

        $this->assertDatabaseMissing('items', ['slug' => 'ghostwire']);
    }

    public function test_a_modifier_with_a_misspelled_predicate_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->payload([
                'program_modifiers' => json_encode([
                    [
                        'op' => 'add', 'amount' => 5, 'target' => ['id' => 'focus'],
                        'condition' => ['when' => 'si_belo_pct', 'args' => ['pct' => 30]],
                    ],
                ]),
            ]))
            ->assertSessionHasErrors('program_modifiers');
    }

    public function test_deck_hardware_cannot_also_be_a_program(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->payload([
                'container' => Item::CONTAINER_PHYSICAL,
                'hardware' => Hardware::Cpu->value,
                'stackable' => '',
            ]))
            ->assertSessionHasErrors('program_kind');
    }

    /** Both are data with a job, and `use_op` already says which job. */
    public function test_a_skillbook_cannot_also_be_a_program(): void
    {
        $skill = Skill::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->payload([
                'name' => null,
                'use_op' => Item::USE_INJECT_SKILL,
                'use_skill_id' => $skill->id,
            ]))
            ->assertSessionHasErrors('program_kind');
    }

    public function test_costs_are_prohibited_on_something_that_is_not_a_program(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->payload([
                'program_kind' => '',
                'qflops_cost' => 5,
            ]))
            ->assertSessionHasErrors('qflops_cost');
    }

    public function test_costs_are_required_on_something_that_is(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->payload(['memory_cost' => '']))
            ->assertSessionHasErrors('memory_cost');
    }

    /**
     * The editor always clears the side an item stopped being — the same
     * decision HardwareEditor takes. An item that is no longer software would
     * otherwise still be running on somebody's deck.
     */
    public function test_an_item_that_stops_being_a_program_loses_its_row(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.items.store'), $this->payload());
        $item = Item::where('slug', 'ghostwire')->firstOrFail();
        $this->assertNotNull($item->program);

        $this->actingAs($admin)
            ->put(route('admin.items.update', $item), $this->payload([
                'slug' => null,
                'program_kind' => '',
                'qflops_cost' => '',
                'memory_cost' => '',
                'storage_size' => '',
                'program_modifiers' => '',
            ]))
            ->assertRedirect();

        // Scoped to THIS item rather than counting the table: a fresh database
        // ships the scanners, so "no programs exist" stopped being the same
        // statement as "this item stopped being one".
        $this->assertNull($item->fresh()->program);
        $this->assertDatabaseMissing('programs', ['item_id' => $item->id]);
    }

    public function test_editing_a_program_replaces_its_row_rather_than_adding_one(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.items.store'), $this->payload());
        $item = Item::where('slug', 'ghostwire')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.items.update', $item), $this->payload([
                'slug' => null,
                'program_kind' => ProgramKind::Decryptor->value,
                'qflops_cost' => 30,
                'background_process' => '1',
            ]))
            ->assertRedirect();

        $this->assertSame(1, Program::query()->where('item_id', $item->id)->count(), 'replaced, not added to');
        $this->assertSame(ProgramKind::Decryptor, $item->fresh()->program->kind);
        $this->assertSame(30, $item->fresh()->program->qflops_cost);
        $this->assertTrue($item->fresh()->program->background_process);
    }

    public function test_the_form_offers_every_kind(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.items.create'));

        $response->assertOk();

        foreach (ProgramKind::cases() as $kind) {
            $response->assertSee($kind->label());
        }
    }

    // ---- the listing -------------------------------------------------------

    /**
     * A PROGRAM'S KIND IS A GATE, not decoration — no amount of bonuses adds up
     * to having a running icebreaker — so a listing that only said "Data" left
     * every program in the game looking alike. With seventeen of them, that is
     * the difference between a screen you can tune from and one you cannot.
     */
    public function test_the_listing_says_what_kind_of_program_each_item_is(): void
    {
        $item = Item::factory()->create(['slug' => 'breaker', 'name' => 'Breaker', 'container' => 'data']);

        Program::query()->create([
            'item_id' => $item->id,
            'kind' => ProgramKind::Icebreaker->value,
            'qflops_cost' => 7,
            'memory_cost' => 4,
            'storage_size' => 2,
            'background_process' => false,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.items.index'))
            ->assertOk()
            ->assertSee(ProgramKind::Icebreaker->label())
            ->assertSee('7 qF', false);
    }

    /** And the list can be narrowed to one kind, the way it can to one hardware. */
    public function test_the_listing_filters_by_program_kind(): void
    {
        foreach ([ProgramKind::Icebreaker, ProgramKind::Analyser] as $kind) {
            $item = Item::factory()->create([
                'slug' => 'prog-'.$kind->value,
                'name' => ucfirst($kind->value).' One',
                'container' => 'data',
            ]);

            Program::query()->create([
                'item_id' => $item->id,
                'kind' => $kind->value,
                'qflops_cost' => 2,
                'memory_cost' => 2,
                'storage_size' => 1,
                'background_process' => true,
            ]);
        }

        $this->actingAs($this->admin())
            ->get(route('admin.items.index', ['program' => ProgramKind::Icebreaker->value]))
            ->assertOk()
            ->assertSee('Icebreaker One')
            ->assertDontSee('Analyser One');
    }
}
