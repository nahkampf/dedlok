<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Collapse;
use App\Characters\Fatigue;
use App\Models\Character;
use App\Models\District;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Safehouse;
use App\Models\User;
use App\Safehouses\Refusal;
use App\Safehouses\Renter;
use App\Safehouses\SafehouseStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Keeping things at a safehouse, and what happens to them when the home is lost. docs/FATIGUE.md, *Safehouses*.
 */
final class SafehouseStorageTest extends TestCase
{
    use RefreshDatabase;

    private Safehouse $capsule;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $dock = District::query()->where('slug', 'dockside')->firstOrFail();
        $this->capsule = Safehouse::create([
            'slug' => 'capsule', 'title' => 'Capsule hotel', 'district_id' => $dock->id, 'rent' => 40, 'security' => 0, 'stealth' => 0,
            'rest_rate' => 2, 'storage_slots' => 2, 'actions' => ['rest'],
        ]);
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), [
            'archetype' => 3, 'creds' => 500, 'district_id' => $dock->id, 'safehouse_id' => $this->capsule->id,
            'rent_due_at' => now()->addDay(),
        ]));
    }

    private function row(int $quantity = 1, bool $stackable = false): InventoryItem
    {
        $item = Item::factory()->create(['container' => 'physical', 'stackable' => $stackable]);

        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => $quantity]);
    }

    private function storage(): SafehouseStorage
    {
        return app(SafehouseStorage::class);
    }

    /** The page asks whether each loose row is worn or wielded; it must not do so with a query apiece. */
    public function test_the_safehouse_page_does_not_ask_the_database_about_each_stash_row(): void
    {
        foreach (range(1, 6) as $ignored) {
            $this->row();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->character->user)->get('/safehouse')->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');

        $this->assertSame(0, count(array_filter($queries, fn (string $q): bool => str_contains($q, 'worn_armour') && str_contains($q, '"inventory_item_id" = ?'))), 'worn armour is read for the whole list at once, not once per row');
    }

    public function test_storing_takes_it_out_of_the_stash_and_back_returns_it(): void
    {
        $row = $this->row();

        $this->assertTrue($this->storage()->deposit($this->character->fresh(), $row));
        $this->assertSame($this->capsule->id, $row->fresh()->safehouse_id);
        $this->assertSame(0, InventoryItem::query()->loose()->where('character_id', $this->character->id)->count());

        $this->assertTrue($this->storage()->withdraw($this->character->fresh(), $row->fresh()));
        $this->assertNull($row->fresh()->safehouse_id);
        $this->assertSame(1, InventoryItem::query()->loose()->where('character_id', $this->character->id)->count());
    }

    public function test_the_slots_are_rows_and_a_stack_is_one(): void
    {
        $a = $this->row();
        $b = $this->row();
        $c = $this->row();
        $pile = $this->row(5, stackable: true);

        $this->assertTrue($this->storage()->deposit($this->character->fresh(), $a));
        $this->assertTrue($this->storage()->deposit($this->character->fresh(), $pile, 2));
        $this->assertSame(Refusal::StorageFull, $this->storage()->deposit($this->character->fresh(), $b));
        $this->assertTrue($this->storage()->deposit($this->character->fresh(), $pile->fresh(), 3), 'joins the stack already there');
        $this->assertSame(2, $this->storage()->used($this->character->fresh(), $this->capsule));
        $this->assertSame(Refusal::StorageFull, $this->storage()->deposit($this->character->fresh(), $c));
    }

    public function test_a_stack_taken_out_merges_with_the_pile_in_the_stash(): void
    {
        $pile = $this->row(5, stackable: true);
        $this->storage()->deposit($this->character->fresh(), $pile, 3);
        $stored = InventoryItem::query()->whereNotNull('safehouse_id')->firstOrFail();

        $this->assertTrue($this->storage()->withdraw($this->character->fresh(), $stored, 3));

        $this->assertSame(5, $pile->fresh()->quantity);
        $this->assertSame(0, InventoryItem::query()->whereNotNull('safehouse_id')->count());
    }

    public function test_you_must_be_there_and_the_home_must_have_room_for_things(): void
    {
        $row = $this->row();
        $this->character->forceFill(['district_id' => Safehouse::fallback()->district_id])->save();
        $this->assertSame(Refusal::NotHere, $this->storage()->deposit($this->character->fresh(), $row));

        $this->capsule->update(['storage_slots' => 0]);
        $this->character->forceFill(['district_id' => $this->capsule->district_id])->save();
        $this->assertSame(Refusal::NoStorage, $this->storage()->deposit($this->character->fresh(), $row));
    }

    public function test_the_equipped_deck_is_carried_and_stays_so(): void
    {
        $row = $this->row();
        $this->character->forceFill(['equipped_deck_id' => $row->id])->save();

        $this->assertSame(Refusal::CannotStore, $this->storage()->deposit($this->character->fresh(), $row));
    }

    public function test_a_mugging_cannot_touch_what_is_stored(): void
    {
        $row = $this->row();
        $this->storage()->deposit($this->character->fresh(), $row);
        app(Fatigue::class)->add($this->character, 9999);

        // The die says: mugged, at the largest share.
        (new Collapse(static fn (int $min, int $max): int => $min))->trigger($this->character->fresh());

        $this->assertLessThan(500, $this->character->fresh()->creds, 'the creds were taken');
        $this->assertSame($this->capsule->id, $row->fresh()->safehouse_id);
        $this->assertNull($row->fresh()->storage_expires_at);
    }

    public function test_losing_the_home_gives_a_deadline_and_a_message_with_collect(): void
    {
        $row = $this->row();
        $this->storage()->deposit($this->character->fresh(), $row);
        $this->character->forceFill(['creds' => 0])->save();

        $this->travelTo(now()->addHours(49));
        app(Renter::class)->settle($this->character->fresh());

        $this->assertNotNull($row->fresh()->storage_expires_at);
        $message = Notification::query()->where('title', 'Your belongings are in storage')->firstOrFail();
        $this->assertStringContainsString('Capsule hotel [Dockside]', $message->body);
        $this->assertSame($this->capsule->id, $message->details['collect']);

        $this->actingAs($this->character->user)->get(route('notifications.index'))->assertOk()->assertSee('Collect');
    }

    public function test_collect_brings_back_what_fits_and_the_rest_is_deleted_after_the_deadline(): void
    {
        $physical = $this->row();
        $this->storage()->deposit($this->character->fresh(), $physical);
        $this->character->forceFill(['safehouse_id' => null, 'rent_due_at' => null])->save();
        $this->storage()->putAway($this->character->fresh(), $this->capsule);

        $this->assertTrue($this->storage()->withdraw($this->character->fresh(), $physical->fresh()) instanceof Refusal, 'no longer yours to open');

        $this->assertSame(1, $this->storage()->collect($this->character->fresh(), $this->capsule));
        $this->assertNull($physical->fresh()->safehouse_id);

        $left = $this->row();
        $left->forceFill(['safehouse_id' => $this->capsule->id, 'storage_expires_at' => now()->addHours(48)])->save();
        $this->travelTo(now()->addHours(49));
        $this->artisan('safehouses:rent')->assertSuccessful();

        $this->assertNull(InventoryItem::query()->find($left->id));
    }

    public function test_renting_the_same_place_again_takes_the_things_back(): void
    {
        $row = $this->row();
        $this->storage()->deposit($this->character->fresh(), $row);
        $this->character->forceFill(['safehouse_id' => null, 'rent_due_at' => null])->save();
        $this->storage()->putAway($this->character->fresh(), $this->capsule);
        DB::table('character_district_passes')->insert(['character_id' => $this->character->id, 'district_id' => $this->capsule->district_id, 'passes' => 5]);

        app(Renter::class)->rent($this->character->fresh(), $this->capsule);

        $this->assertNull($row->fresh()->storage_expires_at);
        $this->assertSame($this->capsule->id, $row->fresh()->safehouse_id);
    }

    public function test_moving_to_another_home_gives_the_old_things_a_deadline(): void
    {
        $row = $this->row();
        $this->storage()->deposit($this->character->fresh(), $row);
        $this->character->forceFill(['district_id' => Safehouse::fallback()->district_id])->save();

        app(Renter::class)->rent($this->character->fresh(), Safehouse::fallback());

        $this->assertNotNull($row->fresh()->storage_expires_at);
    }

    public function test_the_screen_lists_storage_and_a_home_that_holds_things_cannot_be_deleted(): void
    {
        $row = $this->row();
        $this->storage()->deposit($this->character->fresh(), $row);

        $this->actingAs($this->character->user)->get(route('safehouse.show'))->assertOk()->assertSee('Storage')->assertSee('1 / 2');
        $this->actingAs($this->character->user)->post(route('safehouse.take', $row))->assertSessionHas('status');

        $this->storage()->deposit($this->character->fresh(), $row->fresh());
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->delete(route('admin.safehouses.destroy', $this->capsule))->assertSessionHas('error');
        $this->assertNotNull(Safehouse::query()->find($this->capsule->id));
    }
}
