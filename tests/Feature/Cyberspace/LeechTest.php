<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Leeches;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Leech;
use App\Models\Program;
use App\Models\Skill;
use App\Models\System;
use App\Programs\ProgramKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LeechTest extends TestCase
{
    use RefreshDatabase;

    private function leecher(Character $c, int $copies = 2): void
    {
        $item = Item::query()->create(['slug' => 'storage-leecher', 'name' => 'Storage Leecher', 'container' => 'data', 'stackable' => true]);
        Program::query()->create(['item_id' => $item->id, 'kind' => ProgramKind::Leecher, 'storage_size' => 0.4, 'leech_resource' => 'storage', 'leech_amount' => 4.5, 'qflops_cost' => 0, 'memory_cost' => 0, 'background_process' => false]);
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

    public function test_a_fortress_draws_only_on_nodes_of_its_own_grid(): void
    {
        DB::table('grids')->insert(['id' => 2, 'slug' => 'mil', 'name' => 'Military', 'prefix' => 'mil', 'size' => 8, 'entry_x' => 4, 'entry_y' => 4, 'created_at' => now(), 'updated_at' => now()]);
        $c = Character::factory()->create();
        $fortress = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $inSubnet = System::factory()->at(2, 2)->create(['grid_id' => 2, 'address' => 'mil:0002']);
        $this->leecher($c);
        $leeches = new Leeches;

        $this->assertNull($leeches->install($c, $inSubnet));
        $this->assertSame(0, Leech::query()->count());

        $subFortress = System::factory()->at(3, 3)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id, 'grid_id' => 2, 'address' => 'mil:0003']);
        $leech = $leeches->install($c, $inSubnet);

        $this->assertInstanceOf(Leech::class, $leech);
        $this->assertSame($subFortress->id, $leech->fortress_system_id);
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

    public function test_the_chance_is_the_hosts_grade_lowered_by_a_percentage_of_it_with_a_floor(): void
    {
        $c = Character::factory()->create();
        System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $host = System::factory()->at(2, 2)->create(['grade' => 5]);
        $this->leecher($c);
        $leeches = new Leeches;
        $leeches->install($c, $host);

        // Grade V is 12 per cent: a roll of exactly 1200 finds it, 1201 does not (and nothing is removed on a dry run).
        $this->assertSame(0, $leeches->discover(fn (): int => 1201, true));
        $this->assertSame(1, $leeches->discover(fn (): int => 1200, true));

        // Five levels of 2 per cent of the base is 10 per cent of 12: 10.8. A roll of 1100 now misses; 1080 still finds it.
        $skill = Skill::factory()->rank(1)->governedBy('memory', 'focus')->create([
            'slug' => 'concealment_t',
            'modifiers' => array_map(fn (int $l): array => ['op' => 'percentage_of_base', 'level' => $l, 'amount' => -2, 'target' => ['id' => 'leech_discovery']], range(1, 5)),
        ]);
        CharacterSkill::create(['character_id' => $c->id, 'skill_id' => $skill->id, 'skill_points' => $skill->pointsForLevel(5), 'attained_level' => 5]);
        $this->assertSame(0, $leeches->discover(fn (): int => 1100, true));
        $this->assertSame(1, $leeches->discover(fn (): int => 1080, true));

        // Never below the floor: a skill that takes it all away still leaves 0.5 per cent.
        config(['cyberspace.fortress.leech_discovery' => [5 => 0.1]]);
        $this->assertSame(1, $leeches->discover(fn (): int => 50, true));
        $this->assertSame(0, $leeches->discover(fn (): int => 51, true));
    }
}
