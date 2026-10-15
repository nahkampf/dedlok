<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\FortressAccess;
use App\Cyberspace\FortressAreas;
use App\Cyberspace\FortressCloak;
use App\Cyberspace\FortressDefence;
use App\Cyberspace\Fortresses;
use App\Cyberspace\FortressHost;
use App\Cyberspace\FortressMarket;
use App\Cyberspace\FortressProduction;
use App\Cyberspace\FortressRelocation;
use App\Cyberspace\Grid;
use App\Cyberspace\Position;
use App\Cyberspace\Sweep;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\Design;
use App\Models\FortressJob;
use App\Models\FortressListing;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Leech;
use App\Models\Program;
use App\Models\System;
use App\Models\SystemContact;
use App\Models\SystemContent;
use App\Programs\ProgramKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

final class FortressHostTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private function program(Character $c, string $slug, ProgramKind $kind, float $cpu, float $ram, float $sto): InventoryItem
    {
        $item = Item::query()->create(['slug' => $slug, 'name' => $slug, 'container' => 'data', 'stackable' => false]);
        Program::query()->create(['item_id' => $item->id, 'kind' => $kind, 'qflops_cost' => $cpu, 'memory_cost' => $ram, 'storage_size' => $sto, 'background_process' => false]);

        return InventoryItem::query()->create(['character_id' => $c->id, 'item_id' => $item->id, 'quantity' => 1]);
    }

    private function feed(Character $c, System $fortress, string $resource, float $amount, int $n): void
    {
        Leech::query()->create(['character_id' => $c->id, 'fortress_system_id' => $fortress->id, 'host_system_id' => System::factory()->at($n, $n)->create()->id, 'resource' => $resource, 'amount' => $amount, 'installed_at' => now()]);
    }

    public function test_a_shortfall_stops_the_lowest_tier_first_and_the_kernel_last(): void
    {
        config(['cyberspace.fortress.kernel_demand' => ['compute' => 1.0, 'memory' => 1.0, 'storage' => 1.0]]);
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $this->feed($c, $f, 'compute', 6.0, 5);
        $this->feed($c, $f, 'memory', 6.0, 6);
        $this->feed($c, $f, 'storage', 10.0, 7);

        $host = new FortressHost;
        $ice = $this->program($c, 'ice', ProgramKind::FortressIce, 2, 2, 1);
        $util = $this->program($c, 'util', ProgramKind::Utility, 2, 2, 1);
        foreach ([$ice, $util] as $row) {
            $this->assertNull($host->install($c, $row, $f));
            $this->assertNull($host->start($c, $row->fresh()));
        }

        // Compute and RAM 1 (kernel) + 2 + 2 = 5 of 6. Lose some compute: utility stops, ICE stays.
        Leech::query()->where('resource', 'compute')->update(['amount' => 4.0]);
        $host->settle($f);
        $this->assertFalse($util->fresh()->fortress_running);
        $this->assertTrue($ice->fresh()->fortress_running);
        $this->assertNull($f->fresh()->abandoned_at);

        // Lose almost everything: ICE stops by itself, then the fortress is offline.
        Leech::query()->where('resource', 'compute')->update(['amount' => 0.5]);
        $host->settle($f);
        $this->assertFalse($ice->fresh()->fortress_running);
        $this->assertNotNull($f->fresh()->abandoned_at);

        // Resources return: the Kernel comes back, then the ICE restarts by itself; the utility waits for a hand.
        Leech::query()->where('resource', 'compute')->update(['amount' => 6.0]);
        $host->settle($f);
        $this->assertNull($f->fresh()->abandoned_at);
        $this->assertTrue($ice->fresh()->fortress_running);
        $this->assertFalse($util->fresh()->fortress_running);
    }

    public function test_installing_needs_storage_and_uninstalling_returns_it_loose(): void
    {
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $this->feed($c, $f, 'storage', 2.0, 5);
        $row = $this->program($c, 'big', ProgramKind::Utility, 0, 0, 5);

        $this->assertSame('no_storage', (new FortressHost)->install($c, $row, $f));

        $this->feed($c, $f, 'storage', 10.0, 6);
        $this->assertNull((new FortressHost)->install($c, $row, $f));
        $this->assertSame(0, InventoryItem::query()->where('character_id', $c->id)->loose()->count());

        (new FortressHost)->uninstall($c, $row->fresh());
        $this->assertSame(1, InventoryItem::query()->where('character_id', $c->id)->loose()->count());
    }

    public function test_the_owner_sees_the_fortress_screen_and_nobody_else_does(): void
    {
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $other = Character::factory()->create();

        $this->actingAs($c->user)->get(route('fortress.show', $f))->assertOk()->assertSee('Offline');
        $this->actingAs($other->user)->get(route('fortress.show', $f))->assertNotFound();
    }

    public function test_a_frozen_kernel_is_saved_by_leechers_or_deleted(): void
    {
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $this->feed($c, $f, 'storage', 5.0, 5);
        $this->program($c, 'x', ProgramKind::Utility, 0, 0, 1);

        (new Fortresses)->freeze($f->fresh());
        $this->assertTrue(Fortresses::isFrozen($f->fresh()));
        $this->assertSame(0, Leech::query()->count());

        // New Leechers that can run the Kernel bring it back, empty.
        $this->feed($c, $f, 'compute', 2.0, 6);
        $this->feed($c, $f, 'memory', 2.0, 7);
        (new FortressHost)->settle($f->fresh());
        $this->assertNull($f->fresh()->frozen_until);
        $this->assertNull($f->fresh()->abandoned_at);
    }

    public function test_an_invited_member_may_use_the_vault_but_not_host(): void
    {
        $owner = Character::factory()->create();
        $member = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $owner->id]);
        $access = new FortressAccess;

        $this->assertNull($access->invite($owner, $f, $member->handle, 'member'));
        $area = (new FortressAreas)->create($owner, $f, 'Drop');
        $this->assertTrue(FortressAccess::areaCan($member, $f, $area, 'deposit'));
        $this->assertFalse(FortressAccess::areaCan($member, $f, null, 'see'));
        (new FortressAreas)->allow($owner, $f, $area, 'members', ['deposit']);
        $this->assertFalse(FortressAccess::areaCan($member, $f->fresh(), $area->fresh(), 'withdraw'));
        $this->assertTrue(FortressAccess::can($owner, $f, 'audit'));
        $this->assertFalse(FortressAccess::can($member, $f, 'host'));
        $this->assertSame('not_yours', $access->invite($member, $f, $owner->handle));

        $this->actingAs($member->user)->get(route('fortress.show', $f))->assertOk();
        $access->revoke($owner, $f, $member->id);
        $this->actingAs($member->fresh()->user)->get(route('fortress.show', $f))->assertNotFound();
    }

    public function test_a_running_crafted_defence_arms_the_fortress_and_decays_only_while_installed(): void
    {
        Ice::query()->firstOrCreate(['level' => 1], ['name' => 'ICE I', 'integrity' => 10, 'attack' => 1, 'skill' => 50, 'detection' => 5, 'grade_min' => 1, 'grade_max' => 3]);
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $this->feed($c, $f, 'compute', 6.0, 5);
        $this->feed($c, $f, 'memory', 6.0, 6);
        $this->feed($c, $f, 'storage', 6.0, 7);
        $ice = $this->program($c, 'wall', ProgramKind::FortressIce, 1, 1, 1);
        $ids = $this->program($c, 'eye', ProgramKind::FortressIds, 1, 1, 1);
        foreach ([$ice, $ids] as $row) {
            FortressDefence::stamp($c, $row->load('item.program'));
        }

        $host = new FortressHost;
        foreach ([$ice, $ids] as $row) {
            $this->assertNull($host->install($c, $row, $f));
            $this->assertNull($host->start($c, $row->fresh()));
        }

        $f->refresh();
        $this->assertSame(5, $f->fortress_ice['attack']);
        $this->assertSame(20, (int) $f->ids_detection);
        $this->assertNotNull($f->ice_id);

        // Ten days installed: 20% off. Taking it down freezes that.
        $this->travel(10)->days();
        $this->assertSame(4, FortressDefence::effective($ice->fresh())['attack']);
        $host->uninstall($c, $ice->fresh());
        $this->travel(10)->days();
        $this->assertSame(4, FortressDefence::effective($ice->fresh())['attack']);
    }

    private function openMarket(Character $owner, System $f): array
    {
        $this->feed($owner, $f, 'compute', 6.0, 20);
        $this->feed($owner, $f, 'memory', 6.0, 21);
        $this->feed($owner, $f, 'storage', 10.0, 22);
        $market = $this->program($owner, 'bazaar', ProgramKind::Market, 1, 1, 1);
        $host = new FortressHost;
        $this->assertNull($host->install($owner, $market, $f));
        $this->assertNull($host->start($owner, $market->fresh()));

        $area = (new FortressAreas)->create($owner, $f, 'Shop');

        return [$area, $market];
    }

    public function test_a_visitor_buys_from_the_market_and_the_tax_lands_in_the_wallet(): void
    {
        Item::query()->firstOrCreate(['slug' => 'encrypted-data-dump'], ['name' => 'Encrypted Data Dump', 'container' => 'data', 'stackable' => false]);
        $owner = Character::factory()->create();
        $buyer = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['creds' => 500]));
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $owner->id, 'access' => 'public']);
        $this->equipReadyDeck($buyer);
        $buyer->forceFill(['grid_x' => 1, 'grid_y' => 1])->save();
        $before = (int) $owner->fresh()->creds;
        [$area] = $this->openMarket($owner, $f);

        $file = SystemContent::query()->create(['system_id' => $f->id, 'kind' => 'data', 'label' => 'Ledger', 'size_qb' => 1, 'value_sealed' => 1, 'value_broken' => 2, 'area_id' => $area->id, 'deposited_by' => $owner->id]);
        $market = new FortressMarket;
        $this->assertNull($market->configure($owner, $f, $area->id, 10));
        $this->assertNull($market->list($owner, $f->fresh(), $file, 100));

        $listing = FortressListing::query()->first();
        $this->assertNull($market->buy($buyer->fresh(), $f->fresh(), $listing));

        $this->assertSame(400, (int) $buyer->fresh()->creds);
        $this->assertSame(10, (int) $f->fresh()->wallet);
        $this->assertSame($before + 90, (int) $owner->fresh()->creds);
        $this->assertSame(0, SystemContent::query()->where('system_id', $f->id)->count());
    }

    public function test_a_relocation_program_moves_the_fortress_and_tells_its_members(): void
    {
        config(['cyberspace.size' => 16]);
        $owner = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $owner->id, 'relocated_at' => now()->subDays(3)]);
        $this->feed($owner, $f, 'compute', 6.0, 20);
        $this->feed($owner, $f, 'memory', 6.0, 21);
        $this->feed($owner, $f, 'storage', 6.0, 22);
        $mover = $this->program($owner, 'hop', ProgramKind::Relocation, 1, 1, 1);
        $host = new FortressHost;
        $host->install($owner, $mover, $f);
        $host->start($owner, $mover->fresh());
        $old = $f->fresh()->address;

        $this->assertTrue((new FortressRelocation)->due($f->fresh()));
        $this->assertNotSame($old, $f->fresh()->address);
        $this->assertNotNull($f->fresh()->relocated_at);
    }

    public function test_a_cloaked_fortress_presents_its_chosen_face_to_everyone_without_access(): void
    {
        config(['cyberspace.size' => 16]);
        $owner = Character::factory()->create();
        $f = System::factory()->at(5, 5)->create(['type' => SystemType::Fortress, 'owner_character_id' => $owner->id, 'fortress_cloak' => 10]);
        $this->assertSame('no_cloak', (new FortressCloak)->set($owner, System::factory()->at(6, 6)->create(['type' => SystemType::Fortress, 'owner_character_id' => $owner->id]), 3, 'silo', null));
        $this->assertNull((new FortressCloak)->set($owner, $f, 4, 'relay', null));
        $stranger = Character::factory()->create();

        (new Sweep(Grid::fromConfig()))->from($stranger, new Position(5, 5));
        (new Sweep(Grid::fromConfig()))->from($owner, new Position(5, 5));

        $this->assertSame('relay', SystemContact::query()->where('character_id', $stranger->id)->first()->type->value);
        $this->assertSame('fortress', SystemContact::query()->where('character_id', $owner->id)->first()->type->value);
    }

    private function producer(Character $c, System $f, array $produces, string $slug = 'maker'): InventoryItem
    {
        $row = $this->program($c, $slug, ProgramKind::Producer, 1, 1, 1);
        $row->item->program->forceFill(['produces' => $produces])->save();
        $host = new FortressHost;
        $this->assertNull($host->install($c, $row, $f));
        $this->assertNull($host->start($c, $row->fresh()));

        return $row;
    }

    public function test_a_producer_captures_credits_and_makes_components_a_cycle_at_a_time(): void
    {
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $this->feed($c, $f, 'compute', 6.0, 5);
        $this->feed($c, $f, 'memory', 6.0, 6);
        $this->feed($c, $f, 'storage', 20.0, 7);
        Item::query()->firstOrCreate(['slug' => 'dummy-part'], ['name' => 'Part', 'container' => 'data', 'stackable' => true]);
        config(['cyberspace.decryption.components' => ['dummy-part' => ['headline' => 'x', 'weights' => [3 => 100]]]]);

        $this->producer($c, $f, ['credits_min' => 20, 'credits_max' => 100, 'components' => 2]);
        $production = new FortressProduction(fn (int $a, int $b): int => $a);
        $this->assertNull($production->enqueue($c, $f->fresh(), 'components', null, 2));

        // Two hours is not a cycle (six hours): nothing yet.
        System::query()->whereKey($f->id)->update(['production_at' => now()->subHours(2)]);
        $production->settle($f->fresh());
        $this->assertSame(0, (int) $f->fresh()->wallet);

        // Another five: the carried two plus these cross the six. One cycle: the roll's floor, and two files.
        System::query()->whereKey($f->id)->update(['production_at' => now()->subHours(5)]);
        $production->settle($f->fresh());

        $this->assertSame(20, (int) $f->fresh()->wallet);
        $this->assertSame(0, FortressJob::query()->count());
        $this->assertSame(2, (int) SystemContent::query()->where('system_id', $f->id)->where('kind', 'item')->sum('quantity'));
    }

    public function test_a_program_job_waits_for_components_and_the_queue_holds_five(): void
    {
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $this->feed($c, $f, 'compute', 6.0, 5);
        $this->feed($c, $f, 'memory', 6.0, 6);
        $this->feed($c, $f, 'storage', 20.0, 7);

        $part = Item::query()->firstOrCreate(['slug' => 'dummy-part'], ['name' => 'Part', 'container' => 'data', 'stackable' => true]);
        $product = Item::query()->create(['slug' => 'made-script', 'name' => 'Made', 'container' => 'data', 'stackable' => false]);
        $designItem = Item::query()->create(['slug' => 'blueprint', 'name' => 'Blueprint', 'container' => 'data', 'stackable' => false]);
        $design = Design::query()->create(['item_id' => $designItem->id, 'product_item_id' => $product->id, 'craft_seconds' => 7200, 'single_use' => false]);
        $design->components()->create(['position' => 0, 'component_item_id' => $part->id, 'quantity' => 2]);
        SystemContent::query()->create(['system_id' => $f->id, 'kind' => 'item', 'item_id' => $designItem->id, 'quantity' => 1]);

        // Two hours of work needed; the producer gives 1 production a cycle.
        $this->producer($c, $f, ['programs' => 1]);
        $production = new FortressProduction(fn (int $a, int $b): int => $a);
        $this->assertNull($production->enqueue($c, $f->fresh(), 'program', $designItem->id));

        System::query()->whereKey($f->id)->update(['production_at' => now()->subHours(7)]);
        $production->settle($f->fresh());
        $this->assertSame(1, FortressJob::query()->count(), 'no components: this cycle yielded nothing');
        $this->assertSame(0.0, (float) FortressJob::query()->first()->progress);

        SystemContent::query()->create(['system_id' => $f->id, 'kind' => 'item', 'item_id' => $part->id, 'quantity' => 2]);
        System::query()->whereKey($f->id)->update(['production_at' => now()->subHours(13)]);
        $production->settle($f->fresh());

        // Two cycles of 1 production each finish the two hours of work.
        $this->assertSame(0, FortressJob::query()->count());
        $this->assertSame(1, SystemContent::query()->where('system_id', $f->id)->where('item_id', $product->id)->count());
        $this->assertSame(0, SystemContent::query()->where('system_id', $f->id)->where('item_id', $part->id)->count());

        foreach (range(1, 5) as $_) {
            $production->enqueue($c, $f->fresh(), 'components');
        }
        $this->assertSame('queue_full', $production->enqueue($c, $f->fresh(), 'components'));
    }

    public function test_the_cycle_length_is_a_stat_that_modifiers_and_boosters_reach(): void
    {
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $this->feed($c, $f, 'compute', 6.0, 5);
        $this->feed($c, $f, 'memory', 6.0, 6);
        $this->feed($c, $f, 'storage', 20.0, 7);
        $row = $this->producer($c, $f, ['credits_min' => 1, 'credits_max' => 1])->fresh();
        $production = new FortressProduction(fn (int $a, int $b): int => $a);

        $this->assertSame(360.0, $production->cycleMinutes($row));
        $this->assertEqualsWithDelta(180.0, $production->cycleMinutes($row, 100), 1e-9);

        Config::set('effects.quick', [
            'label' => 'Quick', 'stacking' => 'unique', 'retired' => false,
            'modifiers' => [['op' => 'percentage_of_base', 'amount' => -50, 'target' => ['id' => 'production_cycle_minutes']]],
        ]);
        CharacterModifier::query()->create(['character_id' => $c->id, 'definition_key' => 'quick']);

        $this->assertEqualsWithDelta(180.0, $production->cycleMinutes($row->fresh()), 1e-9);
    }
}
