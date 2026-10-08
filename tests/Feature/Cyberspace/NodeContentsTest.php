<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\ContentKind;
use App\Cyberspace\NodeStocker;
use App\Cyberspace\SystemType;
use App\Models\Item;
use App\Models\LootTable;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * What the world's machines are holding.
 *
 * The grid's second supply, written to the shape the market's has: a TOP-UP
 * rather than an addition, so a pass over a node that still has its contents
 * does nothing and running twice is not two hauls. Every roll here is pinned —
 * a probabilistic pass tested against real randomness is a test that passes
 * most of the time.
 */
final class NodeContentsTest extends TestCase
{
    use RefreshDatabase;

    /** A stocker whose dice always come up at one end or the other. */
    private function stocker(bool $lucky = true): NodeStocker
    {
        return NodeStocker::fromConfig(
            static fn (int $min, int $max): int => $lucky ? $min : $max,
        );
    }

    private function machine(int $grade = 5, SystemType $type = SystemType::Silo): System
    {
        return System::factory()->at($grade, $grade)->type($type)->grade($grade)->create([
            'address' => str_pad(strtoupper(dechex($grade)), 8, 'A'),
        ]);
    }

    // ---- what every node has -----------------------------------------------

    /** Creds are the floor of a haul: always something, rarely much. */
    public function test_every_machine_holds_creds(): void
    {
        $this->machine(grade: 1);
        $this->machine(grade: 9);

        $this->stocker()->run();

        $this->assertSame(2, SystemContent::query()->ofKind(ContentKind::Creds)->count());

        foreach (SystemContent::query()->ofKind(ContentKind::Creds)->get() as $creds) {
            $this->assertGreaterThan(0, $creds->amount);
        }
    }

    /** Grade is the promise a scan makes, and this is the promise paying. */
    public function test_a_harder_machine_is_holding_more(): void
    {
        $easy = $this->machine(grade: 1);
        $hard = $this->machine(grade: 9);

        $this->stocker()->run();

        $this->assertGreaterThan(
            $easy->contents()->ofKind(ContentKind::Creds)->value('amount'),
            $hard->contents()->ofKind(ContentKind::Creds)->value('amount'),
        );
    }

    public function test_the_portal_holds_nothing(): void
    {
        System::factory()->at(8, 8)->portal()->create(['address' => 'PORTALAA']);

        $this->stocker()->run();

        $this->assertSame(0, SystemContent::query()->count());
    }

    // ---- topping up, not adding ---------------------------------------------

    /**
     * A NODE THAT STILL HAS ITS CONTENTS IS LEFT ALONE. Running the pass twice
     * is not two hauls, which is the rule the market's supply follows and for
     * the same reason.
     */
    public function test_a_second_pass_adds_nothing_to_a_full_node(): void
    {
        $this->machine();

        $this->stocker()->run();
        $before = SystemContent::query()->count();

        $this->stocker()->run();

        $this->assertSame($before, SystemContent::query()->count());
    }

    /** And an emptied one fills back up, which is what makes robbing it worth doing twice. */
    public function test_an_emptied_node_restocks(): void
    {
        $system = $this->machine();
        $this->stocker()->run();

        $system->contents()->delete();
        $this->stocker()->run();

        $this->assertGreaterThan(0, $system->contents()->count());
    }

    // ---- files ---------------------------------------------------------------

    public function test_a_file_is_sealed_sized_and_worth_more_broken(): void
    {
        Skill::factory()->create(['slug' => 'cinder', 'category' => 'Encryption', 'rank' => 2]);
        Skill::factory()->create(['slug' => 'ouroboros', 'category' => 'Encryption', 'rank' => 16]);

        // Chance at the floor of the roll means every file lands.
        $this->machine(grade: 9);
        $this->stocker(lucky: true)->run();

        $file = SystemContent::query()->ofKind(ContentKind::Data)->sole();

        $this->assertNotNull($file->label, 'a file has a name you remember leaving behind');
        $this->assertGreaterThan(0, $file->size_qb);
        $this->assertNotNull($file->encryption_skill_id, 'everything comes off a system encrypted');
        $this->assertGreaterThan($file->value_sealed, $file->value_broken);
        $this->assertGreaterThan(0, $file->decryptionPremium());
    }

