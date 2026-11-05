<?php

declare(strict_types=1);

namespace Tests\Feature\Missions;

use App\Cyberspace\ExtractedFile;
use App\Cyberspace\FileOrigin;
use App\Missions\MissionAction;
use App\Missions\MissionBoard;
use App\Missions\MissionLedger;
use App\Missions\MissionRefusal;
use App\Missions\MissionState;
use App\Models\Character;
use App\Models\Entity;
use App\Models\Exchange;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Mission;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Procure: an Extraction-class job that wants components from one owner's systems instead of a file at an address.
 */
final class ProcureTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private Entity $giver;

    private Entity $owner;

    private Exchange $desk;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('missions.board.size', ['min' => 1, 'max' => 1]);

        $this->character = Character::factory()->create(['creds' => 0]);
        [$this->giver, $this->owner] = Entity::query()->interactable()->take(2)->get()->all();

        $deskSystem = System::factory()->at(8, 8)->ownedBy($this->giver)->create(['type' => 'exchange', 'grade' => 1]);
        $this->desk = Exchange::query()->create(['system_id' => $deskSystem->id, 'grade' => 1, 'agent' => 'Mr Nakata', 'min_standing' => 0]);

        // A system the owner holds, inside the grade 1 band.
        System::factory()->at(2, 2)->ownedBy($this->owner)->create(['grade' => 2]);
    }

    private function procureOffer(): Mission
    {
        // Every roll is the minimum except the action pick (0 to 8), which has to land on Extract; the share roll always hits.
        Config::set('missions.procure.share', 100);

        MissionBoard::fromConfig(static fn (int $min, int $max): int => $max === 8 ? 4 : $min)->forCharacter($this->character, $this->desk);

        return Mission::query()->where('character_id', $this->character->id)->with('requirements')->latest('id')->firstOrFail();
    }

    private function heldComponent(string $slug, int $ownerId, int $grade = 1): InventoryItem
    {
        $item = Item::query()->where('slug', $slug)->firstOrFail();

        return $this->character->inventory()->create([
            'item_id' => $item->id, 'parent_id' => null, 'quantity' => 1,
            'extracted_file' => (new ExtractedFile('Dump', 1, null, 0, 0, grade: $grade, origin: new FileOrigin(ownerId: $ownerId, ownerName: 'X')))->toArray(),
        ]);
    }

    private function accepted(): Mission
    {
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $mission = $this->procureOffer();
        $this->assertSame(MissionAction::Procure, $mission->action, 'the fixture has to roll a Procure job');

        $taken = app(MissionLedger::class)->accept($this->character, $mission);
        $this->assertInstanceOf(Mission::class, $taken);

        return $taken->fresh('requirements');
    }

    public function test_an_extract_roll_can_become_a_procure_job_that_names_no_address(): void
    {
        $mission = $this->procureOffer();

        $this->assertSame(MissionAction::Procure, $mission->action);
        $this->assertSame(0, $mission->targets()->count());
        $this->assertSame(0, (int) $mission->quantity_required);
        $this->assertGreaterThanOrEqual(1, $mission->requirements->count());
        $this->assertSame([$this->owner->id], $mission->requirements->pluck('owner_id')->unique()->values()->all());
        $this->assertNotSame($this->giver->id, $mission->requirements->first()->owner_id);
    }

    public function test_it_pays_triple_the_standing_and_loyalty_of_an_extraction(): void
    {
        $this->assertSame(1.5 * 3, (float) config('missions.actions.procure.standing'));
        $this->assertSame(3.0 * 3, (float) config('missions.actions.procure.loyalty'));
        $this->assertSame(config('missions.actions.extract.creds'), config('missions.actions.procure.creds'));
    }

    public function test_without_an_owner_in_the_band_it_stays_an_extraction(): void
    {
        System::query()->where('owner_id', $this->owner->id)->delete();
        // An unowned system stays, so a plain Extract still has something to name.
        System::factory()->at(3, 3)->create(['grade' => 2, 'owner_id' => null]);
        Config::set('missions.procure.share', 100);

        MissionBoard::fromConfig(static fn (int $min, int $max): int => $max === 8 ? 4 : $min)->forCharacter($this->character, $this->desk);

        $this->assertSame(MissionAction::Extract, Mission::query()->latest('id')->firstOrFail()->action);
    }

    public function test_the_window_is_forty_eight_hours(): void
    {
        $mission = $this->accepted();

        $this->assertEqualsWithDelta(48 * 3600, $mission->accepted_at->diffInSeconds($mission->expires_at), 5);
    }

    public function test_handing_in_spends_the_components_from_the_right_owner_only(): void
    {
        $mission = $this->accepted();
        $line = $mission->requirements->first();
        $slug = Item::query()->find($line->component_item_id)->slug;
        $ledger = app(MissionLedger::class);

        // Wrong owner: nothing counts.
        $this->heldComponent($slug, $this->giver->id);
        $this->assertFalse($ledger->canHandIn($this->character, $mission));
        $this->assertSame(MissionRefusal::NotDone, $ledger->complete($this->character, $mission));

        foreach ($mission->requirements as $req) {
            $reqSlug = Item::query()->find($req->component_item_id)->slug;
            for ($i = 0; $i < $req->quantity; $i++) {
                $this->heldComponent($reqSlug, $this->owner->id, grade: 9 - $i);
            }
        }

        $this->assertTrue($ledger->canHandIn($this->character, $mission));
        $done = $ledger->complete($this->character, $mission);

        $this->assertInstanceOf(Mission::class, $done);
        $this->assertSame(MissionState::Completed, $done->state);
        // The decoy from the wrong owner is still held; the right ones are spent.
        $this->assertSame(1, $this->character->inventory()->whereNotNull('extracted_file')->count());
    }

    public function test_the_lowest_grade_components_are_spent_first(): void
    {
        $mission = $this->accepted();
        $req = $mission->requirements->first();
        $slug = Item::query()->find($req->component_item_id)->slug;
        $high = $this->heldComponent($slug, $this->owner->id, grade: 9);
        $low = $this->heldComponent($slug, $this->owner->id, grade: 1);

        $picks = app(MissionLedger::class)->requirementStatus($this->character, $mission)[0]['rows'];

        $this->assertSame($low->id, $picks[0]->id);
        $this->assertSame($high->id, $picks[1]->id);
    }
}
