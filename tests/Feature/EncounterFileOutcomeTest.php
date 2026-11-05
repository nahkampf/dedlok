<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\EncounterOutcomes;
use App\City\EncounterStart;
use App\City\FilePlaceholders;
use App\Cyberspace\ExtractedFile;
use App\Decryption\DecryptionQueue;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\Encounter;
use App\Models\EncounterOutcome;
use App\Models\FileText;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The `file` outcome: a text or an item left on the deck, sealed or not, and the reader that shows it. */
final class EncounterFileOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(['handle' => 'Noodle']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function outcome(array $attributes): EncounterOutcome
    {
        $encounter = $this->encounter = Encounter::create(['slug' => 'f', 'title' => 'x', 'starts_on' => EncounterStart::AfterRun->value, 'weight' => 10, 'once' => false, 'retired' => false]);
        $node = $encounter->nodes()->create(['node_key' => 'only', 'body' => 'x', 'entry' => true, 'ends' => true]);

        return $node->outcomes()->create(array_merge(['branch' => 'always', 'kind' => 'file', 'position' => 0, 'amount' => 1.5, 'text' => 'Letter', 'body' => 'Hi {handle}, {system} at {address} ({owner}). {nothing}', 'ice' => false], $attributes));
    }

    private function open(array $door = []): void
    {
        $live = CharacterEncounter::create(['character_id' => $this->character->id, 'encounter_id' => $this->encounter->id, 'node_key' => 'only', 'log' => [], 'started_at' => now(), 'door' => $door ?: null]);
        $this->character->forceFill(['current_encounter_id' => $live->id])->save();
    }

    private function apply(EncounterOutcome $outcome): ?string
    {
        return app(EncounterOutcomes::class)->apply($this->character->fresh(), $outcome->fresh());
    }

    public function test_a_text_is_left_with_its_placeholders_filled_from_the_door_snapshot_with_no_deck_at_all(): void
    {
        $outcome = $this->outcome([]);
        // The system is not in the database: the door's snapshot is all there is.
        $this->open(['system' => 'Ghost Node', 'address' => 'DEADBEEF', 'owner' => "Joint\x1b[31m Signals"]);

        $this->assertNotNull($this->apply($outcome));

        $row = InventoryItem::query()->where('character_id', $this->character->id)->sole();
        $this->assertSame(ExtractedFile::TEXT_SLUG, $row->item->slug);
        $this->assertSame(1.5, $row->extractedFile()->sizeQb);
        $this->assertSame('Hi Noodle, Ghost Node at DEADBEEF (Joint[31m Signals). {nothing}', $row->fileText->body);
    }

    public function test_an_encrypted_text_is_a_sealed_dump_that_opens_into_the_text_file(): void
    {
        $skill = Skill::factory()->create(['slug' => 'cinder', 'category' => 'Encryption', 'rank' => 2]);
        $outcome = $this->outcome(['ref' => 'cinder']);
        $this->open();
        $this->apply($outcome);

        $row = InventoryItem::query()->where('character_id', $this->character->id)->sole();
        $file = $row->extractedFile();

        $this->assertSame(ExtractedFile::SEALED_SLUG, $row->item->slug);
        $this->assertSame($skill->id, $file->encryptionSkillId);
        $this->assertSame(ExtractedFile::TEXT_SLUG, app(DecryptionQueue::class)->outcomeFor($file)->slug, 'nothing is rolled');
        $this->assertSame(0, $file->valueSealed + $file->valueBroken);
    }

    public function test_an_item_payload_is_what_a_sealed_file_becomes_and_asks_for_room_when_not_sealed(): void
    {
        $item = Item::factory()->create(['container' => 'data', 'size' => 4]);
        $outcome = $this->outcome(['body' => null, 'item_id' => $item->id, 'ref' => null]);
        $this->open();

        $this->assertNotNull($this->apply($outcome));
        $this->assertSame(0, InventoryItem::query()->where('character_id', $this->character->id)->count(), 'a deck with no room refuses an item');

        $file = new ExtractedFile(label: 'x', sizeQb: 4.0, encryptionSkillId: null, valueSealed: 0, valueBroken: 0, payloadItemId: $item->id);
        $this->assertTrue(app(DecryptionQueue::class)->outcomeFor($file)->is($item));
    }

    public function test_a_token_the_door_cannot_fill_is_found_and_a_city_door_fills_the_two_everywhere_ones(): void
    {
        $this->assertSame(['system'], FilePlaceholders::unknown('{handle} {system} {nothing}', EncounterStart::Travel));
        $this->assertSame([], FilePlaceholders::unknown('{system} {outcome}', EncounterStart::AfterRun));
        $this->assertSame(['outcome'], FilePlaceholders::unknown('{outcome}', EncounterStart::System));
    }

    public function test_the_reader_shows_your_text_only_and_never_a_sealed_one(): void
    {
        $outcome = $this->outcome([]);
        $this->open();
        $this->apply($outcome);
        // The encounter is over: while one is open every screen but its own sends you back to it.
        $this->character->forceFill(['current_encounter_id' => null])->save();
        $row = InventoryItem::query()->where('character_id', $this->character->id)->sole();

        $this->actingAs($this->character->user)->get(route('inventory.text', $row))->assertOk()->assertSee('Hi Noodle', false);

        $other = Character::factory()->create();
        $this->actingAs($other->user)->get(route('inventory.text', $row))->assertNotFound();

        $row->forceFill(['item_id' => Item::query()->where('slug', ExtractedFile::SEALED_SLUG)->value('id')])->save();
        $this->actingAs($this->character->user)->get(route('inventory.text', $row))->assertNotFound();
        $this->assertSame(1, FileText::query()->count());
    }
}