    /** The hardest machines seal their data with the thing nobody can open. */
    public function test_encryption_follows_the_grade(): void
    {
        $cheap = Skill::factory()->create(['slug' => 'cinder', 'category' => 'Encryption', 'rank' => 2]);
        $dear = Skill::factory()->create(['slug' => 'ouroboros', 'category' => 'Encryption', 'rank' => 16]);

        $low = $this->machine(grade: 1);
        $high = $this->machine(grade: 9);

        $this->stocker(lucky: true)->run();

        $this->assertSame($cheap->id, $low->contents()->ofKind(ContentKind::Data)->value('encryption_skill_id'));
        $this->assertSame($dear->id, $high->contents()->ofKind(ContentKind::Data)->value('encryption_skill_id'));
    }

    /** Each encryption turns up only in its own grades, and a file's values come from its grade's bands. */
    public function test_the_four_encryptions_keep_to_their_grades_and_prices(): void
    {
        Config::set('cyberspace.contents.data.chance', array_fill(1, 9, 1000));
        Config::set('cyberspace.contents.by_type.silo.data', 2000);

        $skills = [];
        foreach (['cinder' => 2, 'halcyon3' => 4, 'blackglass' => 8, 'ouroboros' => 16] as $slug => $rank) {
            $skills[$slug] = Skill::factory()->create(['slug' => $slug, 'category' => 'Encryption', 'rank' => $rank])->id;
        }

        $expected = [1 => ['cinder'], 4 => ['cinder', 'halcyon3'], 5 => ['halcyon3'], 7 => ['blackglass'], 8 => ['blackglass', 'ouroboros'], 9 => ['ouroboros']];

        foreach ($expected as $grade => $allowed) {
            $machine = $this->machine(grade: $grade);

            foreach ([true, false] as $lucky) {
                $machine->contents()->delete();
                $this->stocker($lucky)->run();

                $file = $machine->contents()->ofKind(ContentKind::Data)->sole();
                $this->assertContains(array_search($file->encryption_skill_id, $skills), $allowed, "grade {$grade}");

                [$lo, $hi] = config("cyberspace.contents.data.value.{$grade}");
                $this->assertGreaterThanOrEqual($lo, $file->value_sealed);
                $this->assertLessThanOrEqual($hi, $file->value_sealed);

                [$lo, $hi] = config("cyberspace.contents.data.value_broken.{$grade}");
                $this->assertGreaterThanOrEqual($lo, $file->value_broken);
                $this->assertLessThanOrEqual($hi, $file->value_broken);
            }
        }
    }

    /** A world with no encryption skills still stocks files. */
    public function test_a_file_survives_an_empty_encryption_ladder(): void
    {
        $this->machine(grade: 5);

        $this->stocker(lucky: true)->run();

        $this->assertNull(SystemContent::query()->ofKind(ContentKind::Data)->sole()->encryption_skill_id);
    }

    // ---- items ----------------------------------------------------------------

    /**
     * Retired content does not leak back in through the world.
     *
     * Scoped to the retired row rather than counting the kind: a fresh
     * database ships the scanners, and they are listed — so "no items were
     * stocked" stopped being the same statement as "this one was not".
     */
    public function test_an_unlisted_item_is_never_stocked(): void
    {
        $retired = Item::factory()->create(['slug' => 'retired', 'on_market' => false]);
        $this->machine(grade: 9);

        $this->stocker(lucky: true)->run();

        $this->assertSame(
            0,
            SystemContent::query()->ofKind(ContentKind::Item)->where('item_id', $retired->id)->count(),
        );
    }

