<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\EncounterPause;
use App\City\EncounterRunner;
use App\City\Encounters;
use App\Models\Character;
use App\Models\Dose;
use App\Models\Encounter;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Programs\Lifetime;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** While an encounter is open on the grid the player's own clocks stand still, and move on when it closes. */
final class EncounterPauseTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        $this->travelTo(now()->startOfMinute());

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character = $this->character->fresh();

        Encounter::query()->delete();
        $this->encounter = Encounter::create(['slug' => 'pause_test', 'title' => 'x', 'starts_on' => 'grid_step', 'weight' => 100, 'once' => false, 'retired' => false]);
        $this->encounter->nodes()->create(['node_key' => 'only', 'body' => 'x', 'entry' => true, 'ends' => true]);
    }

    private function open(): void
    {
        app(Encounters::class)->open($this->character, $this->encounter);
        $this->character = $this->character->fresh();
    }

    private function close(): void
    {
        $this->assertTrue(app(EncounterRunner::class)->leave($this->character->fresh()) === true);
    }

    public function test_a_script_does_not_run_out_while_an_encounter_is_open_and_keeps_its_time_after(): void
    {
        $item = Item::factory()->program(ProgramKind::Script, qflops: 0.4, memory: 0.4, storage: 0.4, lifetime: 600)->create();
        $row = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
        $this->assertTrue(app(ProgramRunner::class)->start($this->character, $row)->succeeded());

        $this->travel(100)->seconds();
        $this->open();

        // Far past its 600 seconds, and it is still there: the clock is read as at the moment the encounter opened.
        $this->travel(1000)->seconds();
        Lifetime::reapFor($this->character);
        $this->assertSame(1, InventoryItem::query()->whereNotNull('running_on_deck_id')->count());
        $this->assertTrue(EncounterPause::isHeld($this->character));

        $this->close();
        Lifetime::reapFor($this->character);
        $this->assertSame(1, InventoryItem::query()->whereNotNull('running_on_deck_id')->count(), 'still has what it had');

        // It had 500 seconds left when the encounter opened, and has them still.
        $this->travel(499)->seconds();
        Lifetime::reapFor($this->character);
        $this->assertSame(1, InventoryItem::query()->whereNotNull('running_on_deck_id')->count());

        $this->travel(2)->seconds();
        Lifetime::reapFor($this->character);
        $this->assertSame(0, InventoryItem::query()->whereNotNull('running_on_deck_id')->count());
    }

    public function test_a_dose_keeps_the_time_it_had_left(): void
    {
        $item = Item::factory()->create();
        $dose = Dose::query()->create([
            'character_id' => $this->character->id, 'item_id' => $item->id, 'potency' => 1.0,
            'started_at' => now(), 'expires_at' => now()->addSeconds(300),
        ]);

        $this->open();
        $this->travel(900)->seconds();
        $this->close();

        $this->assertSame(now()->addSeconds(300)->getTimestamp(), $dose->fresh()->expires_at->getTimestamp());
    }

    public function test_a_city_encounter_pauses_nothing(): void
    {
        $this->character->forceFill(['grid_x' => null, 'grid_y' => null])->save();
        $this->character = $this->character->fresh();
        $this->open();

        $this->assertFalse(EncounterPause::isHeld($this->character));
        $this->assertNull(EncounterPause::openedAt($this->character));
    }
}
