<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Combat\ArmourSlot;
use App\Models\Armour;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Armour panel of the item editor: which slots a piece covers, and everything it refuses.
 */
final class ArmourEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'ceramic_plate',
            'name' => 'Ceramic plate',
            'container' => Item::CONTAINER_PHYSICAL,
            'tier' => 1,
            'rarity' => 'common',
            'base_cost' => 100,
            'weight' => 1,
            'size' => 1,
            'capacity' => 0,
            'seed_quantity' => 0,
            'armour_slots' => ['torso'],
            'modifiers' => [
                ['op' => 'percentage_of_base', 'amount' => '-60', 'target' => 'damage.hitpoints.penetrating', 'carried' => ''],
                ['op' => 'percentage_of_base', 'amount' => '-10', 'target' => 'damage.hitpoints.blunt', 'carried' => ''],
            ],
        ], $overrides);
    }

    private function save(array $payload): TestResponse
    {
        return $this->actingAs($this->admin())->post(route('admin.items.store'), $payload);
    }

    public function test_a_set_is_one_item_covering_the_slots_that_are_ticked(): void
    {
        $this->save($this->payload(['slug' => 'jacket', 'armour_slots' => ['torso', 'arm_left', 'arm_right']]))->assertRedirect();

        $item = Item::query()->where('slug', 'jacket')->sole();

        $this->assertTrue($item->isArmour());
        $this->assertSame(['torso', 'arm_left', 'arm_right'], $item->armour->slots);
        $this->assertSame([ArmourSlot::Torso, ArmourSlot::ArmLeft, ArmourSlot::ArmRight], $item->armour->covers());
        $this->assertCount(2, $item->modifiers, 'several resistances, one per type');
    }

    public function test_the_editor_shows_the_slots_ticked(): void
    {
        $this->save($this->payload(['armour_slots' => ['head', 'leg_right']]))->assertRedirect();
        $item = Item::query()->where('slug', 'ceramic_plate')->sole();

        $html = $this->actingAs($this->admin())->get(route('admin.items.edit', $item))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="head"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/value="leg_right"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="torso"[^>]*checked/', $html);
        $this->assertStringContainsString('arm_left', $html, 'every slot is offered');
    }

    public function test_ticking_nothing_is_not_armour(): void
    {
        $this->save($this->payload(['armour_slots' => []]))->assertRedirect();

        $this->assertFalse(Item::query()->where('slug', 'ceramic_plate')->sole()->isArmour());
    }

    public function test_saving_again_with_no_slots_removes_the_armour_row(): void
    {
        $this->save($this->payload())->assertRedirect();
        $item = Item::query()->where('slug', 'ceramic_plate')->sole();
        $this->assertNotNull(Armour::query()->where('item_id', $item->id)->first());

        $update = $this->payload(['armour_slots' => []]);
        unset($update['slug']);
        $this->actingAs($this->admin())->put(route('admin.items.update', $item), $update)->assertRedirect();

        $this->assertNull(Armour::query()->where('item_id', $item->id)->first());
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): iterable
    {
        yield 'a stackable piece (a worn row cannot point at a pile)' => [['stackable' => '1'], 'stackable'];
        yield 'a data item' => [['container' => Item::CONTAINER_DATA], 'container'];
        yield 'a slot that is not one' => [['armour_slots' => ['tail']], 'armour_slots.0'];
        yield 'a slot twice' => [['armour_slots' => ['torso', 'torso']], 'armour_slots.0'];
        yield 'also software' => [['program_kind' => 'icebreaker', 'qflops_cost' => 1, 'memory_cost' => 1, 'storage_size' => 1], 'program_kind'];
        yield 'also a drug' => [['drug_family' => 'stimulant', 'duration_minutes' => 10, 'addictive' => 1, 'tolerance_gain' => 1], 'drug_family'];
        yield 'also an implant' => [['implant_region' => 'spine'], 'armour_slots'];
        yield 'also deck hardware' => [['hardware' => 'cpu'], 'armour_slots'];
        yield 'a resistance carrying a condition of its own' => [[
            'modifiers' => [['op' => 'percentage_of_base', 'amount' => '-60', 'target' => 'damage.hitpoints.penetrating', 'carried' => json_encode(['condition' => ['when' => 'jacked_in']])]],
        ], 'modifiers'];
    }

    /**
     * @dataProvider refusals
     *
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('refusals')]
    public function test_it_refuses(array $override, string $field): void
    {
        $this->save($this->payload($override))->assertSessionHasErrors($field);

        $this->assertSame(0, Item::query()->where('slug', 'ceramic_plate')->count());
    }
}
