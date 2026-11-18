<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\GreenhouseBuys;
use App\City\ShopKeeper;
use App\Cyberspace\ExtractedFile;
use App\Models\Character;
use App\Models\District;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Location;
use App\Models\LocationBuy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Greenhouse buys every extracted file and component at 70% of its own value, and a Scan File for 5. */
final class GreenhouseBuysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE CATCH. Adding a component (`cyberspace.decryption.components`) or a kind of extracted file without adding it to
     * `city.greenhouse.files` fails here, which is the whole of how a gap in the shop's list is noticed.
     */
    public function test_the_greenhouse_buys_every_component_and_every_kind_of_extracted_file(): void
    {
        $everyFile = [
            ...array_keys((array) config('cyberspace.decryption.components')),
            ExtractedFile::SEALED_SLUG, ExtractedFile::DECRYPTED_SLUG, ExtractedFile::TEXT_SLUG,
        ];

        $this->assertSame([], array_values(array_diff($everyFile, (array) config('city.greenhouse.files'))),
            'Add these to city.greenhouse.files (and run greenhouse:sync): the Greenhouse would not buy them.');
        $this->assertSame([], array_values(array_diff((array) config('city.greenhouse.files'), $everyFile)), 'These are listed but are neither a component nor an extracted file.');
    }

    private function place(): Location
    {
        $district = District::query()->first() ?? District::factory()->create();

        return Location::query()->create(['district_id' => $district->getKey(), 'slug' => 'greenhouse', 'name' => 'The Greenhouse', 'map_x' => 50, 'map_y' => 50, 'discovery' => 'open']);
    }

    private function item(string $slug, int $cost = 0): Item
    {
        return Item::query()->where('slug', $slug)->first() ?? Item::factory()->create(['slug' => $slug, 'base_cost' => $cost]);
    }

    public function test_the_sync_buys_files_at_seventy_per_cent_of_their_own_value_and_a_scan_file_for_five(): void
    {
        $place = $this->place();
        $character = Character::factory()->create();
        $this->item('scan-file')->forceFill(['base_cost' => 25])->save();

        $component = InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => $this->item('kernel-log')->id, 'quantity' => 1,
            'extracted_file' => ['label' => 'x', 'size_qb' => 1, 'encryption_skill_id' => null, 'value_sealed' => 40, 'value_broken' => 100, 'grade' => 3, 'origin' => []]]);
        $sealed = InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => $this->item(ExtractedFile::SEALED_SLUG)->id, 'quantity' => 1,
            'extracted_file' => ['label' => 'x', 'size_qb' => 1, 'encryption_skill_id' => null, 'value_sealed' => 40, 'value_broken' => 100, 'grade' => 3, 'origin' => []]]);
        $scan = InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => Item::query()->where('slug', 'scan-file')->value('id'), 'quantity' => 1]);

        $result = (new GreenhouseBuys)->sync();

        $this->assertSame([], $result['notes']);
        $unit = collect(app(ShopKeeper::class)->sellable($character, $place))->mapWithKeys(fn (array $s): array => [$s['row']->id => $s['unit']]);
        $this->assertSame(70, $unit[$component->id], 'a component: 70% of the file it came from');
        $this->assertSame(28, $unit[$sealed->id], 'a sealed file: 70% of its sealed value');
        $this->assertSame(5, $unit[$scan->id], 'a scan file: 5 creds flat');
    }

    public function test_the_sync_keeps_a_count_and_other_listings_and_says_when_a_scan_file_has_no_price(): void
    {
        $place = $this->place();
        $log = $this->item('kernel-log');
        $other = $this->item('something-else');
        LocationBuy::query()->create(['location_id' => $place->id, 'item_id' => $other->id, 'percent' => 10, 'max_quantity' => 3, 'bought' => 0]);
        LocationBuy::query()->create(['location_id' => $place->id, 'item_id' => $log->id, 'percent' => 5, 'max_quantity' => 1, 'bought' => 1]);
        $this->item('scan-file')->forceFill(['base_cost' => 0])->save();

        $result = (new GreenhouseBuys)->sync();

        $this->assertCount(1, $result['notes'], 'a Scan File with no list price is not bought, and it is said');
        $this->assertSame(70, LocationBuy::query()->where('item_id', $log->id)->value('percent'));
        $this->assertSame(1, LocationBuy::query()->where('item_id', $log->id)->value('bought'));
        $this->assertSame(10, LocationBuy::query()->where('item_id', $other->id)->value('percent'), 'another listing is left as the editor made it');
        $this->assertNull(LocationBuy::query()->where('item_id', $this->item('scan-file')->id)->first());
    }
}
