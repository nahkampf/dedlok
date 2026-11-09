<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Notifications\Category;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Telling somebody their intel died while they were asleep.
 *
 * THE THING THIS FILE IS FOR IS "SAID ONCE": the file is announced, then deleted, and a stale
 * file this sweep never announced is deleted without a second message.
 */
final class StaleFileTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create();
    }

    public function test_a_file_that_went_stale_is_announced(): void
    {
        $row = $this->file(expires: now()->subHour());

        $this->artisan('scans:stale')->assertSuccessful();

        $message = Notification::query()->sole();

        $this->assertSame($this->character->user_id, $message->user_id);
        $this->assertSame(Category::Cyberspace, $message->category);
        $this->assertSame(
            Text::get('notifications.messages.file_stale', [
                'file' => 'A1B2C3D4.scn',
                'system' => 'Data silo - Ono-Sendai 001',
            ]),
            $message->body,
        );

        // And then it is gone: nothing can use a stale one.
        $this->assertNull($row->fresh());
    }

    public function test_it_is_said_once_and_not_every_hour(): void
    {
        $this->file(expires: now()->subHour());

        $this->artisan('scans:stale')->assertSuccessful();
        $this->artisan('scans:stale')->assertSuccessful();
        $this->artisan('scans:stale')->assertSuccessful();

        $this->assertSame(1, Notification::query()->count());
    }

    public function test_an_already_announced_stale_file_is_deleted_quietly(): void
    {
        $row = $this->file(expires: now()->subHour());
        $row->forceFill(['scan_file' => [...$row->scan_file, 'stale_announced' => true]])->save();

        $this->artisan('scans:stale')->assertSuccessful();

        $this->assertNull($row->fresh());
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_a_file_that_is_still_good_is_left_alone(): void
    {
        $row = $this->file(expires: now()->addHours(12));

        $this->artisan('scans:stale')->assertSuccessful();

        $this->assertSame(0, Notification::query()->count());
        $this->assertFalse((bool) ($row->fresh()->scan_file['stale_announced'] ?? false));
    }

    /** A dry run says what it would do and remembers nothing, so the real pass still speaks. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $row = $this->file(expires: now()->subHour());

        $this->artisan('scans:stale --dry-run')->assertSuccessful();

        $this->assertSame(0, Notification::query()->count());
        $this->assertNotNull($row->fresh());
        $this->assertFalse((bool) ($row->fresh()->scan_file['stale_announced'] ?? false));

        $this->artisan('scans:stale')->assertSuccessful();

        $this->assertSame(1, Notification::query()->count());
    }

    /** Rows that are not scan files are not this sweep's business. */
    public function test_an_ordinary_row_is_ignored(): void
    {
        InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => Item::factory()->create()->id,
            'quantity' => 1,
        ]);

        $this->artisan('scans:stale')->assertSuccessful();

        $this->assertSame(0, Notification::query()->count());
    }

    private function file(\DateTimeInterface $expires): InventoryItem
    {
        return InventoryItem::query()->create([
            'character_id' => $this->character->id,
            // The catalogue row the migration wrote — there is one Scan File
            // item in the game and every file is a row of it.
            'item_id' => Item::query()->where('slug', 'scan-file')->value('id'),
            'quantity' => 1,
            'scan_file' => [
                'address' => 'A1B2C3D4',
                'name' => 'Data silo - Ono-Sendai 001',
                'grade' => 5,
                'made_at' => now()->subHours(49)->format(DATE_ATOM),
                'expires_at' => $expires->format(DATE_ATOM),
            ],
        ]);
    }
}
