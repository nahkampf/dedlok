<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\ContentKind;
use App\Cyberspace\NodeStocker;
use App\Cyberspace\SystemType;
use App\Models\Item;
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

        $stocked = SystemContent::query()->ofKind(ContentKind::Item)->sole();

        $this->assertContains(
            $stocked->item_id,
            Item::query()->where('on_market', true)->pluck('id')->all(),
        );
        $this->assertSame(1, $stocked->quantity);
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
