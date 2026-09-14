<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\MapView;
use App\Cyberspace\Position;
use App\Cyberspace\Sweep;
use App\Models\Character;
use App\Models\System;
use App\Models\SystemContact;
use App\Models\User;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Spotting the one machine that was not there last time.
 *
 * The case the whole feature exists for is the last one in this file: a system
 * that RELOCATED INTO a position the player has already swept. Everything else
 * — a first visit, a machine you have looked at twice, one that has been there
 * for an hour — is only there to make sure the mark stays quiet the rest of the
 * time. A map where everything is permanently new says nothing.
 */
final class NewSystemMarkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);
        Config::set('cyberspace.map_range', 4);
        Config::set('cyberspace.new_for_minutes', 10);

        $this->user = User::factory()->create();
        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $this->user->id, 'archetype' => 3],
        ));
    }

    private function sweep(): void
    {
        Sweep::fromConfig()->from($this->character->fresh(), new Position(8, 8));
    }

    private function contact(): SystemContact
    {
        return SystemContact::query()->where('character_id', $this->character->id)->sole();
    }

    public function test_a_machine_just_discovered_is_new(): void
    {
        System::factory()->at(8, 9)->create(['address' => 'AAAAAAAA', 'name' => 'Cold Vault']);

        $this->sweep();

        $this->assertTrue($this->contact()->isNew());
        $this->assertNotNull($this->contact()->first_seen_at);
    }

    /** Looking at something again does not make it newer. */
    public function test_looking_twice_does_not_restart_the_clock(): void
    {
        System::factory()->at(8, 9)->create(['address' => 'AAAAAAAA']);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->subMinutes(30));
        $this->sweep();
        $first = $this->contact()->first_seen_at;

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(30));
        $this->sweep();

        $this->assertEquals($first, $this->contact()->first_seen_at, 'the clock is carried, not reset');
        $this->assertFalse($this->contact()->isNew(), 'half an hour is not new');
        $this->assertTrue($this->contact()->seen_at->isAfter($first), 'but it was looked at just now');

        CarbonImmutable::setTestNow();
    }

    public function test_newness_wears_off(): void
    {
        System::factory()->at(8, 9)->create(['address' => 'AAAAAAAA']);
        $this->sweep();

        $this->assertTrue($this->contact()->isNew(10));
        $this->assertFalse($this->contact()->isNew(0), 'a zero-minute window marks nothing');

        SystemContact::query()->update(['first_seen_at' => now()->subMinutes(11)]);

        $this->assertFalse($this->contact()->fresh()->isNew(10));
    }

    /**
     * THE CASE THE FEATURE EXISTS FOR. A stranger moves into a position the
     * player has walked past before, and the map has to say so.
     */
    public function test_a_machine_that_relocated_into_known_ground_is_new(): void
    {
        $old = System::factory()->at(8, 9)->create(['address' => 'AAAAAAAA', 'name' => 'Cold Vault']);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->subHour());
        $this->sweep();
        $before = $this->contact()->first_seen_at;
        CarbonImmutable::setTestNow();

        $this->assertFalse($this->contact()->isNew(), 'an hour old');

        // It relocates away, and a different machine takes the position.
        $old->update(['x' => 2, 'y' => 2, 'address' => 'CCCCCCCC']);
        System::factory()->at(8, 9)->create(['address' => 'DDDDDDDD', 'name' => 'Hot Forge']);

        $this->sweep();

        $this->assertSame('Hot Forge', $this->contact()->name);
        $this->assertTrue($this->contact()->isNew(), 'a stranger has moved in');
        $this->assertTrue($this->contact()->first_seen_at->isAfter($before), 'the clock restarted');
    }

    /** Ground cannot be new. A position never changes. */
    public function test_empty_ground_is_never_marked(): void
    {
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->sweep();

        $map = MapView::for($this->character->fresh());

        foreach ($map->rows as $row) {
            foreach ($row as $cell) {
                if ($cell->contact === null) {
                    $this->assertFalse($cell->isNew, "empty ground at {$cell->position} claimed to be new");
                }
            }
        }
    }

    // ---- on the map --------------------------------------------------------

    public function test_the_map_marks_a_new_machine_and_stops_when_it_ages(): void
    {
        System::factory()->at(8, 9)->create(['address' => 'AAAAAAAA', 'name' => 'Cold Vault']);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->sweep();

        // The legend draws a swatch wearing the same classes, so counting is
        // the only precise test: one for the legend, one for the machine.
        $lit = $this->actingAs($this->user)->get(route('cyberspace.index'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($lit, 'cell--new'), 'the legend, and one marked machine');
        $this->assertStringContainsString(Text::get('cyberspace.map.new'), $lit);

        SystemContact::query()->update(['first_seen_at' => now()->subHour()]);

        $aged = $this->actingAs($this->user)->get(route('cyberspace.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($aged, 'cell--new'), 'the legend alone; nothing is new any more');
    }

    /**
     * A contact taken before the column existed has no first sighting, and is
     * treated as old — a map that lit up everything the day this shipped would
     * have told nobody anything.
     */
    public function test_a_sighting_with_no_first_seen_is_treated_as_old(): void
    {
        System::factory()->at(8, 9)->create(['address' => 'AAAAAAAA']);
        $this->sweep();

        SystemContact::query()->update(['first_seen_at' => null]);

        $this->assertFalse($this->contact()->fresh()->isNew());
    }
}
