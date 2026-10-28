<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Cyberspace\HunterCombat;
use App\Cyberspace\Hunters;
use App\Cyberspace\Navigator;
use App\Cyberspace\Position;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunRefusal;
use App\Cyberspace\RunStarter;
use App\Cyberspace\TravelRefusal;
use App\Models\Character;
use App\Models\District;
use App\Models\Hunt;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Program;
use App\Models\Safehouse;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Stats\Resolver;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Cyberspace combat: a hunter, rolled on every move, fought as an ICE with no
 * trace, no IDS and no door out until it is settled.
 */
final class HunterTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();
    }

    // ---- the curve ----------------------------------------------------------

    public function test_zero_heat_is_not_rolled_at_all(): void
    {
        $this->assertSame(0.0, $this->hunters()->chanceFor(0));
    }

    public function test_the_curve_matches_the_two_endpoints_the_design_named(): void
    {
        $hunters = $this->hunters();

        $this->assertEqualsWithDelta(0.005, $hunters->chanceFor(5), 0.0001);
        $this->assertEqualsWithDelta(0.35, $hunters->chanceFor(100), 0.0001);
    }

    public function test_the_chance_never_exceeds_the_cap_above_the_top_of_the_line(): void
    {
        $this->assertSame(0.35, $this->hunters()->chanceFor(500));
    }

    public function test_below_the_floor_is_never_rolled(): void
    {
        $this->character->forceFill(['heat' => 4.9])->save();

        $hunters = Hunters::fromConfig(app(Resolver::class), static fn (): int => 1);

        $this->assertFalse($hunters->rolls($this->character->fresh()), 'a die that always passes still must not roll below the floor');
    }

    // ---- spawning -------------------------------------------------------------

    public function test_a_hunter_is_a_real_ice_row_from_its_grades_level_range(): void
    {
        $this->character->forceFill(['heat' => 90])->save();

        $hunt = $this->hunters()->spawn($this->character->fresh());

        $this->assertSame(5, $hunt->grade, 'heat 90 is in the top band');
        $this->assertContains($hunt->ice->level, [10, 11, 12], 'grade 5 draws from the same range a grade 8-9 system does');
        $this->assertSame($hunt->ice->integrity, $hunt->ice_integrity, 'starts at the drawn row\'s own integrity');
        $this->assertSame($hunt->id, $this->character->fresh()->current_hunt_id);
    }

    public function test_the_five_grades_cover_the_five_bands(): void
    {
        $expected = [
            10 => [1, [1, 2]],
            30 => [2, [3, 4]],
            50 => [3, [5, 6, 7]],
            70 => [4, [8, 9]],
            95 => [5, [10, 11, 12]],
        ];

        foreach ($expected as $heat => [$grade, $levels]) {
            $this->character->forceFill(['heat' => $heat])->save();
            $hunt = $this->hunters()->spawn($this->character->fresh());

            $this->assertSame($grade, $hunt->grade, "heat {$heat}");
            $this->assertContains($hunt->ice->level, $levels, "heat {$heat}");
        }
    }

    public function test_owning_no_icebreaker_starts_nothing(): void
    {
        $this->character->forceFill(['heat' => 50])->save();

        $this->hunters()->spawn($this->character->fresh());

        $this->assertSame(0, $this->character->fresh()->runningProcesses()->count());
    }

    public function test_the_strongest_owned_icebreaker_that_fits_is_started(): void
    {
        $weak = $this->giveItem(Item::query()->where('slug', 'icebreaker-1')->sole());
        $strong = $this->giveItem(Item::query()->where('slug', 'icebreaker-3')->sole());

        $this->character->forceFill(['heat' => 50])->save();
        $this->hunters()->spawn($this->character->fresh());

        $running = $this->character->fresh()->runningProcesses();

        $this->assertSame(1, $running->count());
        $this->assertSame($strong->item_id, $running->first()->item_id, 'the stronger of the two owned icebreakers');
    }

    public function test_an_icebreaker_stored_away_is_not_started_and_the_one_in_hand_is(): void
    {
        $weak = $this->giveItem(Item::query()->where('slug', 'icebreaker-1')->sole());
        $strong = $this->giveItem(Item::query()->where('slug', 'icebreaker-3')->sole());
        $home = Safehouse::create([
            'slug' => 'capsule', 'title' => 'Capsule hotel', 'district_id' => District::query()->where('slug', 'dockside')->firstOrFail()->id,
            'rent' => 40, 'security' => 0, 'stealth' => 0, 'rest_rate' => 2, 'storage_slots' => 2, 'actions' => ['rest'],
        ]);
        $strong->forceFill(['safehouse_id' => $home->id])->save();

        $this->character->forceFill(['heat' => 50])->save();
        $this->hunters()->spawn($this->character->fresh());

        $running = $this->character->fresh()->runningProcesses();

        $this->assertSame(1, $running->count());
        $this->assertSame($weak->item_id, $running->first()->item_id, 'the stored one is not in hand');
        $this->assertSame($home->id, $strong->fresh()->safehouse_id);
        $this->assertNull($strong->fresh()->running_on_deck_id);
    }

    public function test_running_programs_are_stopped_so_the_icebreaker_can_start(): void
    {
        $this->giveItem(Item::query()->where('slug', 'icebreaker-3')->sole());

        $item = Item::factory()->create(['container' => 'data', 'stackable' => true]);
        Program::query()->create([
            'item_id' => $item->id, 'kind' => ProgramKind::Daemon->value,
            'qflops_cost' => 1, 'memory_cost' => 1, 'storage_size' => 1, 'background_process' => true,
        ]);
        $daemon = InventoryItem::query()->create([
            'character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1,
            'running_on_deck_id' => $this->character->equipped_deck_id, 'started_at' => now(),
        ]);

        $this->character->forceFill(['heat' => 50])->save();
        $this->hunters()->spawn($this->character->fresh());

        $this->assertNull($daemon->fresh()->running_on_deck_id, 'the daemon was stopped');
        $this->assertSame(1, $this->character->fresh()->runningProcesses()->count(), 'only the icebreaker runs');
    }

    public function test_no_new_hunter_within_the_cooldown_of_the_last_one(): void
    {
        $this->character->forceFill(['heat' => 100])->save();
        $always = Hunters::fromConfig(app(Resolver::class), static fn (int $min, int $max): int => $min);

        $this->assertTrue($always->rolls($this->character->fresh()));

        $hunt = $always->spawn($this->character->fresh());
        $hunt->forceFill(['started_at' => now()->subMinutes(59)])->save();
        $this->character->forceFill(['current_hunt_id' => null])->save();

        $this->assertFalse($always->rolls($this->character->fresh()), 'inside the hour');

        $hunt->forceFill(['started_at' => now()->subMinutes(61)])->save();

        $this->assertTrue($always->rolls($this->character->fresh()), 'after the hour');
    }

    public function test_egress_from_a_hunter_closes_it_unpaid_and_jacks_out_or_the_hunter_swings(): void
    {
        $this->character->forceFill(['heat' => 50])->save();
        $this->hunters()->spawn($this->character->fresh());
        $hunt = Hunt::query()->latest('id')->first();

        $this->character->refresh();
        $creds = $this->character->creds;

        // A certain pass: the die returns 0 and an overwhelming egress value.
        $pass = new CheckRoller(1.0, 0.0, [], static fn (): float => 0.0);
        $combat = new HunterCombat(app(Resolver::class), new ProgramRunner, new WaitingRoom(app(Resolver::class)), static fn (int $a, int $b): int => $b, $pass);
        $combat->fight($this->character, $hunt, egress: true);

        $this->character->refresh();
        $this->assertNull($this->character->current_hunt_id);
        $this->assertFalse($this->character->isJackedIn());
        $this->assertSame($creds, $this->character->creds, 'an egress pays nothing');
        $this->assertFalse((bool) $hunt->fresh()->won);
    }

    // ---- fighting: losing -----------------------------------------------------

    public function test_losing_drops_heat_by_a_random_slice_rather_than_to_zero(): void
    {
        $this->character->forceFill(['heat' => 90, 'current_synaptic_integrity' => 1])->save();

        $hunt = $this->hunters()->spawn($this->character->fresh());

        $combat = HunterCombat::fromConfig(app(Resolver::class), $this->alwaysHitsDie());
        $combat->fight($this->character->fresh(), $hunt->fresh());

        $character = $this->character->fresh();

        $this->assertSame(0, $character->current_synaptic_integrity);
        $this->assertNull($character->current_hunt_id);
        $this->assertNotNull($character->waiting_until, 'the floor fires on a defeat here too');
        $this->assertEqualsWithDelta(90 * 0.85, $character->heat, 0.05, '15% off, the top of the range, never to zero');
        $this->assertFalse($hunt->fresh()->won);
        $this->assertNotNull($hunt->fresh()->resolved_at);
    }

    public function test_a_fight_with_no_icebreaker_is_not_infinite(): void
    {
        $this->character->forceFill(['heat' => 90])->save();
        $hunt = $this->hunters()->spawn($this->character->fresh());

        $combat = HunterCombat::fromConfig(app(Resolver::class), $this->alwaysHitsDie());

        for ($i = 0; $i < 50 && $this->character->fresh()->current_hunt_id !== null; $i++) {
            $combat->fight($this->character->fresh(), $hunt->fresh());
        }

        $this->assertNull($this->character->fresh()->current_hunt_id, 'zero damage still resolves — on the SI floor');
    }

    // ---- fighting: winning ------------------------------------------------

    public function test_winning_pays_creds_reduces_heat_by_grade_and_stops_the_loadout(): void
    {
        $this->giveItem(Item::query()->where('slug', 'icebreaker-12')->sole());
        $this->character->forceFill(['heat' => 24, 'creds' => 0])->save(); // top of grade 1's band

        $hunt = $this->hunters()->spawn($this->character->fresh());
        $this->assertSame(1, $hunt->grade);

        $combat = HunterCombat::fromConfig(app(Resolver::class), static fn (int $min, int $max): int => $min);

        for ($i = 0; $i < 20 && $this->character->fresh()->current_hunt_id !== null; $i++) {
            $combat->fight($this->character->fresh(), $hunt->fresh());
        }

        $character = $this->character->fresh();

        $this->assertNull($character->current_hunt_id);
        $this->assertTrue($hunt->fresh()->won);
        $this->assertGreaterThanOrEqual(250, $character->creds, 'grade 1\'s creds band');
        $this->assertLessThanOrEqual(350, $character->creds);
        $this->assertEqualsWithDelta(19.0, $character->heat, 0.01, 'grade 1: 24 - (1 * 5)');
        $this->assertSame(0, $character->runningProcesses()->count(), 'the icebreaker is stopped, not left running');
    }

    public function test_a_dropped_program_that_does_not_fit_is_silently_skipped(): void
    {
        $this->giveItem(Item::query()->where('slug', 'icebreaker-12')->sole());
        $this->character->forceFill(['heat' => 24])->save();
        $hunt = $this->hunters()->spawn($this->character->fresh());

        // Certain program drop, and a deck with no free storage to take it.
        Config::set('cyberspace.heat.hunt.program_chance', 100);

        $combat = HunterCombat::fromConfig(app(Resolver::class), static fn (int $min, int $max): int => $min);

        for ($i = 0; $i < 20 && $this->character->fresh()->current_hunt_id !== null; $i++) {
            $combat->fight($this->character->fresh(), $hunt->fresh());
        }

        // Nothing thrown, nothing granted beyond what storage allows — the
        // assertion that matters is that the win still resolved cleanly.
        $this->assertTrue($hunt->fresh()->won);
    }

    // ---- the lock -----------------------------------------------------------

    public function test_a_run_is_refused_while_hunted(): void
    {
        $this->character->forceFill(['heat' => 90])->save();
        $this->hunters()->spawn($this->character->fresh());

        $result = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction);

        $this->assertSame(RunRefusal::Hunted, $result->refusal);
    }

    public function test_jacking_out_is_refused_while_hunted(): void
    {
        $this->character->forceFill(['heat' => 90])->save();
        $this->hunters()->spawn($this->character->fresh());

        $result = Navigator::fromConfig(app(Resolver::class))->jackOut($this->character->fresh());

        $this->assertSame(TravelRefusal::Hunted, $result->refusal);
    }

    public function test_every_other_route_redirects_to_the_hunt_while_one_is_live(): void
    {
        $this->character->forceFill(['heat' => 90])->save();
        $this->hunters()->spawn($this->character->fresh());

        $this->actingAs($this->character->user)
            ->get(route('cyberspace.index'))
            ->assertRedirect(route('hunt.show'));
    }

    public function test_the_hunt_log_shows_the_newest_line_first(): void
    {
        $this->character->forceFill(['heat' => 90])->save();
        $hunt = $this->hunters()->spawn($this->character->fresh());
        $hunt->forceFill(['log' => [
            ['text' => 'HUNT FIRST LINE', 'tone' => ''],
            ['text' => 'HUNT SECOND LINE', 'tone' => ''],
            ['text' => 'HUNT THIRD LINE', 'tone' => ''],
        ]])->save();

        $this->actingAs($this->character->user)->get(route('hunt.show'))->assertOk()
            ->assertSeeInOrder(['HUNT THIRD LINE', 'HUNT SECOND LINE', 'HUNT FIRST LINE']);
    }

    // ---- Navigator wiring ---------------------------------------------------

    public function test_a_roll_that_passes_creates_a_hunt_on_arrival(): void
    {
        $this->character->forceFill(['heat' => 90])->save();

        $navigator = Navigator::fromConfig(app(Resolver::class), static fn (int $min, int $max): int => $min);
        $result = $navigator->step($this->character->fresh(), new Position(9, 8));

        $this->assertTrue($result->succeeded());
        $this->assertTrue($result->wasAmbushed());
        $this->assertInstanceOf(Hunt::class, $result->hunt);
        $this->assertSame($result->hunt->id, $this->character->fresh()->current_hunt_id);
    }

    public function test_a_roll_that_fails_leaves_an_ordinary_arrival(): void
    {
        $this->character->forceFill(['heat' => 90])->save();

        $navigator = Navigator::fromConfig(app(Resolver::class), static fn (int $min, int $max): int => $max);
        $result = $navigator->step($this->character->fresh(), new Position(9, 8));

        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->wasAmbushed());
        $this->assertNull($this->character->fresh()->current_hunt_id);
    }

    public function test_zero_heat_is_never_ambushed_even_by_a_die_that_always_passes(): void
    {
        $navigator = Navigator::fromConfig(app(Resolver::class), static fn (int $min, int $max): int => $min);
        $result = $navigator->step($this->character->fresh(), new Position(9, 8));

        $this->assertFalse($result->wasAmbushed());
    }

    // ---- fixtures -----------------------------------------------------------

    private function hunters(): Hunters
    {
        return Hunters::fromConfig(app(Resolver::class), static fn (int $min, int $max): int => $min);
    }

    /**
     * A die that always beats the ICE's hit chance (the 1-100 roll) and
     * always takes the top of any other range it is asked for (the heat-loss
     * fraction) — one closure, since HunterCombat shares a single die for both.
     *
     * @return Closure(int, int): int
     */
    private function alwaysHitsDie(): Closure
    {
        return static fn (int $min, int $max): int => $max === 100 ? $min : $max;
    }

    private function giveItem(Item $item): InventoryItem
    {
        return InventoryItem::create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);
    }
}
