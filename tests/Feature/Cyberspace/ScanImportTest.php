<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\ScanExporter;
use App\Cyberspace\ScanImporter;
use App\Cyberspace\ScanImportRefusal;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading somebody else's file, which is what makes one worth buying.
 *
 * The other half of `ScanExportTest`, and between them the invariant: a read
 * is one object from the moment it is filed to the moment somebody spends it,
 * and it is never in two places.
 */
final class ScanImportTest extends TestCase
{
    use RefreshDatabase;

    private Character $scanner;

    private Character $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scanner = Character::factory()->create();
        $this->buyer = Character::factory()->create();
    }

    public function test_a_bought_file_becomes_the_buyers_own_scan(): void
    {
        $system = System::factory()->create(['address' => 'A1B2C3D4', 'grade' => 6]);
        $file = $this->aFileOf($system, ['grade' => 6]);

        // Sold. The market is not built, so the row changes hands the way a
        // market would eventually do it — the importer never asks how it got
        // here, only whose it is now.
        $file->forceFill(['character_id' => $this->buyer->id])->save();

        $scan = app(ScanImporter::class)->import($this->buyer, $file->fresh());

        $this->assertInstanceOf(SystemScan::class, $scan);
        $this->assertSame($this->buyer->id, $scan->character_id);
        $this->assertSame('A1B2C3D4', $scan->address);
        $this->assertSame(6, $scan->grade);
        $this->assertTrue($scan->isComplete(), 'a complete file reads in complete');

        // Where the machine is NOW, since a file carries no position.
        $this->assertSame($system->x, $scan->x);
        $this->assertSame($system->y, $scan->y);

        $this->assertNull(InventoryItem::query()->find($file->id), 'reading one in spends it');
    }

    public function test_the_clock_does_not_restart(): void
    {
        $system = System::factory()->create(['address' => 'B2C3D4E5']);
        $file = $this->aFileOf($system);

        // An hour left when it changes hands, and an hour left afterwards.
        $expires = now()->addHour();
        $stored = $file->scan_file;
        $stored['expires_at'] = $expires->toImmutable()->format(DATE_ATOM);
        $file->forceFill(['scan_file' => $stored])->save();

        $scan = app(ScanImporter::class)->import($this->buyer, $file->fresh());

        $this->assertInstanceOf(SystemScan::class, $scan);
        $this->assertSame(
            $expires->format('Y-m-d H:i'),
            $scan->expires_at->format('Y-m-d H:i'),
            'a second-hand file is worth what is left of it, not a fresh 48 hours',
        );
    }

    public function test_a_stale_file_reads_in_nothing_and_is_not_spent(): void
    {
        $system = System::factory()->create(['address' => 'C3D4E5F6']);
        $file = $this->aFileOf($system);

        $stored = $file->scan_file;
        $stored['expires_at'] = now()->subHour()->toImmutable()->format(DATE_ATOM);
        $file->forceFill(['scan_file' => $stored])->save();

        $this->assertSame(
            ScanImportRefusal::Stale,
            app(ScanImporter::class)->import($this->buyer, $file->fresh()),
        );

        $this->assertNotNull(InventoryItem::query()->find($file->id), 'a refusal never eats the file');
    }

    public function test_a_file_about_a_machine_that_is_gone_is_refused(): void
    {
        $system = System::factory()->create(['address' => 'D4E5F6A7']);
        $file = $this->aFileOf($system);

        $system->delete();

        $this->assertSame(
            ScanImportRefusal::Gone,
            app(ScanImporter::class)->import($this->buyer, $file->fresh()),
        );

        $this->assertNotNull(InventoryItem::query()->find($file->id));
    }

    public function test_a_complete_read_of_their_own_makes_the_file_pointless(): void
    {
        $system = System::factory()->create(['address' => 'E5F6A7B8']);
        $file = $this->aFileOf($system);

        // The buyer already read this machine themselves, and their file is
        // whole and still good.
        $this->completeScan($this->buyer, $system->address);

        $this->assertSame(
            ScanImportRefusal::AlreadyKnown,
            app(ScanImporter::class)->import($this->buyer, $file->fresh()),
        );

        $this->assertNotNull(InventoryItem::query()->find($file->id));
    }

    public function test_a_partial_read_of_their_own_is_replaced(): void
    {
        $system = System::factory()->create(['address' => 'F6A7B8C9', 'grade' => 4]);
        $file = $this->aFileOf($system, ['grade' => 4]);

        // One pass in: the grade and nothing else. A complete file is worth
        // something to this player, which is the whole case that separates
        // this from AlreadyKnown.
        $partial = $this->scan($this->buyer, $system->address, ['grade' => 9]);

        $scan = app(ScanImporter::class)->import($this->buyer, $file->fresh());

        $this->assertInstanceOf(SystemScan::class, $scan);
        $this->assertNull(SystemScan::query()->find($partial->id), 'one scan per character per machine');
        $this->assertSame(4, $scan->grade, 'the file wins, because it is the one that knows everything');

        $this->assertSame(
            1,
            SystemScan::query()->where('character_id', $this->buyer->id)->count(),
        );
    }

    public function test_a_row_that_is_not_a_file_reads_in_nothing(): void
    {
        $system = System::factory()->create(['address' => 'A7B8C9DA']);
        $this->aFileOf($system);

        $notAFile = InventoryItem::factory()->create(['character_id' => $this->buyer->id]);

        $this->assertSame(
            ScanImportRefusal::NotAFile,
            app(ScanImporter::class)->import($this->buyer, $notAFile),
        );
    }

    // ---- fixtures -----------------------------------------------------------

    /** A file, made the only way one can be made: by exporting a finished read. */
    private function aFileOf(System $system, array $facts = []): InventoryItem
    {
        $this->completeScan($this->scanner, $system->address, $facts);

        $row = app(ScanExporter::class)->export($this->scanner, $system->address);

        $this->assertInstanceOf(InventoryItem::class, $row, 'the fixture has to produce a file');

        return $row;
    }

    private function completeScan(Character $character, string $address, array $facts = []): SystemScan
    {
        $os = Skill::query()->where('slug', 'lattice')->first()
            ?? Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'rank' => 1]);

        return $this->scan($character, $address, array_merge([
            'grade' => 5,
            'os_skill_id' => $os->id,
            'ids_detection' => 0,
            'tracer_detection' => 0,
            'tracer_rate' => 0,
            'ice_level' => 0,
        ], $facts));
    }

    private function scan(Character $character, string $address, array $attributes = []): SystemScan
    {
        $scan = new SystemScan(array_merge([
            'character_id' => $character->id,
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
