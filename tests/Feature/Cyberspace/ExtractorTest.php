<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Extractor;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasRoomForData;
use Tests\TestCase;

/**
 * Taking what a machine holds, and making it the character's.
 *
 * `system_contents` rows are the only thing this class reads, and the only
 * thing it deletes — nothing else in the codebase touches either side of that
 * exchange.
 */
final class ExtractorTest extends TestCase
{
    use HasRoomForData;
    use RefreshDatabase;

    private function character(): Character
    {
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3, 'creds' => 0],
        ));

        $this->giveRoomForData($character);

        return $character;
    }

    private function system(): System
    {
        return System::factory()->at(8, 8)->grade(1)->create(['address' => 'DEADBEEF']);
    }

    private function extractor(): Extractor
    {
        return new Extractor;
    }

    // ---- nothing there -------------------------------------------------

    /** A never-stocked or already-raided node is an ordinary answer, not a failure. */
    public function test_an_empty_node_yields_nothing(): void
    {
        $this->system();

        $result = $this->extractor()->extract($this->character(), 'DEADBEEF');

        $this->assertTrue($result->tookNothing());
    }

    public function test_a_missing_address_yields_nothing(): void
    {
        $result = $this->extractor()->extract($this->character(), 'NOTHERE1');

        $this->assertTrue($result->tookNothing());
    }

    // ---- creds -----------------------------------------------------------

    public function test_creds_go_straight_onto_the_character(): void
    {
        $system = $this->system();
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 450]);
        $character = $this->character();

        $result = $this->extractor()->extract($character, 'DEADBEEF');

        $this->assertSame(450, $result->creds);
        $this->assertSame(450, $character->fresh()->creds);
    }

    /** Consumed — a second extraction of the same, unrestocked node finds nothing. */
    public function test_a_taken_content_row_is_gone_afterwards(): void
    {
        $system = $this->system();
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 100]);
        $character = $this->character();

        $this->extractor()->extract($character, 'DEADBEEF');
        $second = $this->extractor()->extract($character, 'DEADBEEF');

        $this->assertTrue($second->tookNothing());
        $this->assertSame(100, $character->fresh()->creds, 'the first haul stands, the second finds nothing new');
    }

    // ---- a catalogue item --------------------------------------------------

    public function test_an_unstackable_item_becomes_its_own_row(): void
    {
        $system = $this->system();
        $item = Item::factory()->create(['container' => 'data', 'stackable' => false]);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'item', 'item_id' => $item->id, 'quantity' => 2,
        ]);
        $character = $this->character();

        $result = $this->extractor()->extract($character, 'DEADBEEF');

        $this->assertSame(2, $result->items);
        $this->assertSame(2, InventoryItem::where('character_id', $character->id)->where('item_id', $item->id)->count());
    }

    /** A stackable item merges into whatever pile the character already has. */
    public function test_a_stackable_item_merges_into_an_existing_pile(): void
    {
        $system = $this->system();
        $item = Item::factory()->create(['container' => 'data', 'stackable' => true]);
        $character = $this->character();
        InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id, 'parent_id' => null, 'quantity' => 3]);

        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'item', 'item_id' => $item->id, 'quantity' => 4,
        ]);

        $this->extractor()->extract($character, 'DEADBEEF');

        $this->assertSame(1, InventoryItem::where('character_id', $character->id)->where('item_id', $item->id)->count(), 'one pile, not two');
        $this->assertSame(7, InventoryItem::where('character_id', $character->id)->where('item_id', $item->id)->sole()->quantity);
    }

    // ---- a file ------------------------------------------------------------

    /** A generated file has no catalogue row of its own, so it becomes one generic dump. */
    public function test_a_file_becomes_an_encrypted_data_dump(): void
    {
        $system = $this->system();
        $skill = Skill::factory()->create(['slug' => 'cinder', 'title' => 'Cinder', 'category' => 'Encryption', 'rank' => 2]);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'Payroll ledger',
            'size_qb' => 40, 'encryption_skill_id' => $skill->id, 'value_sealed' => 300, 'value_broken' => 900,
        ]);
        $character = $this->character();

        $result = $this->extractor()->extract($character, 'DEADBEEF');

        $this->assertSame(1, $result->files);

        $row = InventoryItem::where('character_id', $character->id)
            ->whereHas('item', fn ($q) => $q->where('slug', 'encrypted-data-dump'))
            ->sole();

        $file = $row->extractedFile();
        $this->assertSame('Payroll ledger', $file->label);
        $this->assertEquals(40, $file->sizeQb);
        $this->assertSame($skill->id, $file->encryptionSkillId);
        $this->assertSame(300, $file->valueSealed);
        $this->assertSame(900, $file->valueBroken);
        $this->assertSame(600, $file->decryptionPremium());
    }

    /** Two files never merge, whatever the dump item's own stackable flag says elsewhere — each carries different numbers. */
    public function test_two_files_stay_two_separate_rows(): void
    {
        $system = $this->system();
        foreach ([100, 200] as $size) {
            SystemContent::query()->create([
                'system_id' => $system->id, 'kind' => 'data', 'label' => 'File',
                'size_qb' => $size, 'value_sealed' => 10, 'value_broken' => 20,
            ]);
        }
        $character = $this->character();

        $result = $this->extractor()->extract($character, 'DEADBEEF');

        $this->assertSame(2, $result->files);
        $this->assertSame(
            2,
            InventoryItem::where('character_id', $character->id)
                ->whereHas('item', fn ($q) => $q->where('slug', 'encrypted-data-dump'))
                ->count(),
        );
    }

    // ---- the granular pieces a multi-turn transfer spends ------------------

    /** Sizing a transfer never takes anything — it is asked before committing to one. */
    public function test_total_file_qb_reads_without_taking(): void
    {
        $system = $this->system();
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 50]);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'File',
            'size_qb' => 40, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'File',
            'size_qb' => 60, 'value_sealed' => 10, 'value_broken' => 20,
        ]);

        $this->assertEquals(100, $this->extractor()->totalFileQb('DEADBEEF'));
        $this->assertSame(2, SystemContent::query()->where('kind', 'data')->count(), 'reading the size takes nothing');
    }

    public function test_total_file_qb_is_zero_with_nothing_to_pull(): void
    {
        $this->system();

        $this->assertEquals(0, $this->extractor()->totalFileQb('DEADBEEF'));
    }

    /** Creds and items come out without touching a file sitting alongside them. */
    public function test_take_creds_and_items_leaves_files_behind(): void
    {
        $system = $this->system();
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 300]);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'File',
            'size_qb' => 40, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        $character = $this->character();

        $result = $this->extractor()->takeCredsAndItems($character, 'DEADBEEF');

        $this->assertSame(300, $result->creds);
        $this->assertFalse($result->tookNothing());
        $this->assertSame(300, $character->fresh()->creds);
        $this->assertSame(1, SystemContent::query()->where('kind', 'data')->count(), 'the file is still there');
    }

    /** The file half of a haul, taken on its own once a transfer's countdown reaches zero. */
    public function test_take_files_grants_only_the_files(): void
    {
        $system = $this->system();
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 300]);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'File',
            'size_qb' => 40, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        $character = $this->character();

        $result = $this->extractor()->takeFiles($character, 'DEADBEEF');

        $this->assertSame(1, $result->files);
        $this->assertSame(0, $result->creds);
        $this->assertSame(0, $character->fresh()->creds, 'the creds are still on the node');
        $this->assertSame(1, SystemContent::query()->where('kind', 'creds')->count());
    }

    /** A slower raider loses the race exactly the way an instant haul already does. */
    public function test_take_files_finds_nothing_once_another_raid_beat_it_there(): void
    {
        $system = $this->system();
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'File',
            'size_qb' => 40, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        $first = $this->character();
        $second = $this->character();

        $this->extractor()->takeFiles($first, 'DEADBEEF');
        $result = $this->extractor()->takeFiles($second, 'DEADBEEF');

        $this->assertTrue($result->tookNothing());
    }

    // ---- concurrent runners --------------------------------------------------

    /** First extraction takes it. A concurrent second one finds the shelf empty. */
    public function test_the_first_extraction_takes_it_and_a_concurrent_one_finds_nothing(): void
    {
        $system = $this->system();
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 250]);

        $first = $this->character();
        $second = $this->character();

        $this->extractor()->extract($first, 'DEADBEEF');
        $result = $this->extractor()->extract($second, 'DEADBEEF');

        $this->assertTrue($result->tookNothing());
        $this->assertSame(250, $first->fresh()->creds);
        $this->assertSame(0, $second->fresh()->creds);
    }
}
