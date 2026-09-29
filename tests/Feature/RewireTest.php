<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Drugs\DrugLedger;
use App\Drugs\DrugTaker;
use App\Drugs\Family;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rewire: the first thing for sale that heals Synaptic Integrity.
 *
 * It heals through `synaptic_regen`, which `characters:regenerate` credits a
 * minute at a time — so these tests run the real command rather than asking
 * the stat, because a stat nobody credits heals nobody.
 */
final class RewireTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        // Off the four-hourly mend's mark, so only the dose heals here.
        $this->travelTo(now()->setTime(12, 1));

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 25),
            ['archetype' => 3],
        ));
    }

    public function test_it_is_a_nootropic_nobody_gets_hooked_on(): void
    {
        $drug = $this->rewire()->drug;

        $this->assertSame(Family::Nootropic, $drug->family);
        $this->assertSame(0.0, (float) $drug->addictive);
        $this->assertNull(config('drugs.overdose.families.nootropic.lethal_at'), 'it never stops a heart');
    }

    public function test_a_dose_heals_a_minute_at_a_time(): void
    {
        $this->character->forceFill(['current_synaptic_integrity' => 5])->save();

        $this->take();
        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertSame(7, (int) $this->character->fresh()->current_synaptic_integrity);
    }

    public function test_while_it_runs_intellect_and_focus_are_down_eight_percent(): void
    {
        $this->take();

        $character = $this->character->fresh();

        $this->assertSame(23, $character->stat('intellect')->toInt(), '25 less 8%, rounded');
        $this->assertSame(23, $character->stat('focus')->toInt());
    }

    public function test_it_builds_tolerance_and_no_addiction(): void
    {
        $this->take();

        $books = (new DrugLedger)->for($this->character, Family::Nootropic);

        $this->assertSame(10.0, (float) $books->tolerance);
        $this->assertSame(0.0, (float) $books->addiction);
    }

    private function rewire(): Item
    {
        return Item::query()->where('slug', 'rewire')->sole();
    }

    private function take(): void
    {
        $row = InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => $this->rewire()->id,
            'quantity' => 3,
        ]);

        $this->assertTrue((new DrugTaker)->take($this->character->fresh(), $row)->succeeded());
    }
}
