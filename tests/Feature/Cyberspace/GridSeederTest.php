<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\GridSeeder;
use App\Cyberspace\Position;
use App\Cyberspace\SystemType;
use App\Models\System;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Instantiating the world.
 *
 * The pass is a TOP-UP to a target, like the market's, and for the same reason:
 * a generator that adds is a generator you can only run once. What is already
 * standing counts, so a second pass does nothing and a pass after a burn
 * replaces exactly what was lost.
 */
final class GridSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 8);       // 64 positions
        Config::set('cyberspace.occupancy', 50); // target 32, and 32 free
    }

    /**
     * NOT called seeder(): Illuminate's CanConfigureMigrationCommands defines
     * a protected seeder() on every test case, and overriding it hands
     * RefreshDatabase this object where it expects a seeder class name.
     */
    private function generator(): GridSeeder
    {
        return GridSeeder::fromConfig(fn (int $min, int $max): int => random_int($min, $max));
    }

    public function test_it_fills_the_grid_to_the_target(): void
    {
        $result = $this->generator()->run();

        $this->assertSame(64, $result->positions);
        $this->assertSame(32, $result->target);
        $this->assertSame(32, System::query()->count());
        $this->assertFalse($result->gridFull);
    }

    /** A world is created once. Running it twice is not two worlds. */
    public function test_a_second_pass_over_a_full_world_changes_nothing(): void
    {
        $this->generator()->run();
        $addresses = System::query()->orderBy('id')->pluck('address')->all();

        $second = $this->generator()->run();

        $this->assertSame(0, $second->created);
        $this->assertSame($addresses, System::query()->orderBy('id')->pluck('address')->all());
    }

    /**
     * The world comes out defended, and the portal does not.
     *
     * Every generated machine guarantees two countermeasures by its type, so
     * nothing reaches the grid undefended however the presence roll lands —
     * and the portal is exempt for the reason it is exempt from everything.
     */
    public function test_the_world_arrives_with_its_defences_decided(): void
    {
        $this->generator()->run();

        $ordinary = System::query()->where('type', '!=', SystemType::Portal->value)->get();

        $this->assertNotEmpty($ordinary);

        foreach ($ordinary as $system) {
            $this->assertTrue(
                $system->hasIds() || $system->hasTracer(),
                "a {$system->type->value} reached the grid with nothing watching it",
            );
        }

        $portal = System::query()->where('type', SystemType::Portal->value)->first();

        if ($portal !== null) {
            $this->assertFalse($portal->hasIds());
            $this->assertFalse($portal->hasTracer());
        }
    }

    public function test_it_replaces_exactly_what_a_burn_took(): void
    {
        $this->generator()->run();
        System::query()->where('type', '!=', SystemType::Portal->value)->limit(5)->delete();

        $result = $this->generator()->run();

        $this->assertSame(5, $result->created);
        $this->assertSame(32, System::query()->count());
    }

    public function test_there_is_exactly_one_portal_and_it_stands_in_the_middle(): void
    {
        $this->generator()->run();

        $portals = System::query()->where('type', SystemType::Portal->value)->get();

        $this->assertCount(1, $portals);
        $this->assertTrue($portals->first()->position()->equals(new Position(4, 4)));
        $this->assertSame(Text::get('cyberspace.portal.name'), $portals->first()->name);
    }

    /**
     * A machine standing where the portal goes is MOVED, never overwritten. It
     * is somebody's bookmark, and a system that vanished is a different story
     * from one that relocated.
     */
    public function test_a_system_in_the_portals_place_is_moved_aside(): void
    {
        $squatter = System::factory()->at(4, 4)->create();

        $result = $this->generator()->run();

        $this->assertTrue($result->occupantMoved);

        $squatter->refresh();
        $this->assertFalse($squatter->position()->equals(new Position(4, 4)));
        $portal = System::query()->where('x', 4)->where('y', 4)->first();
        $this->assertNotNull($portal);
        $this->assertSame(SystemType::Portal, $portal->type);
    }

    public function test_every_system_has_its_own_address_and_its_own_position(): void
    {
        $this->generator()->run();

        $systems = System::query()->get();

        $this->assertCount(32, $systems->pluck('address')->unique());
        $this->assertCount(32, $systems->map(fn (System $s): string => (string) $s->position())->unique());

        foreach ($systems as $system) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{8}$/', $system->address);
            $this->assertNotSame('', trim($system->displayName()));
        }
    }

    /**
     * A generated machine stores NO name and a serial; the portal is the one
     * that stores a name and no serial.
     */
    public function test_generated_systems_are_named_for_their_owner_and_the_portal_is_authored(): void
    {
        $this->generator()->run();

        $generated = System::query()
            ->where('type', '!=', SystemType::Portal->value)
            ->get();

        foreach ($generated as $system) {
            $this->assertNull($system->name);
            $this->assertNotNull($system->designation);
            $this->assertGreaterThanOrEqual(1, $system->designation);
            $this->assertLessThanOrEqual(999, $system->designation);
        }

        $portal = System::query()->where('type', SystemType::Portal->value)->sole();
        $this->assertSame(Text::get('cyberspace.portal.name'), $portal->name);
        $this->assertNull($portal->designation);
    }

    /** What the player reads: the type, who runs it, and the serial. */
    public function test_a_generated_name_reads_as_type_owner_and_serial(): void
    {
        $this->generator()->run();

        $system = System::query()
            ->where('type', '!=', SystemType::Portal->value)
            ->whereNotNull('owner_id')
            ->with('owner')
            ->firstOrFail();

        $this->assertSame(
            $system->type->label().' - '.$system->owner->name.' '.str_pad((string) $system->designation, 3, '0', STR_PAD_LEFT),
            $system->displayName(),
        );
    }

    public function test_a_dry_run_works_the_whole_thing_out_and_writes_nothing(): void
    {
        $result = $this->generator()->run(dryRun: true);

        // `created` is what the FILL would instantiate; the portal is reported
        // on its own line because it is placed rather than rolled. The two
        // together are the target, and a real pass reports the same pair.
        $this->assertTrue($result->portalPlaced);
        $this->assertSame(31, $result->created);
        $this->assertSame(32, $result->occupiedAfter());

        $this->assertSame(0, System::query()->count());
    }

    public function test_the_command_reports_what_it_did(): void
    {
        $this->artisan('cyberspace:seed --dry-run')
            ->expectsOutputToContain('[dry run]')
            ->assertSuccessful();

        $this->assertSame(0, System::query()->count());

        $this->artisan('cyberspace:seed')->assertSuccessful();

        $this->assertSame(32, System::query()->count());
    }

    /**
     * A world with no empty reserve at all is not a world the design wants —
     * relocation needs somewhere to go — but the arithmetic has to land on it
     * exactly rather than one short.
     */
    public function test_a_world_with_no_empty_space_is_filled_exactly(): void
    {
        Config::set('cyberspace.size', 2);
        Config::set('cyberspace.occupancy', 100);

        $result = $this->generator()->run();

        $this->assertSame(4, System::query()->count());
        $this->assertFalse($result->gridFull, 'exactly full is not short');
    }
}
