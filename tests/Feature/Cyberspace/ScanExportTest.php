<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\ScanExporter;
use App\Cyberspace\ScanExportRefusal;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Turning a read into something somebody else can buy.
 *
 * THE INVARIANT THIS FILE EXISTS FOR: one read is one object, for ever. A
 * scanner who could export the same pass twice could sell the same intel
 * twice, and the whole point of scanning as an occupation is that the
 * instrument and the clock are what bound the income.
 */
final class ScanExportTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create();
    }

    public function test_a_complete_read_becomes_a_row_in_the_data_stash(): void
    {
        $system = System::factory()->create(['address' => 'A1B2C3D4', 'grade' => 5]);
        $scan = $this->completeScanOf($system->address);

        $row = app(ScanExporter::class)->export($this->character, $system->address);

        $this->assertInstanceOf(InventoryItem::class, $row);
        $this->assertSame('scan-file', $row->item->slug);
        $this->assertSame(1, $row->quantity);
        $this->assertNull($row->parent_id, 'an exported file lands loose, like everything else granted');

        $file = $row->scanFile();

        $this->assertNotNull($file);
        $this->assertSame('A1B2C3D4', $file->address);
        $this->assertSame(5, $file->grade);
        $this->assertSame($system->displayName(), $file->name);

        // The position does NOT travel. An address is meaningless and trading
        // one is expected; coordinates are what BookmarkKeeper keeps off the
        // market by only letting a waypoint be set on ground somebody walked.
        $this->assertArrayNotHasKey('x', $row->scan_file);
        $this->assertArrayNotHasKey('y', $row->scan_file);

        // Selling what you know costs you knowing it.
        $this->assertNotNull($scan->fresh()->used_at);
        $this->assertNull(
            SystemScan::query()->where('character_id', $this->character->id)->live()->first(),
            'the machine stops being scanned the moment its file is made',
        );
    }

    public function test_one_read_is_one_object(): void
    {
        $system = System::factory()->create(['address' => 'B2C3D4E5']);
        $this->completeScanOf($system->address);

        $exporter = app(ScanExporter::class);

        $this->assertInstanceOf(InventoryItem::class, $exporter->export($this->character, $system->address));
        $this->assertSame(
            ScanExportRefusal::Gone,
            $exporter->export($this->character, $system->address),
            'a second export off one pass would be the same intel sold twice',
        );

        $this->assertSame(1, InventoryItem::query()->where('character_id', $this->character->id)->count());
    }

    public function test_an_incomplete_read_is_not_intel(): void
    {
        $system = System::factory()->create(['address' => 'C3D4E5F6']);

        // The grade and nothing else: one pass in, and worth nothing to
        // anybody who wants to know what they are walking into.
        $scan = $this->scan($system->address, ['grade' => 3]);

        $this->assertSame(
            ScanExportRefusal::Incomplete,
            app(ScanExporter::class)->export($this->character, $system->address),
        );

        $this->assertNull($scan->fresh()->used_at, 'a refused export spends nothing');
        $this->assertSame(0, InventoryItem::query()->where('character_id', $this->character->id)->count());
    }

    public function test_a_stale_read_cannot_be_filed(): void
    {
        $system = System::factory()->create(['address' => 'D4E5F6A7']);

        $this->completeScanOf($system->address, ['expires_at' => now()->subMinute()]);

        $this->assertSame(
            ScanExportRefusal::Gone,
            app(ScanExporter::class)->export($this->character, $system->address),
        );
    }

    public function test_the_file_outlives_the_machine_it_describes(): void
    {
        $system = System::factory()->create(['address' => 'E5F6A7B8', 'grade' => 7]);
        $name = $system->displayName();
        $this->completeScanOf($system->address, ['grade' => 7]);

        $row = app(ScanExporter::class)->export($this->character, $system->address);
        $this->assertInstanceOf(InventoryItem::class, $row);

        // Burned out from under the file. Every field was copied, so the card
        // still says what was read rather than nothing at all.
        $system->delete();

        $file = $row->fresh()->scanFile();

        $this->assertNotNull($file);
        $this->assertSame($name, $file->name);
        $this->assertSame(7, $file->grade);
        $this->assertStringContainsString($name, (string) $row->fresh()->scanFileSummary());
    }

    public function test_a_stale_file_says_so_on_its_card(): void
    {
        $system = System::factory()->create(['address' => 'F6A7B8C9']);
        $this->completeScanOf($system->address);

        $row = app(ScanExporter::class)->export($this->character, $system->address);
        $this->assertInstanceOf(InventoryItem::class, $row);

        $stored = $row->scan_file;
        $stored['expires_at'] = now()->subHour()->format(DATE_ATOM);
        $row->forceFill(['scan_file' => $stored])->save();

        $this->assertStringContainsString(
            'Stale',
            (string) $row->fresh()->scanFileSummary(),
            'a file past its 48 hours is worth nothing and has to say so',
        );
    }

    /**
     * A read that found out everything there is to find out.
     *
     * Written straight onto the row rather than rolled through the Probe: what
     * is under test is what happens to a FINISHED file, and driving a dozen
     * pinned passes to get one would be testing the probe twice.
     */
    private function completeScanOf(string $address, array $overrides = []): SystemScan
    {
        $os = Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'rank' => 1]);

        return $this->scan($address, array_merge([
            'grade' => 5,
            'os_skill_id' => $os->id,
            // Zero is an ANSWER: found out, and there is none of it.
            'ids_detection' => 0,
            'tracer_detection' => 0,
            'tracer_rate' => 0,
            'ice_level' => 0,
        ], $overrides));
    }

    private function scan(string $address, array $attributes = []): SystemScan
    {
        $scan = new SystemScan(array_merge([
            'character_id' => $this->character->id,
            'address' => $address,
            'x' => 8,
            'y' => 8,
            'passes' => 1,
            'scanned_at' => now(),
            'expires_at' => now()->addHours(48),
        ], $attributes));

        $scan->save();

        return $scan;
    }
}