    public function test_an_item_is_stocked_from_the_listed_catalogue(): void
    {
        Item::factory()->create(['slug' => 'loot', 'on_market' => true]);
        $this->machine(grade: 9);

        $this->stocker(lucky: true)->run();

        // Several, now that the draw repeats — so every one of them is checked
        // rather than the only one there used to be.
        $stocked = SystemContent::query()->ofKind(ContentKind::Item)->get();
        $listed = Item::query()->where('on_market', true)->pluck('id')->all();

        $this->assertNotEmpty($stocked);

        foreach ($stocked as $row) {
            $this->assertContains($row->item_id, $listed);
            $this->assertSame(1, $row->quantity);
        }
    }

    /**
     * NONE TO SEVERAL, rather than none or one. The odds are rolled again
     * after each hit, so a rich machine is rich rather than merely likely.
     */
    public function test_a_lucky_node_holds_several_items(): void
    {
        Item::factory()->create(['slug' => 'loot', 'on_market' => true]);
        Config::set('cyberspace.contents.item.max', 3);
        $this->machine(grade: 9);

        $this->stocker(lucky: true)->run();

        $this->assertSame(3, SystemContent::query()->ofKind(ContentKind::Item)->count());
    }

    // ---- how good the drop is ------------------------------------------------

    /**
     * A NODE HOLDS WHAT A MACHINE CAN HOLD. Software, files, imprints — never a
     * cyberdeck or an implant, because those are objects in the world and a
     * node is not somewhere a player's hands reach.
     */
    public function test_a_node_never_drops_a_physical_item(): void
    {
        Item::factory()->create(['slug' => 'a_deck', 'container' => Item::CONTAINER_PHYSICAL, 'on_market' => true]);
        Item::factory()->create(['slug' => 'a_program', 'container' => Item::CONTAINER_DATA, 'on_market' => true]);

        $this->machine(grade: 9);
        $this->stocker(lucky: true)->run();

        $stocked = SystemContent::query()->ofKind(ContentKind::Item)->pluck('item_id');

        $this->assertNotEmpty($stocked);

        foreach (Item::query()->whereIn('id', $stocked)->get() as $item) {
            $this->assertSame(Item::CONTAINER_DATA, $item->container, "{$item->slug} is not something a node can hold");
        }
    }

    /** The bottom of the grid hardly ever gives anything rare. */
    public function test_a_low_grade_machine_almost_never_drops_rare(): void
    {
        Item::factory()->create(['container' => Item::CONTAINER_DATA, 'on_market' => true, 'rarity' => 'common']);
        Item::factory()->create(['container' => Item::CONTAINER_DATA, 'on_market' => true, 'rarity' => 'rare']);

        $this->machine(grade: 1);

        // The weighted draw is a 1-in-1000 roll for rare at this band, so a
        // roll at the top of the range is the only one that reaches it.
        NodeStocker::fromConfig(static fn (int $min, int $max): int => $min)->run();

        $drops = Item::query()
            ->whereIn('id', SystemContent::query()->ofKind(ContentKind::Item)->pluck('item_id'))
            ->pluck('rarity');

        foreach ($drops as $rarity) {
            $this->assertSame('common', $rarity->value);
        }
    }

    /**
     * And nothing below grade 8 ever gives ultra-rare.
     *
     * Run across every grade the wall covers, with dice that make every item
     * roll hit — so each of those nodes holds its full four items and the only
     * thing keeping the ultra-rare off them is the table.
     */
    public function test_nothing_below_grade_eight_drops_ultra_rare(): void
    {
        $prize = Item::factory()->create([
            'container' => Item::CONTAINER_DATA,
            'on_market' => true,
            'rarity' => 'ultra_rare',
        ]);

        foreach (range(1, 7) as $grade) {
            System::factory()->at($grade, 20)->grade($grade)->create([
                'address' => str_pad((string) $grade, 8, 'B'),
            ]);
        }

        $this->stocker(lucky: true)->run();

        $this->assertGreaterThan(0, SystemContent::query()->ofKind(ContentKind::Item)->count());
        $this->assertSame(
            0,
            SystemContent::query()->ofKind(ContentKind::Item)->where('item_id', $prize->id)->count(),
        );
    }

