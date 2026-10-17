<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\ExtractedFile;
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
use App\Models\Bookmark;
use App\Models\Character;
use App\Models\Design;
use App\Models\FortressArea;
use App\Models\FortressAssembly;
use App\Models\FortressListing;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Leech;
use App\Models\Notification;
use App\Models\Program;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContact;
use App\Models\SystemContent;
use App\Programs\ProgramKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\Concerns\InjectsSkills;
use Tests\TestCase;

final class FortressHostTest extends TestCase
{
    use CarriesAWorkingDeck;
    use InjectsSkills;
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

    public function test_a_small_kernel_demand_percent_still_moves_the_demand_by_a_tenth(): void
    {
        config(['cyberspace.fortress.kernel_demand' => ['compute' => 1.0, 'memory' => 1.0, 'storage' => 1.0]]);
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'kernel_demand_percent' => 103]);

        $this->assertSame(1.1, (new FortressHost)->used($f)['storage']);

        $f->forceFill(['kernel_demand_percent' => 97])->save();
        $this->assertSame(0.9, (new FortressHost)->used($f->fresh())['storage']);
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
        $util = $this->program($c, 'util', ProgramKind::FortressDaemon, 2, 2, 1);
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
        $row = $this->program($c, 'big', ProgramKind::FortressDaemon, 0, 0, 5);

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
        $this->program($c, 'x', ProgramKind::FortressDaemon, 0, 0, 1);

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

    /** A fortress with room, and a producer of this kind installed and running on it. */
    private function producing(ProgramKind $kind, array $produces): array
    {
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $this->feed($c, $f, 'compute', 6.0, 5);
        $this->feed($c, $f, 'memory', 6.0, 6);
        $this->feed($c, $f, 'storage', 20.0, 7);
        $row = $this->program($c, 'worker-'.$kind->value, $kind, 1, 1, 1);
        $row->item->program->forceFill(['produces' => $produces])->save();
        $host = new FortressHost;
        $this->assertNull($host->install($c, $row, $f));
        $this->assertNull($host->start($c, $row->fresh()));

        return [$c, $f->fresh(), $row->fresh()];
    }

    private function dump(System $f, int $grade = 3, ?int $area = null): SystemContent
    {
        $file = new ExtractedFile(label: 'Customer data', sizeQb: 1.0, encryptionSkillId: null, valueSealed: 100, valueBroken: 400, grade: $grade);

        return SystemContent::query()->create([
            'system_id' => $f->id, 'kind' => 'data', 'label' => $file->label, 'size_qb' => 1.0, 'value_sealed' => 100, 'value_broken' => 400,
            'area_id' => $area, 'meta' => ['extracted_file' => $file->toArray(), 'slug' => ExtractedFile::SEALED_SLUG],
        ]);
    }

    private function settleAfter(System $f, int $minutes): void
    {
        System::query()->whereKey($f->id)->update(['production_at' => now()->subMinutes($minutes)]);
        (new FortressProduction(fn (int $a, int $b): int => $a))->settle($f->fresh());
    }

    public function test_a_decryptor_turns_a_dump_into_a_component_a_cycle_later_and_keeps_its_origin(): void
    {
        config(['cyberspace.decryption.components' => ['dummy-part' => ['headline' => 'x', 'weights' => [3 => 100]]]]);
        Item::query()->firstOrCreate(['slug' => 'dummy-part'], ['name' => 'Part', 'container' => 'data', 'stackable' => false]);
        [$c, $f] = $this->producing(ProgramKind::FortressDecryptor, ['cycle_minutes' => 60]);
        $area = FortressArea::query()->create(['system_id' => $f->id, 'name' => 'Out', 'permissions' => []]);
        $f->forceFill(['decryptor_out_area_id' => $area->id])->save();
        $this->dump($f->fresh(), 3);

        $this->settleAfter($f, 30);
        $this->assertSame(0, SystemContent::query()->where('system_id', $f->id)->where('kind', 'item')->count(), 'half a cycle is not a cycle');

        $this->settleAfter($f, 31);
        $made = SystemContent::query()->where('system_id', $f->id)->where('kind', 'item')->first();
        $this->assertSame('dummy-part', $made->item->slug);
        $this->assertSame($area->id, $made->area_id);
        $this->assertSame(3, $made->meta['extracted_file']['grade']);
        $this->assertSame(0, SystemContent::query()->where('system_id', $f->id)->where('kind', 'data')->count());
    }

    public function test_a_decryptor_banks_no_time_while_it_has_nothing_to_work_and_skills_and_boosters_shorten_its_cycle(): void
    {
        config(['cyberspace.decryption.components' => ['dummy-part' => ['headline' => 'x', 'weights' => [3 => 100]]]]);
        Item::query()->firstOrCreate(['slug' => 'dummy-part'], ['name' => 'Part', 'container' => 'data', 'stackable' => false]);
        [$c, $f, $row] = $this->producing(ProgramKind::FortressDecryptor, ['cycle_minutes' => 60]);

        // Idle for a long time, then a Dump arrives: the cycle starts then, not before.
        $this->settleAfter($f, 600);
        $this->dump($f->fresh());
        $this->settleAfter($f, 10);
        $this->assertSame(0, SystemContent::query()->where('system_id', $f->id)->where('kind', 'item')->count());

        $skill = Skill::factory()->rank(1)->governedBy('memory', 'focus')->create([
            'slug' => 'decryptor_speed',
            'modifiers' => [['op' => 'percentage_of_base', 'level' => 1, 'amount' => -50, 'target' => ['id' => 'production_cycle_minutes.decryptor']]],
        ]);
        $this->inject($c, $skill, $skill->pointsForLevel(1), 1);
        $this->assertEqualsWithDelta(30.0, (new FortressProduction(fn (int $a, int $b): int => $a))->cycleMinutes($row->fresh()), 1e-9);
        $this->assertEqualsWithDelta(15.0, (new FortressProduction(fn (int $a, int $b): int => $a))->cycleMinutes($row->fresh(), 100), 1e-9);
    }

    public function test_an_assembler_spends_the_recipe_and_makes_runs_while_components_last(): void
    {
        [$c, $f, $row] = $this->producing(ProgramKind::FortressAssembler, ['reduction' => 50]);
        $part = Item::query()->firstOrCreate(['slug' => 'dummy-part'], ['name' => 'Part', 'container' => 'data', 'stackable' => true]);
        $product = Item::query()->create(['slug' => 'made-script', 'name' => 'Made', 'container' => 'data', 'stackable' => false]);
        $designItem = Item::query()->create(['slug' => 'blueprint', 'name' => 'Blueprint', 'container' => 'data', 'stackable' => false]);
        $design = Design::query()->create(['item_id' => $designItem->id, 'product_item_id' => $product->id, 'craft_seconds' => 7200, 'single_use' => false]);
        $design->components()->create(['position' => 0, 'component_item_id' => $part->id, 'quantity' => 2]);
        SystemContent::query()->create(['system_id' => $f->id, 'kind' => 'item', 'item_id' => $designItem->id, 'quantity' => 1]);
        SystemContent::query()->create(['system_id' => $f->id, 'kind' => 'item', 'item_id' => $part->id, 'quantity' => 3]);
        $production = new FortressProduction(fn (int $a, int $b): int => $a);

        $this->assertSame('no_design', $production->assign($c, $f, $row, 999999, 2));
        $this->assertNull($production->assign($c, $f, $row, $designItem->id, 2));

        // Two hours halved by the item is 60 minutes a run.
        $this->assertEqualsWithDelta(60.0, $production->runMinutes($row, $design), 1e-9);
        $this->settleAfter($f, 30);
        $this->assertSame(0, SystemContent::query()->where('item_id', $product->id)->count());
        $this->assertSame(1, SystemContent::query()->where('item_id', $part->id)->sum('quantity'), 'the recipe is spent when the run starts');

        // The first run finishes; the second cannot start on one component, so it waits.
        $this->settleAfter($f, 40);
        $this->assertSame(1, SystemContent::query()->where('item_id', $product->id)->count());
        $this->assertSame(1, FortressAssembly::query()->first()->runs_done);

        // Changing the assignment cancels the run in progress.
        $this->assertNull($production->assign($c, $f, $row, $designItem->id, 0));
        $this->assertFalse((bool) FortressAssembly::query()->first()->started);
    }

    public function test_an_assembler_uses_only_components_that_match_the_designs_filters_lowest_grade_first(): void
    {
        [$c, $f, $row] = $this->producing(ProgramKind::FortressAssembler, ['reduction' => 0]);
        $part = Item::query()->firstOrCreate(['slug' => 'dummy-part'], ['name' => 'Part', 'container' => 'data', 'stackable' => false]);
        $product = Item::query()->create(['slug' => 'made-script', 'name' => 'Made', 'container' => 'data', 'stackable' => false]);
        $designItem = Item::query()->create(['slug' => 'blueprint', 'name' => 'Blueprint', 'container' => 'data', 'stackable' => false]);
        $design = Design::query()->create(['item_id' => $designItem->id, 'product_item_id' => $product->id, 'craft_seconds' => 3600, 'single_use' => false]);
        $design->components()->create(['position' => 0, 'component_item_id' => $part->id, 'quantity' => 1, 'grade_min' => 5]);
        SystemContent::query()->create(['system_id' => $f->id, 'kind' => 'item', 'item_id' => $designItem->id, 'quantity' => 1]);
        $part = fn (int $grade) => SystemContent::query()->create([
            'system_id' => $f->id, 'kind' => 'item', 'item_id' => $part->id, 'quantity' => 1,
            'meta' => ['extracted_file' => (new ExtractedFile(label: 'x', sizeQb: 1.0, encryptionSkillId: null, valueSealed: 1, valueBroken: 1, grade: $grade))->toArray()],
        ]);
        $weak = $part(2);
        $production = new FortressProduction(fn (int $a, int $b): int => $a);
        $production->assign($c, $f, $row, $designItem->id, 1);

        $this->settleAfter($f, 90);
        $this->assertNotNull($weak->fresh(), 'a grade 2 component does not satisfy a grade 5 line');
        $this->assertFalse((bool) FortressAssembly::query()->first()->started);

        $good = $part(7);
        $better = $part(5);
        $this->settleAfter($f, 30);
        $this->assertNull($better->fresh(), 'the lowest qualifying grade is spent');
        $this->assertNotNull($good->fresh());
        $this->assertNotNull($weak->fresh());
    }

    public function test_areas_are_set_by_hosts_and_a_taken_down_decryptor_gives_back_what_it_held(): void
    {
        config(['cyberspace.decryption.components' => ['dummy-part' => ['headline' => 'x', 'weights' => [3 => 100]]]]);
        Item::query()->firstOrCreate(['slug' => 'dummy-part'], ['name' => 'Part', 'container' => 'data', 'stackable' => false]);
        [$c, $f, $row] = $this->producing(ProgramKind::FortressDecryptor, ['cycle_minutes' => 60]);
        $stranger = Character::factory()->create();
        $production = new FortressProduction(fn (int $a, int $b): int => $a);
        $area = FortressArea::query()->create(['system_id' => $f->id, 'name' => 'In', 'permissions' => []]);

        $this->assertSame('not_yours', $production->setAreas($stranger, $f, 'decryptor', $area->id, null));
        $this->assertSame('not_yours', $production->setAreas($c, $f, 'decryptor', 999999, null));
        $this->assertNull($production->setAreas($c, $f, 'decryptor', $area->id, null));
        $this->assertSame($area->id, (int) $f->fresh()->decryptor_in_area_id);

        $this->dump($f->fresh(), 5, $area->id);
        $this->settleAfter($f, 10);
        $this->assertSame(0, SystemContent::query()->where('kind', 'data')->count(), 'the Decryptor holds the Dump while it works');

        $this->assertNull((new FortressHost)->uninstall($c, $row->fresh()));
        $back = SystemContent::query()->where('kind', 'data')->first();
        $this->assertSame($area->id, $back->area_id);
        $this->assertSame(5, $back->meta['extracted_file']['grade']);
    }

    public function test_a_network_tap_steals_a_dump_from_each_node_in_range_each_cycle_and_a_failed_roll_steals_none(): void
    {
        [$c, $f] = $this->producing(ProgramKind::FortressNetworkTap, ['cycle_minutes' => 60, 'range' => 2, 'strength' => 1000]);
        System::factory()->at(2, 2)->grade(1)->create();
        System::factory()->at(3, 3)->grade(2)->create();
        System::factory()->at(9, 9)->grade(1)->create(); // out of range
        $this->app->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        $this->settleAfter($f, 30);
        $this->assertSame(0, SystemContent::query()->where('system_id', $f->id)->where('kind', 'data')->count(), 'half a cycle is not a cycle');

        $this->settleAfter($f, 31);
        $dumps = SystemContent::query()->where('system_id', $f->id)->where('kind', 'data')->get();
        $this->assertCount(2, $dumps);
        $this->assertSame(ExtractedFile::SEALED_SLUG, $dumps[0]->meta['slug']);
        $this->assertEqualsCanonicalizing([1, 2], $dumps->map(fn ($d) => $d->meta['extracted_file']['grade'])->all());

        SystemContent::query()->where('system_id', $f->id)->delete();
        $this->app->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.9999));
        $this->settleAfter($f, 61);
        $this->assertSame(0, SystemContent::query()->where('system_id', $f->id)->where('kind', 'data')->count());
    }

    public function test_a_wiretap_skims_creds_by_grade_into_the_wallet_and_a_skill_raises_it(): void
    {
        [$c, $f] = $this->producing(ProgramKind::FortressWiretap, ['cycle_minutes' => 60, 'range' => 1, 'strength' => 1000]);
        System::factory()->at(2, 2)->grade(3)->create();
        config(['cyberspace.fortress.wiretap_pay' => [3 => [20, 40]]]);
        $this->app->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        $this->settleAfter($f, 61);
        $this->assertSame(20, (int) $f->fresh()->wallet); // the pinned die takes the minimum

        $skill = Skill::factory()->rank(1)->governedBy('memory', 'focus')->create([
            'slug' => 'skim', 'modifiers' => [['op' => 'add', 'level' => 1, 'amount' => 50, 'target' => ['id' => 'wiretap_yield']]],
        ]);
        $this->inject($c, $skill, $skill->pointsForLevel(1), 1);
        $this->settleAfter($f, 61);
        $this->assertSame(50, (int) $f->fresh()->wallet); // 20 + 30
    }

    public function test_a_defence_skill_lifts_only_its_own_product(): void
    {
        $c = Character::factory()->create();
        $skill = Skill::factory()->rank(1)->governedBy('memory', 'intellect')->create([
            'slug' => 'ice_quality',
            'modifiers' => [['op' => 'add', 'level' => 1, 'amount' => 50, 'target' => ['id' => 'defence_quality.ice']]],
        ]);
        $this->inject($c, $skill, $skill->pointsForLevel(1), 1);
        $ice = $this->program($c, 'wall', ProgramKind::FortressIce, 1, 1, 1);
        $ids = $this->program($c, 'eye', ProgramKind::FortressIds, 1, 1, 1);

        foreach ([$ice, $ids] as $row) {
            FortressDefence::stamp($c->fresh(), $row->load('item.program'));
        }

        $this->assertSame(8, $ice->fresh()->defence['attack']);
        $this->assertSame(45, $ice->fresh()->defence['integrity']);
        $this->assertSame(20, $ids->fresh()->defence['detection']);
    }

    public function test_a_boosters_boost_is_stamped_by_its_maker_and_never_decays(): void
    {
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $skill = Skill::factory()->rank(1)->governedBy('memory', 'intellect')->create([
            'slug' => 'booster_quality',
            'modifiers' => [['op' => 'add', 'level' => 1, 'amount' => 10, 'target' => ['id' => 'defence_quality.booster']]],
        ]);
        $this->inject($c, $skill, $skill->pointsForLevel(1), 1);
        $row = $this->program($c, 'pace', ProgramKind::Booster, 1, 1, 1);
        $row->item->program->update(['produces' => ['boost' => 25]]);

        FortressDefence::stamp($c->fresh(), $row->load('item.program'));
        $row = $row->fresh();
        $row->forceFill(['fortress_system_id' => $f->id, 'fortress_running' => true])->save();
        FortressDefence::installed($row);

        // 25 over base by a 10% maker; no decay clock and no maintenance for a Booster.
        $this->assertEqualsWithDelta(27.5, $row->fresh()->defence['boost'], 1e-9);
        $this->assertArrayNotHasKey('maintained_at', $row->fresh()->defence);
        $this->assertSame('not_hosted', FortressDefence::maintain($c, $row->fresh(), $f));
    }

    public function test_a_relocation_names_a_bookmark_for_the_owner_and_messages_who_is_chosen(): void
    {
        config(['cyberspace.size' => 16]);
        $owner = Character::factory()->create();
        $admin = Character::factory()->create();
        $guest = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'name' => 'Citadel', 'owner_character_id' => $owner->id, 'relocated_at' => now()->subDays(3)]);
        DB::table('fortress_members')->insert([
            ['system_id' => $f->id, 'character_id' => $admin->id, 'role' => 'administrator'],
            ['system_id' => $f->id, 'character_id' => $guest->id, 'role' => 'member'],
        ]);
        $this->feed($owner, $f, 'compute', 6.0, 20);
        $this->feed($owner, $f, 'memory', 6.0, 21);
        $this->feed($owner, $f, 'storage', 6.0, 22);
        $mover = $this->program($owner, 'hop', ProgramKind::Relocation, 1, 1, 1);
        (new FortressHost)->install($owner, $mover, $f);
        (new FortressHost)->start($owner, $mover->fresh());

        // The administrator may set the interval and who is told; a guest may not, nor may an interval off the list.
        $relocation = new FortressRelocation;
        $this->assertSame('not_yours', $relocation->configure($guest, $f->fresh(), 8, true, true, true));
        $this->assertSame('bad_interval', $relocation->configure($admin, $f->fresh(), 5, true, true, true));
        $this->assertNull($relocation->configure($admin, $f->fresh(), 8, true, false, false));
        $this->assertSame(8, FortressRelocation::hoursFor($f->fresh()));

        System::query()->whereKey($f->id)->update(['relocated_at' => now()->subHours(9)]);
        $this->assertTrue($relocation->due($f->fresh()));

        $new = $f->fresh();
        $this->assertSame('Citadel', Bookmark::query()->where('character_id', $owner->id)->where('address', $new->address)->value('label'));
        $mail = Notification::query()->where('user_id', $owner->user_id)->latest('id')->first();
        $this->assertSame('Data Fortress Citadel relocated', $mail->title);
        $this->assertSame("The new position is {$new->x},{$new->y}, address {$new->address}", $mail->body);
        $this->assertSame(0, Notification::query()->where('user_id', $admin->user_id)->count());
        $this->assertSame(0, Notification::query()->where('user_id', $guest->user_id)->count());

        // And not before the interval has passed again.
        $this->assertFalse($relocation->due($new->fresh()));
    }

    public function test_maintenance_skills_raise_values_and_cut_demand_a_pass_at_a_time(): void
    {
        $c = Character::factory()->create();
        $f = System::factory()->at(1, 1)->create(['type' => SystemType::Fortress, 'owner_character_id' => $c->id]);
        $this->feed($c, $f, 'compute', 6.0, 5);
        $this->feed($c, $f, 'memory', 6.0, 6);
        $this->feed($c, $f, 'storage', 6.0, 7);
        $hardening = Skill::factory()->rank(1)->governedBy('memory', 'focus')->create([
            'slug' => 'hardening_t',
            'modifiers' => array_map(fn (int $l): array => ['op' => 'add', 'level' => $l, 'amount' => 2, 'target' => ['id' => 'maintenance_boost']], range(1, 5)),
        ]);
        $streamlining = Skill::factory()->rank(1)->governedBy('memory', 'focus')->create([
            'slug' => 'streamlining_t',
            'modifiers' => array_map(fn (int $l): array => ['op' => 'add', 'level' => $l, 'amount' => 1, 'target' => ['id' => 'maintenance_efficiency']], range(1, 5)),
        ]);
        $this->inject($c, $hardening, $hardening->pointsForLevel(3), 3);
        $this->inject($c, $streamlining, $streamlining->pointsForLevel(2), 2);
        $ice = $this->program($c, 'wall', ProgramKind::FortressIce, 1, 1, 1);
        FortressDefence::stamp($c->fresh(), $ice->load('item.program'));
        (new FortressHost)->install($c, $ice, $f);

        $this->assertNull(FortressDefence::maintain($c->fresh(), $ice->fresh(), $f));

        // 3 levels of Hardening is 6% a pass (rounded up to a point on 5 and 30); 2 of Streamlining cuts 2 points of demand.
        $defence = $ice->fresh()->defence;
        $this->assertSame(6, $defence['attack']);
        $this->assertSame(32, $defence['integrity']);
        $this->assertEquals(2, $defence['cut']);
    }
}
