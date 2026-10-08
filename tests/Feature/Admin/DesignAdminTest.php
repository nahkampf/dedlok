<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Design;
use App\Models\Item;
use App\Models\User;
use App\Programs\ProgramKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** An item is a design because a `designs` row points at it; the item editor writes that row and its lines. */
final class DesignAdminTest extends TestCase
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
        $script = Item::query()->where('slug', 'dummy-temporary-program')->firstOrFail();

        return array_merge([
            'slug' => 'a_recipe', 'name' => 'A recipe', 'flavor' => null, 'container' => Item::CONTAINER_DATA, 'tier' => 1, 'rarity' => 'common',
            'stackable' => '1', 'use_op' => '', 'use_skill_id' => '', 'weight' => '0', 'size' => 0.2, 'capacity' => 0, 'accepts' => '',
            'base_cost' => 0, 'on_market' => '0', 'seed_quantity' => 0,
            'hardware' => '', 'variant' => '', 'slots' => [], 'program_kind' => '', 'drug_family' => '',
            'design_product' => (string) $script->id,
            'design_seconds' => 300,
            'design_single_use' => '1',
            'design_skill_id' => '',
            'design_skill_level' => '',
            'design_lines' => [
                ['item_id' => (string) Item::query()->where('slug', 'memory-dump')->value('id'), 'quantity' => 2, 'grade_min' => 3, 'os_slug' => '', 'owner_id' => '', 'system_type' => '', 'ice_min' => '', 'ids_min' => '', 'grade_max' => ''],
                ['item_id' => '', 'quantity' => '', 'grade_min' => '', 'grade_max' => '', 'os_slug' => '', 'owner_id' => '', 'system_type' => '', 'ice_min' => '', 'ids_min' => ''],
            ],
        ], $overrides);
    }

    public function test_an_item_becomes_a_design_by_naming_what_it_builds_and_blank_rows_are_ignored(): void
    {
        $this->actingAs($this->admin())->post(route('admin.items.store'), $this->payload())->assertSessionHasNoErrors();

        $design = Design::query()->whereHas('item', fn ($q) => $q->where('slug', 'a_recipe'))->sole();

        $this->assertSame(300, $design->craft_seconds);
        $this->assertTrue($design->single_use);
        $this->assertCount(1, $design->components);
        $this->assertSame(3, $design->components[0]->grade_min);
        $this->assertNull($design->components[0]->os_slug);
    }

    public function test_clearing_the_product_makes_it_an_ordinary_item_again(): void
    {
        $this->actingAs($this->admin())->post(route('admin.items.store'), $this->payload());
        $item = Item::query()->where('slug', 'a_recipe')->firstOrFail();

        $payload = $this->payload(['design_product' => '', 'design_lines' => [], 'design_skill_level' => '1']);
        unset($payload['slug']);

        $this->actingAs($this->admin())->put(route('admin.items.update', $item), $payload)->assertSessionHasNoErrors();

        $this->assertSame(0, Design::query()->where('item_id', $item->id)->count());
    }

    public function test_it_will_not_build_a_kind_nobody_can_be_taught_to_craft(): void
    {
        $evasion = Item::factory()->program(ProgramKind::Evasion)->create();

        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->payload(['design_product' => (string) $evasion->id]))
            ->assertSessionHasErrors('design_product');
    }

    public function test_a_design_needs_a_time_and_at_least_one_component(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->payload(['design_seconds' => '', 'design_lines' => []]))
            ->assertSessionHasErrors(['design_seconds', 'design_lines']);
    }
}