    /** Weight 0 is a wall, not a long shot. */
    public function test_a_zero_weight_rarity_is_never_drawn(): void
    {
        $this->assertSame(
            0,
            LootTable::query()->where('grade_max', '<=', 7)->where('rarity', 'ultra_rare')->sum('weight'),
        );
    }

    // ---- what a file is called ------------------------------------------------

    /** A silo keeps the records a company must keep; a relay keeps traffic. */
    public function test_a_file_is_named_for_the_machine_it_came_off(): void
    {
        $silo = $this->machine(grade: 5, type: SystemType::Silo);

        System::factory()->at(9, 9)->type(SystemType::Relay)->grade(5)->create(['address' => 'RELAYAAA']);

        $this->stocker(lucky: true)->run();

        $siloFile = $silo->contents()->ofKind(ContentKind::Data)->value('label');
        $relayFile = SystemContent::query()->ofKind(ContentKind::Data)
            ->whereNot('system_id', $silo->id)->value('label');

        $this->assertContains($siloFile, config('strings.cyberspace.files.words.silo'));
        $this->assertContains($relayFile, config('strings.cyberspace.files.words.relay'));
    }

    // ---- a machine that was told what to hold -------------------------------

    /**
     * A MISSION CANNOT BE BUILT ON THE WORLD HAPPENING TO PUT THE RIGHT THING
     * IN THE RIGHT PLACE. A rule says exactly what is there, and replaces the
     * draw rather than adding to it.
     */
    public function test_a_told_machine_holds_exactly_what_it_was_told(): void
    {
        $wanted = Item::factory()->create(['slug' => 'the_prize', 'on_market' => false]);
        $system = $this->machine(grade: 9);

        $system->update(['stock_rule' => [
            'fixed' => [['item_id' => $wanted->id, 'quantity' => 2]],
        ]]);

        $this->stocker(lucky: true)->run();

        $stocked = SystemContent::query()->ofKind(ContentKind::Item)->get();

        $this->assertCount(1, $stocked, 'the rule replaced the draw');
        $this->assertSame($wanted->id, $stocked->first()->item_id);
        $this->assertSame(2, $stocked->first()->quantity);
    }

    /** Unlisted is no obstacle: a quest reward is not catalogue content. */
    public function test_a_told_machine_may_hold_something_nobody_can_buy(): void
    {
        $wanted = Item::factory()->create(['slug' => 'the_prize', 'on_market' => false]);
        $system = $this->machine(grade: 1);

        $system->update(['stock_rule' => ['fixed' => [['item_id' => $wanted->id, 'quantity' => 1]]]]);

        $this->stocker(lucky: false)->run();

        $this->assertSame($wanted->id, SystemContent::query()->ofKind(ContentKind::Item)->sole()->item_id);
    }

    /** "One or more, from this set" — drawn without replacement. */
    public function test_a_machine_can_be_told_to_pick_from_a_set(): void
    {
        $set = Item::factory()->count(3)->create(['on_market' => false])->pluck('id')->all();
        $system = $this->machine(grade: 5);

        $system->update(['stock_rule' => ['any_of' => ['from' => $set, 'count' => 2]]]);

        $this->stocker(lucky: true)->run();

        $stocked = SystemContent::query()->ofKind(ContentKind::Item)->pluck('item_id')->all();

        $this->assertCount(2, $stocked);
        $this->assertCount(2, array_unique($stocked), 'two of these three means two DIFFERENT ones');
        $this->assertEmpty(array_diff($stocked, $set));
    }

