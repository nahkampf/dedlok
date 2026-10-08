<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\CityRefusal;
use App\City\ShopKeeper;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\District;
use App\Models\Entity;
use App\Models\EntityBuy;
use App\Models\Exchange;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Location;
use App\Models\LocationBuy;
use App\Models\System;
use App\Standing\DeskBuyer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/** A buyer pays its percentage of a file's OWN value, sealed or decrypted, up to its limit. */
final class FileSalesTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Item $sealed;

    private Item $memory;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->sealed = Item::query()->where('slug', 'encrypted-data-dump')->firstOrFail();
        $this->memory = Item::query()->where('slug', 'memory-dump')->firstOrFail();
    }

    private function file(Item $item, int $sealed = 100, int $broken = 250): InventoryItem
    {
        return InventoryItem::create([
            'character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1,
            'extracted_file' => ['label' => 'Logs', 'size_qb' => 2, 'encryption_skill_id' => null, 'value_sealed' => $sealed, 'value_broken' => $broken, 'grade' => 3],
        ]);
    }

    private function place(): Location
    {
        return Location::create(['district_id' => District::startId(), 'slug' => 'fence', 'name' => 'Fence', 'map_x' => 50, 'map_y' => 50, 'discovery' => 'open']);
    }

    public function test_a_place_pays_each_file_its_own_value_by_state(): void
    {
        $place = $this->place();
        LocationBuy::create(['location_id' => $place->id, 'item_id' => $this->sealed->id, 'percent' => 60]);
        LocationBuy::create(['location_id' => $place->id, 'item_id' => $this->memory->id, 'percent' => 100]);
        $sealed = $this->file($this->sealed);
        $broken = $this->file($this->memory);
        $keeper = app(ShopKeeper::class);
        $before = (int) $this->character->fresh()->creds;

        $this->assertSame(60, $keeper->sell($this->character->fresh(), $place, $sealed->id, 1), '60% of the sealed 100');
        $this->assertSame(250, $keeper->sell($this->character->fresh(), $place, $broken->id, 1), 'all of the broken 250');
        $this->assertSame($before + 310, (int) $this->character->fresh()->creds);
    }

    public function test_a_desk_buys_by_entity_and_grade_and_shares_its_limit(): void
    {
        $entity = Entity::query()->interactable()->firstOrFail();
        $system = System::factory()->at(8, 8)->ownedBy($entity)->create(['type' => SystemType::Exchange]);
        $desk = Exchange::query()->create(['system_id' => $system->id, 'grade' => 2, 'agent' => 'Wren', 'min_standing' => 0]);
        EntityBuy::create(['entity_id' => $entity->id, 'item_id' => $this->memory->id, 'min_grade' => 2, 'max_grade' => 4, 'percent' => 50, 'max_quantity' => 1, 'window_started_at' => now()]);
        $buyer = app(DeskBuyer::class);
        $one = $this->file($this->memory);
        $two = $this->file($this->memory);

        $this->assertSame(125, $buyer->sell($this->character->fresh(), $desk->fresh(), $one->id, 1));
        $this->assertSame(CityRefusal::WontTakeMore, $buyer->sell($this->character->fresh(), $desk->fresh(), $two->id, 1));

        $this->travel(9)->hours();
        $this->assertSame(125, $buyer->sell($this->character->fresh(), $desk->fresh(), $two->id, 1));

        $elsewhere = Exchange::query()->create(['system_id' => System::factory()->at(9, 9)->ownedBy($entity)->create(['type' => SystemType::Exchange])->id, 'grade' => 5, 'agent' => 'Kit', 'min_standing' => 0]);
        $this->assertSame(CityRefusal::CannotSell, $buyer->sell($this->character->fresh(), $elsewhere->fresh(), $this->file($this->memory)->id, 1), 'grade 5 is outside 2-4');
    }
}
