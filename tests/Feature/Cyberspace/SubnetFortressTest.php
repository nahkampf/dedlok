<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\ContentKind;
use App\Cyberspace\Extractor;
use App\Cyberspace\Fortresses;
use App\Cyberspace\FortressHost;
use App\Cyberspace\FortressRefusal;
use App\Cyberspace\FortressTunnel;
use App\Cyberspace\FortressVault;
use App\Cyberspace\NodeStocker;
use App\Cyberspace\SystemType;
use App\Cyberspace\TravelRefusal;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Program;
use App\Models\System;
use App\Models\SystemContent;
use App\Programs\ProgramKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Subnet fortresses (CYBERSPACE.md, *Subnet fortresses*): the certificate that lets one be founded, and Secure Tunnelling.
 */
final class SubnetFortressTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);
        Config::set('cyberspace.fortress.cap', 2);
        DB::table('grids')->insert(['id' => 2, 'slug' => 'mil', 'name' => 'Military', 'prefix' => 'mil', 'size' => 8, 'entry_x' => 4, 'entry_y' => 4, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function kernel(Character $c): InventoryItem
    {
        $item = Item::query()->firstOrCreate(['slug' => 'data-fortress-kernel'], ['name' => 'Kernel', 'container' => 'data', 'stackable' => false]);
        Program::query()->firstOrCreate(['item_id' => $item->id], ['kind' => ProgramKind::Kernel]);

        return InventoryItem::query()->create(['character_id' => $c->id, 'item_id' => $item->id, 'quantity' => 1]);
    }

    private function certificate(Character $c, ?int $grid): InventoryItem
    {
        $item = Item::query()->where('slug', Fortresses::CERTIFICATE_SLUG)->firstOrFail();

        return InventoryItem::query()->create(['character_id' => $c->id, 'item_id' => $item->id, 'quantity' => 1, 'grid_id' => $grid]);
    }

    private function tunnelProgram(System $fortress, bool $running = true): void
    {
        $item = Item::query()->create(['slug' => 'tun'.$fortress->id, 'name' => 'Secure Tunnelling', 'container' => 'data', 'stackable' => false]);
        Program::query()->create(['item_id' => $item->id, 'kind' => ProgramKind::Tunnel, 'qflops_cost' => 0, 'memory_cost' => 0, 'storage_size' => 0, 'background_process' => false]);
        InventoryItem::query()->create(['character_id' => $fortress->owner_character_id, 'item_id' => $item->id, 'quantity' => 1, 'fortress_system_id' => $fortress->id, 'fortress_running' => $running]);
    }

    public function test_a_subnet_fortress_needs_that_subnets_certificate_and_locks_it_in_its_vault(): void
    {
        $c = Character::factory()->create(['grid_id' => 2, 'grid_x' => 5, 'grid_y' => 5]);

        $this->assertSame(FortressRefusal::NoCertificate, (new Fortresses)->found($c, $this->kernel($c)));

        // A certificate for another subnet, or one never stamped, is no certificate here.
        $this->certificate($c, 3);
        $this->certificate($c, null);
        $this->assertSame(FortressRefusal::NoCertificate, (new Fortresses)->found($c->fresh(), InventoryItem::query()->where('item_id', Item::query()->where('slug', 'data-fortress-kernel')->value('id'))->first()));

        $good = $this->certificate($c, 2);
        $result = (new Fortresses)->found($c->fresh(), InventoryItem::query()->where('item_id', Item::query()->where('slug', 'data-fortress-kernel')->value('id'))->first());

        $this->assertInstanceOf(System::class, $result, $result instanceof FortressRefusal ? $result->value : '');
        $this->assertSame(2, (int) $result->grid_id);
        $this->assertMatchesRegularExpression('/^mil:[0-9A-F]{4}$/', $result->address);
        $this->assertDatabaseMissing('inventory_items', ['id' => $good->id]);

        $locked = SystemContent::query()->where('system_id', $result->id)->sole();
        $this->assertTrue($locked->meta['locked']);
        $this->assertSame('locked', (new FortressVault)->withdraw($c->fresh(), $locked, $result));
        $this->assertDatabaseHas('system_contents', ['id' => $locked->id]);
    }

    public function test_a_certificate_taken_from_a_subnet_node_is_stamped_with_that_subnet(): void
    {
        $c = Character::factory()->create();
        $this->equipReadyDeck($c);
        $node = System::factory()->at(2, 2)->create(['grid_id' => 2, 'address' => 'mil:0001']);
        $item = Item::query()->where('slug', Fortresses::CERTIFICATE_SLUG)->firstOrFail();
        SystemContent::query()->create(['system_id' => $node->id, 'kind' => ContentKind::Item->value, 'item_id' => $item->id, 'quantity' => 1]);

        (new Extractor)->takeCredsAndItems($c, 'mil:0001');

        $this->assertSame(2, (int) InventoryItem::query()->where('character_id', $c->id)->where('item_id', $item->id)->sole()->grid_id);
    }

    public function test_a_subnet_spec_puts_certificates_on_the_node_types_it_names(): void
    {
        DB::table('grids')->where('id', 2)->update(['spec' => json_encode(['certificate' => ['types' => ['silo'], 'chance' => 100, 'min_grade' => 3]])]);
        $stocker = NodeStocker::fromConfig(static fn (int $min, int $max): int => $min);
        $held = fn (System $s): bool => collect($stocker->contentsFor($s, [], []))->contains(fn (array $c): bool => ($c['item_id'] ?? null) === Item::query()->where('slug', Fortresses::CERTIFICATE_SLUG)->value('id'));

        $this->assertTrue($held(System::factory()->at(1, 1)->create(['grid_id' => 2, 'type' => SystemType::Silo, 'grade' => 3, 'address' => 'mil:0001'])));
        $this->assertFalse($held(System::factory()->at(2, 2)->create(['grid_id' => 2, 'type' => SystemType::Silo, 'grade' => 2, 'address' => 'mil:0002'])), 'below the least grade');
        $this->assertFalse($held(System::factory()->at(3, 3)->create(['grid_id' => 2, 'type' => SystemType::Compute, 'grade' => 5, 'address' => 'mil:0003'])), 'not a named type');
        $this->assertFalse($held(System::factory()->at(4, 4)->create(['type' => SystemType::Silo, 'grade' => 5])), 'the main grid never');
    }

    public function test_a_tunnel_puts_a_member_on_the_fortress_across_grids_for_a_toll_and_then_cools(): void
    {
        $owner = Character::factory()->create();
        $fortress = System::factory()->at(6, 6)->create(['grid_id' => 2, 'type' => SystemType::Fortress, 'owner_character_id' => $owner->id, 'address' => 'mil:0F00']);
        $this->tunnelProgram($fortress);

        $c = $owner;
        $this->equipReadyDeck($c);
        $c->forceFill(['grid_id' => 1, 'grid_x' => 3, 'grid_y' => 3, 'current_synaptic_integrity' => 20])->save();
        $tunnel = FortressTunnel::make();

        $this->assertTrue($tunnel->usable($c->fresh(), $fortress));
        $result = $tunnel->tunnel($c->fresh(), $fortress);

        $this->assertTrue($result->succeeded());
        $c->refresh();
        $this->assertSame([2, 6, 6], [(int) $c->grid_id, $c->grid_x, $c->grid_y]);
        $this->assertSame(18, (int) $c->current_synaptic_integrity);

        $again = $tunnel->tunnel($c->fresh(), $fortress);
        $this->assertSame(TravelRefusal::TunnelCooling, $again->refusal);
        $this->assertGreaterThan(0, $tunnel->cooldownLeft($c->fresh(), $fortress));
    }

    public function test_a_tunnel_also_jacks_in_somebody_who_is_not(): void
    {
        $owner = Character::factory()->create();
        $this->equipReadyDeck($owner);
        $fortress = System::factory()->at(6, 6)->create(['type' => SystemType::Fortress, 'owner_character_id' => $owner->id]);
        $this->tunnelProgram($fortress);

        $this->assertFalse($owner->fresh()->isJackedIn());
        $result = FortressTunnel::make()->tunnel($owner->fresh(), $fortress);

        $this->assertTrue($result->succeeded());
        $this->assertTrue($owner->fresh()->isJackedIn());
    }

    public function test_no_tunnel_without_a_running_program_or_for_a_stranger_or_a_busy_character(): void
    {
        $owner = Character::factory()->create();
        $this->equipReadyDeck($owner);
        $fortress = System::factory()->at(6, 6)->create(['type' => SystemType::Fortress, 'owner_character_id' => $owner->id]);
        $tunnel = FortressTunnel::make();

        $this->assertSame(TravelRefusal::NoTunnel, $tunnel->tunnel($owner->fresh(), $fortress)->refusal, 'nothing running');

        $this->tunnelProgram($fortress);
        $stranger = Character::factory()->create();
        $this->equipReadyDeck($stranger);
        $this->assertSame(TravelRefusal::NoTunnel, $tunnel->tunnel($stranger->fresh(), $fortress)->refusal, 'not admitted');

        $owner->forceFill(['waiting_until' => now()->addMinute()])->save();
        $this->assertSame(TravelRefusal::TunnelBusy, $tunnel->tunnel($owner->fresh(), $fortress)->refusal);

        $owner->forceFill(['waiting_until' => null, 'current_synaptic_integrity' => 2])->save();
        $this->assertSame(TravelRefusal::TunnelTooHurt, $tunnel->tunnel($owner->fresh(), $fortress)->refusal);
        $this->assertNotNull(new FortressHost);
    }
}
