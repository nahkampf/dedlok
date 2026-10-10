<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Decks\Hardware;
use App\Decks\Variant;
use App\Models\InventoryItem;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Each kind of thing draws its own glyph (chosen 2026-10-10); anything unmapped stays the stash default. */
class GlyphTest extends TestCase
{
    use RefreshDatabase;

    public function test_hardware_draws_by_kind(): void
    {
        $module = Item::factory()->module(Hardware::Ram, Variant::Opto, measure: 16, draw: 34)->create();
        $deck = Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'ram_opto' => 1, 'psu' => 1])->create();

        $this->assertSame("\u{2338}", $module->glyph());
        $this->assertSame("\u{2394}", $deck->glyph());
    }

    public function test_a_plain_item_keeps_the_default(): void
    {
        $this->assertSame("\u{25A4}", Item::factory()->physical()->create()->glyph());
    }

    public function test_a_file_row_draws_its_own_glyph(): void
    {
        $item = Item::query()->where('slug', 'encrypted-data-dump')->first()
            ?? Item::factory()->physical()->create(['slug' => 'encrypted-data-dump']);
        $row = new InventoryItem(['extracted_file' => ['x' => 1]]);
        $row->setRelation('item', $item);

        $this->assertSame("\u{25A7}", $row->glyph());
    }
}