    /** Asking for more than the set holds gives the set, not an error. */
    public function test_a_set_cannot_be_drawn_past_its_end(): void
    {
        $set = Item::factory()->count(2)->create(['on_market' => false])->pluck('id')->all();
        $system = $this->machine(grade: 5);

        $system->update(['stock_rule' => ['any_of' => ['from' => $set, 'count' => 5]]]);

        $this->stocker(lucky: true)->run();

        $this->assertSame(2, SystemContent::query()->ofKind(ContentKind::Item)->count());
    }

    /** A node that should look ordinary while carrying the one thing that matters. */
    public function test_a_told_machine_can_keep_its_ordinary_draw(): void
    {
        Item::factory()->create(['slug' => 'loot', 'on_market' => true]);
        Config::set('cyberspace.contents.item.max', 1);

        $wanted = Item::factory()->create(['slug' => 'the_prize', 'on_market' => false]);
        $system = $this->machine(grade: 9);

        $system->update(['stock_rule' => [
            'fixed' => [['item_id' => $wanted->id, 'quantity' => 1]],
            'plus_random' => true,
        ]]);

        $this->stocker(lucky: true)->run();

        $stocked = SystemContent::query()->ofKind(ContentKind::Item)->pluck('item_id')->all();

        $this->assertContains($wanted->id, $stocked);
        $this->assertGreaterThan(1, count($stocked), 'and something the world put there too');
    }

    /**
     * An id the catalogue has lost is skipped rather than fatal: a mission
     * whose reward was deleted should fail to give a reward, not take the
     * nightly restock of the whole grid down with it.
     */
    public function test_a_rule_naming_nothing_stocks_nothing_and_does_not_throw(): void
    {
        $system = $this->machine(grade: 5);
        $system->update(['stock_rule' => ['fixed' => [['item_id' => 0, 'quantity' => 1]]]]);

        $this->stocker(lucky: true)->run();

        $this->assertSame(0, SystemContent::query()->ofKind(ContentKind::Item)->count());
        $this->assertSame(1, $system->contents()->ofKind(ContentKind::Creds)->count(), 'the rest of it still stocked');
    }

    /** Unlucky dice mean a node holding nothing but its creds. */
    public function test_a_poor_node_holds_only_creds(): void
    {
        Item::factory()->create(['slug' => 'loot', 'on_market' => true]);
        $this->machine(grade: 1);

        $this->stocker(lucky: false)->run();

        $this->assertSame(1, SystemContent::query()->count());
        $this->assertSame(ContentKind::Creds, SystemContent::query()->sole()->kind);
    }

    // ---- the counter -----------------------------------------------------------

    /** On the SYSTEM and not on the character: three raids by anybody. */
    public function test_a_machine_starts_unraided(): void
    {
        // Read back, because the default is the column's and not the model's.
        $this->assertSame(0, $this->machine()->fresh()->raids);
    }

    // ---- the dry run -------------------------------------------------------------

    public function test_a_dry_run_works_it_all_out_and_writes_nothing(): void
    {
        $this->machine(grade: 4);

        $result = $this->stocker()->run(dryRun: true);

        $this->assertSame(1, $result->nodes);
        $this->assertGreaterThan(0, $result->creds);
        $this->assertSame(0, SystemContent::query()->count());
    }

    /** Type leans what a machine keeps: a silo hoards files, a compute node money. */
    public function test_type_leans_the_odds(): void
    {
        Config::set('cyberspace.contents.data.chance', array_fill(1, 9, 500));
        Config::set('cyberspace.contents.by_type.silo.data', 2000);
        Config::set('cyberspace.contents.by_type.compute.data', 0);

        $silo = $this->machine(grade: 5, type: SystemType::Silo);
        $compute = $this->machine(grade: 6, type: SystemType::Compute);

        // Dice in the middle: the lean is what decides these two.
        NodeStocker::fromConfig(static fn (int $min, int $max): int => (int) (($min + $max) / 2))->run();

        $this->assertSame(1, $silo->contents()->ofKind(ContentKind::Data)->count());
        $this->assertSame(0, $compute->contents()->ofKind(ContentKind::Data)->count());
    }
}
