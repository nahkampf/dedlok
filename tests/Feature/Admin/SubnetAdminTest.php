<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Cyberspace\Grid;
use App\Cyberspace\GridSeeder;
use App\Cyberspace\RaidLedger;
use App\Cyberspace\SystemType;
use App\Models\Entity;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Subnets screen and subnet generation (CYBERSPACE.md, *Subnets*, step 4).
 */
final class SubnetAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function form(array $over = []): array
    {
        return array_merge([
            'name' => 'Military', 'slug' => 'mil', 'prefix' => 'mil', 'size' => 12, 'entry_x' => 6, 'entry_y' => 6,
            'flag' => 'subnet_mil_access', 'infiltrate' => 1,
            'grade_min' => 6, 'grade_max' => 8, 'occupancy' => 30,
        ], $over);
    }

    private function create(array $over = []): int
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('admin.subnets.store'), $this->form($over))->assertSessionHasNoErrors();

        return (int) DB::table('grids')->where('slug', 'mil')->value('id');
    }

    public function test_only_an_admin_gets_in(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.subnets.index'))->assertForbidden();
    }

    public function test_creating_one_makes_the_grid_and_a_linked_pair_of_grade_nine_gateways(): void
    {
        $id = $this->create();

        $inner = System::query()->where('grid_id', $id)->firstOrFail();
        $outer = $inner->linksTo;

        $this->assertSame(SystemType::Gateway, $inner->type);
        $this->assertSame([6, 6], [$inner->x, $inner->y]);
        $this->assertMatchesRegularExpression('/^mil:[0-9A-F]{4}$/', $inner->address);
        $this->assertSame(9, $inner->grade);
        $this->assertSame(1, (int) $outer->grid_id);
        $this->assertSame($inner->id, $outer->links_to_system_id);
        $this->assertSame([['kind' => 'flag', 'flag' => 'subnet_mil_access'], ['kind' => 'infiltrate']], $outer->entry_rules);
        $this->assertSame([], $inner->entry_rules ?? []);
        $this->assertNull($inner->ice_id);
        $this->assertSame(0, (int) $inner->ids_detection);
    }

    public function test_the_screens_draw(): void
    {
        $id = $this->create();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.subnets.index'))->assertOk()->assertSee('Military');
        $this->actingAs($admin)->get(route('admin.subnets.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.subnets.edit', $id))->assertOk()->assertSee('subnet_mil_access');
        $this->actingAs($admin)->get(route('admin.index'))->assertOk();
    }

    public function test_a_prefix_must_be_two_to_five_lower_case_letters_and_unique(): void
    {
        $this->create();
        $admin = User::factory()->create(['is_admin' => true]);

        foreach (['m', 'toolong', 'MIL', 'mi1', 'mil'] as $bad) {
            $this->actingAs($admin)->post(route('admin.subnets.store'), $this->form(['slug' => 'x'.$bad, 'prefix' => $bad]))->assertSessionHasErrors('prefix');
        }
    }

    public function test_generating_fills_the_subnet_to_its_spec(): void
    {
        Entity::query()->create(['name' => 'Acme', 'category' => 'corporation', 'interactable' => false, 'business' => 'x', 'description' => 'x']);
        $id = $this->create(['types' => ['silo']]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('admin.subnets.generate', $id))->assertSessionHas('status');

        $made = System::query()->where('grid_id', $id)->where('type', '!=', SystemType::Gateway->value)->get();
        $this->assertSame((int) round(144 * 30 / 100) - 1, $made->count());
        $this->assertTrue($made->every(fn (System $s): bool => $s->grade >= 6 && $s->grade <= 8));
        $this->assertTrue($made->every(fn (System $s): bool => $s->type === SystemType::Silo));
        $this->assertTrue($made->every(fn (System $s): bool => preg_match('/^mil:[0-9A-F]{4}$/', $s->address) === 1 && $s->x < 12 && $s->y < 12));
        $this->assertSame(0, System::query()->where('grid_id', 1)->count() - 1, 'nothing landed on the main grid except the partner gateway');
    }

    public function test_a_subnet_system_relocates_within_its_own_subnet_and_keeps_the_prefix(): void
    {
        $id = $this->create();
        GridSeeder::forGrid($id)->run();
        $system = System::query()->where('grid_id', $id)->where('type', '!=', SystemType::Gateway->value)->firstOrFail();
        $old = $system->address;

        $this->assertTrue(RaidLedger::fromConfig()->relocate($system));

        $system->refresh();
        $this->assertSame($id, (int) $system->grid_id);
        $this->assertNotSame($old, $system->address);
        $this->assertMatchesRegularExpression('/^mil:[0-9A-F]{4}$/', $system->address);
        $this->assertTrue(Grid::forId($id)->contains($system->position()));
    }

    public function test_a_gateway_never_relocates(): void
    {
        $id = $this->create();
        $gate = System::query()->where('grid_id', $id)->firstOrFail();

        $this->assertFalse(RaidLedger::fromConfig()->relocate($gate));
    }

    public function test_deleting_removes_the_grid_its_systems_and_the_partner_gateway(): void
    {
        $id = $this->create();
        GridSeeder::forGrid($id)->run();

        $this->actingAs(User::factory()->create(['is_admin' => true]))->delete(route('admin.subnets.destroy', $id))->assertRedirect(route('admin.subnets.index'));

        $this->assertSame(0, DB::table('grids')->where('id', $id)->count());
        $this->assertSame(0, System::query()->where('type', SystemType::Gateway->value)->count());
    }
}
