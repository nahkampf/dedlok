<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\RaidLedger;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemContent;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * A node counts its raids, and the third one retires it.
 *
 * Every roll is pinned, including the one that decides where a retired machine
 * moves to — relocation draws a free position, and a test that let it draw
 * randomly would be a test about a 16 x 16 grid's luck.
 */
final class RaidLedgerTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.raids.limit', 3);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->equipReadyDeck($this->character);

        $this->character->forceFill([
            'grid_x' => 8,
            'grid_y' => 8,
            // Both standing instructions off: this is a test about the books,
            // not about being pulled out before the raid lands.
            'egress_trace_at' => 0,
            'egress_si_at' => 0,
        ])->save();
        $this->character->refresh();
    }

    // ---- counting ----------------------------------------------------------

    public function test_a_new_machine_has_never_been_raided(): void
    {
        // Read back rather than trusted in memory: the default lives on the
        // column, so a factory that never mentioned it returns null.
        $this->assertSame(0, $this->machine()->fresh()->raids);
    }

    public function test_taking_something_counts_a_raid(): void
    {
        $system = $this->machine();
        $this->stock($system, 500);

        $this->raid();

        $this->assertSame(1, $system->fresh()->raids);
    }

    /**
     * NOTHING TAKEN IS NOT A RAID. Walking in, finding the shelf bare and
     * walking out again leaves the books where they were — the counter is what
     * stops a working loop being an annuity, and a loop that returned nothing
     * is not the loop it exists to brake.
     */
    public function test_an_empty_machine_is_not_raided(): void
    {
        $system = $this->machine();

        $this->raid();

        $this->assertSame(0, $system->fresh()->raids);
    }

    /**
     * ONE RAID PER RUN, not one per turn that took something. A haul that
     * pockets the creds and then spends turns pulling a file is one visit.
     */
    public function test_a_haul_spread_over_turns_is_one_raid(): void
    {
        $system = $this->machine();
        $this->stock($system, 500);
        SystemContent::query()->create([
            'system_id' => $system->id,
            'kind' => 'data',
            'size_qb' => 400,
            'encryption_skill_id' => null,
            'sealed_value' => 100,
            'broken_value' => 900,
            'label' => 'Ledger fragment',
        ]);

        $run = $this->begin();
        $simulator = $this->simulator();

        // Turn one takes the creds and starts the transfer; the file is far
        // bigger than a turn's bandwidth, so this runs for several.
        $simulator->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $this->assertSame(1, $system->fresh()->raids, 'the first take is the raid');

        for ($turn = 0; $turn < 12; $turn++) {
            $live = $run->fresh();

            if (! $live->isLive()) {
                break;
            }

            $simulator->take($this->character->fresh(), $live, RunAction::Extract);
        }

        $this->assertNull($run->fresh()->extracting_qb, 'the transfer finished');
        $this->assertSame(1, $system->fresh()->raids, 'still one visit');
    }

    // ---- retiring ----------------------------------------------------------

    /** Below the limit nothing moves: two good nights are two good nights. */
    public function test_a_machine_under_the_limit_stays_where_it_is(): void
    {
        $system = $this->machine();
        $system->forceFill(['raids' => 1])->save();
        $this->stock($system, 100);

        $this->raid();
        $this->egress();

        $fresh = $system->fresh();

        $this->assertSame(2, $fresh->raids);
        $this->assertSame(8, $fresh->x);
        $this->assertSame(8, $fresh->y);
        $this->assertSame('DEADBEEF', $fresh->address);
    }

    /**
     * THE THIRD RAID RETIRES IT: new position, new address, books balanced.
     * Somebody eventually reconciles the numbers.
     */
    public function test_the_third_raid_relocates_the_machine(): void
    {
        $system = $this->machine();
        $system->forceFill(['raids' => 2])->save();
        $this->stock($system, 100);

        $this->raid();
        $this->egress();

        $fresh = $system->fresh();

        $this->assertSame(0, $fresh->raids, 'a relocated machine has new books');
        $this->assertNotSame('DEADBEEF', $fresh->address, 'and a new address');
        $this->assertNotSame([8, 8], [$fresh->x, $fresh->y], 'and it is somewhere else');
    }

    /**
     * IT GOES WHEN THE RAIDER IS OUT, not on the turn the shelf empties. The
     * machine moving out from under a live run is a different ending
     * (`RunEnding::Cancelled`) and not this one's to deal.
     */
    public function test_it_does_not_move_while_the_raider_is_still_inside(): void
    {
        $system = $this->machine();
        $system->forceFill(['raids' => 2])->save();
        $this->stock($system, 100);

        $this->raid();

        $fresh = $system->fresh();

        $this->assertSame(3, $fresh->raids, 'the books say it is spent');
        $this->assertSame('DEADBEEF', $fresh->address, 'and it is still standing here');
    }

    /**
     * A STRANGER'S RUN HOLDS IT IN PLACE TOO, and the counter waits rather
     * than being spent — the next run to end on the machine retires it.
     */
    public function test_somebody_else_still_inside_holds_the_machine(): void
    {
        $system = $this->machine();
        $system->forceFill(['raids' => 3])->save();

        Run::query()->create([
            'character_id' => Character::factory()->create(['archetype' => 3])->id,
            'address' => 'DEADBEEF',
            'x' => 8,
            'y' => 8,
            'purpose' => RunPurpose::Extraction->value,
            'turn' => 1,
            'started_at' => now(),
        ]);

        $this->assertFalse(RaidLedger::fromConfig($this->die())->retireIfSpent('DEADBEEF'));
        $this->assertSame('DEADBEEF', $system->fresh()->address);
    }

    /** An address that resolves to nothing is an ordinary answer, not an error. */
    public function test_an_address_with_no_machine_behind_it_is_a_no_op(): void
    {
        $ledger = RaidLedger::fromConfig($this->die());

        $ledger->record('C0FFEE01');

        $this->assertFalse($ledger->retireIfSpent('C0FFEE01'));
    }

    /** Every ending retires it, not only a tidy one: the counter is spent either way. */
    public function test_the_counter_decides_and_not_the_ending(): void
    {
        $system = $this->machine();
        $system->forceFill(['raids' => 3])->save();

        $run = $this->begin();
        app(RunStarter::class)->end($this->character->fresh(), $run->fresh(), RunEnding::Defeat);

        $this->assertNotSame('DEADBEEF', $system->fresh()->address);
    }

    // ---- fixtures ----------------------------------------------------------

    /** A machine nothing guards, so a raid is about the books and not a fight. */
    private function machine(): System
    {
        return System::factory()->at(8, 8)->grade(1)->create(['address' => 'DEADBEEF']);
    }

    private function stock(System $system, int $creds): void
    {
        SystemContent::query()->create([
            'system_id' => $system->id,
            'kind' => 'creds',
            'amount' => $creds,
        ]);
    }

    /**
     * A die pinned high: every roll comes back at the top of its range, which
     * is what `freePosition()` uses to pick the far corner of the grid rather
     * than wherever chance sent it.
     */
    private function die(): callable
    {
        return static fn (int $min, int $max): int => $max;
    }

    private function simulator(): Simulator
    {
        return Simulator::fromConfig($this->die(), app(Resolver::class));
    }

    private function begin(): Run
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        return app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
    }

    /** Begin a run, take what the machine is holding, and leave the run live. */
    private function raid(): Run
    {
        $run = $this->begin();

        $this->simulator()->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        return $run->fresh();
    }

    private function egress(): void
    {
        $this->simulator()->take(
            $this->character->fresh(),
            Run::query()->live()->where('character_id', $this->character->id)->sole(),
            RunAction::Egress,
        );
    }
}
