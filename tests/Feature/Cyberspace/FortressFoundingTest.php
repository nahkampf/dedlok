<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Fortresses;
use App\Cyberspace\FortressRefusal;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Program;
use App\Models\System;
use App\Programs\ProgramKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class FortressFoundingTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<array<string, mixed>>|null  $modifiers */
    private function kernel(Character $c, ?array $modifiers = null, string $slug = 'data-fortress-kernel'): InventoryItem
    {
        $item = Item::query()->firstOrCreate(['slug' => $slug], ['name' => 'Data Fortress Kernel', 'container' => 'data', 'stackable' => false, 'modifiers' => $modifiers]);
        Program::query()->firstOrCreate(['item_id' => $item->id], ['kind' => ProgramKind::Kernel]);

        return InventoryItem::query()->create(['character_id' => $c->id, 'item_id' => $item->id, 'quantity' => 1]);
    }

    public function test_a_kernel_is_spent_to_found_a_fortress_on_an_empty_square(): void
    {
        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.fortress.cap', 1);
        $c = Character::factory()->create(['grid_x' => 5, 'grid_y' => 5]);
        $kernel = $this->kernel($c);

        $result = (new Fortresses)->found($c, $kernel);

        $this->assertInstanceOf(System::class, $result);
        $this->assertSame(SystemType::Fortress, $result->type);
        $this->assertSame($c->id, (int) $result->owner_character_id);
        $this->assertDatabaseMissing('inventory_items', ['id' => $kernel->id]);
    }

    public function test_nobody_may_found_one_without_a_slot_granted(): void
    {
        Config::set('cyberspace.size', 16);
        $c = Character::factory()->create(['grid_x' => 5, 'grid_y' => 5]);

        $this->assertSame(FortressRefusal::AtTheCap, (new Fortresses)->found($c, $this->kernel($c)));
    }

    public function test_an_occupied_square_and_the_cap_refuse(): void
    {
        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.fortress.cap', 1);
        $c = Character::factory()->create(['grid_x' => 5, 'grid_y' => 5]);
        System::factory()->at(5, 5)->create();
        $this->assertSame(FortressRefusal::Occupied, (new Fortresses)->found($c, $this->kernel($c)));

        $c->forceFill(['grid_x' => 6, 'grid_y' => 6])->save();
        $first = (new Fortresses)->found($c->fresh(), InventoryItem::query()->where('character_id', $c->id)->first());
        $this->assertInstanceOf(System::class, $first);

        $c->forceFill(['grid_x' => 7, 'grid_y' => 7])->save();
        $second = Item::query()->where('slug', 'data-fortress-kernel')->first();
        $row = InventoryItem::query()->create(['character_id' => $c->id, 'item_id' => $second->id, 'quantity' => 1]);
        $this->assertSame(FortressRefusal::AtTheCap, (new Fortresses)->found($c->fresh(), $row));
    }

    public function test_any_kernel_founds_and_its_own_numbers_are_kept_on_the_fortress(): void
    {
        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.fortress.cap', 2);
        $c = Character::factory()->create(['grid_x' => 5, 'grid_y' => 5]);

        $micro = (new Fortresses)->found($c, $this->kernel($c, null, 'microkernel'));
        $this->assertSame([100, 0, 100], [$micro->kernelIntegrityMax(), $micro->kernelResistance(), $micro->kernelDemandPercent()]);

        $c->forceFill(['grid_x' => 6, 'grid_y' => 6])->save();
        $expanded = (new Fortresses)->found($c->fresh(), $this->kernel($c, [
            ['op' => 'percentage_of_base', 'amount' => 8, 'target' => ['id' => 'kernel_integrity']],
            ['op' => 'add', 'amount' => 6, 'target' => ['id' => 'kernel_resistance']],
            ['op' => 'percentage_of_base', 'amount' => 3, 'target' => ['id' => 'kernel_demand']],
        ], 'expanded-kernel'));

        $this->assertSame([108, 6, 103], [$expanded->kernelIntegrityMax(), $expanded->kernelResistance(), $expanded->kernelDemandPercent()]);
    }

    public function test_an_item_that_is_not_a_kernel_program_is_refused(): void
    {
        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.fortress.cap', 1);
        $c = Character::factory()->create(['grid_x' => 5, 'grid_y' => 5]);
        $item = Item::query()->create(['slug' => 'junk', 'name' => 'Junk', 'container' => 'data', 'stackable' => false]);
        $row = InventoryItem::query()->create(['character_id' => $c->id, 'item_id' => $item->id, 'quantity' => 1]);

        $this->assertSame(FortressRefusal::NotAKernel, (new Fortresses)->found($c, $row));
    }
}
