<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Leeches;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Leech;
use App\Models\Program;
use App\Models\System;
use App\Programs\ProgramKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeechTest extends TestCase
{
    use RefreshDatabase;

    private function leecher(Character $c, int $copies = 2): void
    {
        $item = Item::query()->create(['slug' => 'storage-leecher', 'name' => 'Storage Leecher', 'container' => 'data', 'stackable' => true]);
        Program::query()->create(['item_id' => $item->id, 'kind' => ProgramKind::Leecher, 'storage_size' => 4.5, 'qflops_cost' => 0, 'memory_cost' => 0, 'background_process' => false]);
        InventoryItem::query()->create(['character_id' => $c->id, 'item_id' => $item->id, 'quantity' => $copies]);
    }

    public function test_a_leecher_feeds_the_fortress_and_one_per_node(): void
    {
        $c = Character::factory()->create();
        $fortress = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $host = System::factory()->at(2, 2)->create();
        $this->leecher($c);
        $leeches = new Leeches;

        $this->assertInstanceOf(Leech::class, $leeches->install($c, $host));
        $this->assertSame(Leeches::ALREADY, $leeches->install($c, $host));
        $this->assertSame(['compute' => 0.0, 'memory' => 0.0, 'storage' => 4.5], $leeches->totals($fortress));
        $this->assertSame(1, InventoryItem::query()->where('character_id', $c->id)->first()->quantity);
    }

    public function test_a_found_leecher_is_removed_and_stops_counting(): void
    {
        $c = Character::factory()->create();
        $fortress = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $host = System::factory()->at(2, 2)->create();
        $this->leecher($c);
        $leeches = new Leeches;
        $leeches->install($c, $host);

        $this->assertSame(1, $leeches->discover(fn (): int => 1));
        $this->assertSame(0.0, $leeches->totals($fortress)['storage']);
        $this->assertSame(0, Leech::query()->count());
    }
}
