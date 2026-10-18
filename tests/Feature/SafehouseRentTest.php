<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Collapse;
use App\Items\ConsumableEditor;
use App\Items\Consumer;
use App\Items\UseRefusal;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\Dose;
use App\Models\Drug;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Safehouse;
use App\Models\User;
use App\Models\WalletEntry;
use App\Safehouses\Action;
use App\Safehouses\HomeActions;
use App\Safehouses\Refusal;
use App\Safehouses\Renter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Renting, the rent clock, losing a home, and what you do in one. FATIGUE.md, *Safehouses*.
 */
final class SafehouseRentTest extends TestCase
{
    use RefreshDatabase;

    private Safehouse $capsule;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $dock = District::query()->where('slug', 'dockside')->firstOrFail();
        $this->capsule = Safehouse::create([
            'slug' => 'capsule', 'title' => 'Capsule hotel', 'district_id' => $dock->id, 'rent' => 40,
            'security' => 0, 'stealth' => 0, 'rest_rate' => 2, 'actions' => ['rest', 'patch', 'meditate', 'shower', 'eat'],
        ]);
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), [
            'archetype' => 3, 'creds' => 500, 'district_id' => $dock->id,
        ]));
    }

    private function passes(int $n): void
    {
        DB::table('character_district_passes')->insert(['character_id' => $this->character->id, 'district_id' => $this->capsule->district_id, 'passes' => $n]);
    }

    public function test_it_needs_five_passes_in_the_district(): void
    {
        $this->passes(4);

        $this->assertSame(Refusal::Unfamiliar, app(Renter::class)->rent($this->character, $this->capsule));

        DB::table('character_district_passes')->update(['passes' => 5]);

        $this->assertTrue(app(Renter::class)->rent($this->character->fresh(), $this->capsule));
        $this->assertSame($this->capsule->id, $this->character->fresh()->safehouse_id);
    }

    public function test_the_first_day_is_paid_now_as_a_ledger_line_with_the_place_in_it(): void
    {
        $this->passes(5);
        app(Renter::class)->rent($this->character, $this->capsule);

        $this->assertSame(460, $this->character->fresh()->creds);
        $entry = WalletEntry::query()->where('character_id', $this->character->id)->latest('id')->first();
        $this->assertSame(-40, $entry->amount);
        $this->assertSame('Rent: Capsule hotel [Dockside]', $entry->note);
        $this->assertNotNull($this->character->fresh()->rent_due_at);
    }

    public function test_you_must_be_there_and_able_to_pay(): void
    {
        $this->passes(5);
        $this->character->forceFill(['creds' => 10])->save();
        $this->assertSame(Refusal::CannotAfford, app(Renter::class)->rent($this->character->fresh(), $this->capsule));

        $this->character->forceFill(['creds' => 500, 'district_id' => Safehouse::fallback()->district_id])->save();
        $this->assertSame(Refusal::NotHere, app(Renter::class)->rent($this->character->fresh(), $this->capsule));
    }

    public function test_renting_another_drops_the_old_and_the_box_is_free_and_needs_no_passes(): void
    {
        $this->passes(5);
        app(Renter::class)->rent($this->character, $this->capsule);

        $this->character->forceFill(['district_id' => Safehouse::fallback()->district_id])->save();
        $this->assertTrue(app(Renter::class)->rent($this->character->fresh(), Safehouse::fallback()));

        $fresh = $this->character->fresh();
        $this->assertNull($fresh->safehouse_id);
        $this->assertNull($fresh->rent_due_at);
        $this->assertSame(Refusal::AlreadyYours, app(Renter::class)->rent($fresh, Safehouse::fallback()));
    }

    public function test_rent_is_charged_each_day_and_never_into_debt(): void
    {
        $this->passes(5);
        app(Renter::class)->rent($this->character, $this->capsule);

        $this->travelTo(now()->addHours(25));
        app(Renter::class)->settle($this->character->fresh());
        $this->assertSame(420, $this->character->fresh()->creds);

        $this->character->forceFill(['creds' => 10])->save();
        $this->travelTo(now()->addHours(24));
        app(Renter::class)->settle($this->character->fresh());

        $this->assertSame(10, $this->character->fresh()->creds, 'nothing taken that is not there');
        $this->assertSame($this->capsule->id, $this->character->fresh()->safehouse_id, 'still inside the grace period');
    }

    public function test_a_late_payment_is_taken_the_moment_the_money_is_there(): void
    {
        $this->passes(5);
        app(Renter::class)->rent($this->character, $this->capsule);
        $this->character->forceFill(['creds' => 0])->save();

        $this->travelTo(now()->addHours(30));
        app(Renter::class)->settle($this->character->fresh());
        $this->character->forceFill(['creds' => 100])->save();
        app(Renter::class)->settle($this->character->fresh());

        $this->assertSame(60, $this->character->fresh()->creds);
        $this->assertSame($this->capsule->id, $this->character->fresh()->safehouse_id);
    }

    public function test_a_day_beyond_the_grace_loses_it_and_says_so(): void
    {
        $this->passes(5);
        app(Renter::class)->rent($this->character, $this->capsule);
        $this->character->forceFill(['creds' => 0])->save();

        $this->travelTo(now()->addHours(49));
        app(Renter::class)->settle($this->character->fresh());

        $this->assertNull($this->character->fresh()->safehouse_id);
        $this->assertNull($this->character->fresh()->rent_due_at);
        $this->assertSame('cardboard-box', $this->character->fresh()->home()->slug);
        $this->assertStringContainsString('Capsule hotel [Dockside]', Notification::query()->latest('id')->value('body'));
    }

    public function test_the_sweep_does_the_same_for_somebody_away(): void
    {
        $this->passes(5);
        app(Renter::class)->rent($this->character, $this->capsule);
        $this->travelTo(now()->addHours(25));

        $this->artisan('safehouses:rent')->assertSuccessful();

        $this->assertSame(420, $this->character->fresh()->creds);
    }

    public function test_the_district_offers_it_and_the_safehouse_screen_renders(): void
    {
        $this->passes(5);
        $user = $this->character->user;

        $this->actingAs($user)->get(route('city.district', $this->capsule->district))->assertOk()->assertSee('Capsule hotel')->assertSee('Rent it');
        $this->actingAs($user)->post(route('safehouse.rent', $this->capsule))->assertRedirect(route('safehouse.show'));
        $this->actingAs($user)->get(route('city.district', $this->capsule->district))->assertOk()->assertSee('Your safehouse');
        $this->actingAs($user)->get(route('safehouse.show'))->assertOk()->assertSee('Capsule hotel')->assertSee('Rest');
    }

    public function test_the_screen_says_so_when_you_are_not_there(): void
    {
        $this->character->forceFill(['safehouse_id' => $this->capsule->id, 'district_id' => Safehouse::fallback()->district_id])->save();

        $this->actingAs($this->character->user)->get(route('safehouse.show'))->assertOk()->assertSee('Go there to use it');
    }

    private function home(): void
    {
        $this->character->forceFill(['safehouse_id' => $this->capsule->id])->save();
    }

    public function test_resting_eases_fatigue_at_the_homes_rate_and_stops_when_asked(): void
    {
        $this->home();
        $this->character->forceFill(['current_fatigue' => 100])->save();
        $actions = app(HomeActions::class);

        $this->assertTrue($actions->startRest($this->character->fresh()));
        $this->assertNotNull(CityActivity::query()->find($this->character->id));

        $this->travelTo(now()->addMinutes(10));
        $actions->settleRest($this->character->fresh());
        $this->assertEquals(80, $this->character->fresh()->current_fatigue);

        $this->travelTo(now()->addMinutes(5));
        $this->assertTrue($actions->stopRest($this->character->fresh()));
        $this->assertEquals(70, $this->character->fresh()->current_fatigue);
        $this->assertNull(CityActivity::query()->find($this->character->id));
    }

    public function test_a_rest_ends_by_itself_at_nothing(): void
    {
        $this->home();
        $this->character->forceFill(['current_fatigue' => 10])->save();
        $actions = app(HomeActions::class);
        $actions->startRest($this->character->fresh());

        $this->travelTo(now()->addHours(2));
        $this->assertTrue($actions->settleRest($this->character->fresh()));

        $this->assertEquals(0, $this->character->fresh()->current_fatigue);
        $this->assertNull(CityActivity::query()->find($this->character->id));
    }

    public function test_resting_locks_the_city_and_needs_something_to_ease(): void
    {
        $this->home();
        $actions = app(HomeActions::class);

        $this->assertSame(Refusal::NothingToDo, $actions->startRest($this->character->fresh()));

        $this->character->forceFill(['current_fatigue' => 50])->save();
        $actions->startRest($this->character->fresh());

        $this->assertSame(Refusal::Busy, $actions->startRest($this->character->fresh()));
        $this->assertSame(Refusal::Busy, $actions->use($this->character->fresh(), Action::Shower));
    }

    public function test_patch_meditate_and_shower_do_their_work_then_wait(): void
    {
        $this->home();
        $this->character->forceFill(['current_hitpoints' => 5, 'current_synaptic_integrity' => 5, 'current_fatigue' => 50])->save();
        $actions = app(HomeActions::class);

        $this->assertTrue($actions->use($this->character->fresh(), Action::Patch));
        $this->assertEquals(15, $this->character->fresh()->current_hitpoints);
        $this->assertSame(Refusal::Cooldown, $actions->use($this->character->fresh(), Action::Patch));

        $this->assertTrue($actions->use($this->character->fresh(), Action::Meditate));
        $this->assertEquals(15, $this->character->fresh()->current_synaptic_integrity);
        $this->assertEquals(40, $this->character->fresh()->current_fatigue);

        DB::table('character_drug_use')->insert(['character_id' => $this->character->id, 'family' => 'stimulant', 'addiction' => 3, 'tolerance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue($actions->use($this->character->fresh(), Action::Shower));
        $this->assertEquals(25, $this->character->fresh()->current_fatigue);
        $this->assertEquals(2, DB::table('character_drug_use')->where('character_id', $this->character->id)->value('addiction'));

        $this->travelTo(now()->addHours(5));
        $this->assertNull($actions->cooldown($this->character->fresh(), Action::Patch));
    }

    public function test_a_home_only_offers_what_it_lists(): void
    {
        $this->home();
        $this->capsule->update(['actions' => ['rest']]);

        $this->assertSame(Refusal::NotOffered, app(HomeActions::class)->use($this->character->fresh(), Action::Patch));
    }

    public function test_a_meal_here_fills_and_eases_by_what_the_table_is_worth_and_stops_when_full(): void
    {
        $this->home();
        $this->capsule->update(['meal_fullness' => 20, 'meal_fatigue' => 12]);
        $this->character->forceFill(['current_fatigue' => 50])->save();
        $actions = app(HomeActions::class);

        $this->assertTrue($actions->use($this->character->fresh(), Action::Eat));
        $this->assertEquals(20, $this->character->fresh()->current_fullness);
        $this->assertEquals(38, $this->character->fresh()->current_fatigue);

        $this->assertTrue($actions->use($this->character->fresh(), Action::Eat));
        $this->assertTrue($actions->use($this->character->fresh(), Action::Eat));
        $this->assertEquals(50, $this->character->fresh()->current_fullness, 'never past the maximum');
        $this->assertSame(Refusal::TooFull, $actions->use($this->character->fresh(), Action::Eat));
    }

    public function test_food_from_the_stash_fills_you_and_a_full_stomach_refuses_it(): void
    {
        $item = Item::factory()->create(['container' => 'physical', 'stackable' => true]);
        ConsumableEditor::apply($item, [['kind' => 'fullness', 'amount' => 30], ['kind' => 'ease_fatigue', 'amount' => 10]]);
        $row = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 3]);
        $this->character->forceFill(['current_fatigue' => 40])->save();
        $consumer = new Consumer;

        $consumer->use($this->character->fresh(), $row);
        $this->assertEquals(30, $this->character->fresh()->current_fullness);
        $this->assertEquals(30, $this->character->fresh()->current_fatigue);

        $consumer->use($this->character->fresh(), $row->fresh());
        $this->assertEquals(50, $this->character->fresh()->current_fullness);

        $this->assertSame(UseRefusal::TooFull, $consumer->use($this->character->fresh(), $row->fresh()));
        $this->assertSame(1, $row->fresh()->quantity, 'the copy is kept');
    }

    public function test_fullness_drains_on_the_minute_clock(): void
    {
        $this->character->forceFill(['current_fullness' => 10])->save();

        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertEquals(9.93, $this->character->fresh()->current_fullness);
    }

    private function dose(string $family, int $wakefulness): void
    {
        $item = Item::factory()->create(['container' => 'physical']);
        Drug::create(['item_id' => $item->id, 'family' => $family, 'duration_minutes' => 60, 'wakefulness' => $wakefulness]);
        Dose::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'potency' => 1, 'started_at' => now(), 'expires_at' => now()->addHour()]);
    }

    public function test_stimulants_add_up_and_stop_you_resting_until_an_opiate_or_medical_dose_lets_you(): void
    {
        $this->home();
        $this->character->forceFill(['current_fatigue' => 50])->save();
        $actions = app(HomeActions::class);

        $this->dose('stimulant', 6);
        $this->assertTrue($actions->startRest($this->character->fresh()), 'six is under the limit');
        $actions->stopRest($this->character->fresh());

        $this->dose('stimulant', 6);
        $this->assertSame(Refusal::Wired, $actions->startRest($this->character->fresh()));
        $this->actingAs($this->character->user)->get(route('safehouse.show'))->assertOk()->assertSee('too wired to rest');

        $this->dose('opiate', 0);
        $this->assertTrue($actions->startRest($this->character->fresh()));
    }

    public function test_taking_a_stimulant_part_way_ends_the_rest_and_keeps_what_it_earned(): void
    {
        $this->home();
        $this->character->forceFill(['current_fatigue' => 100])->save();
        $actions = app(HomeActions::class);
        $actions->startRest($this->character->fresh());

        $this->travelTo(now()->addMinutes(10));
        $this->dose('stimulant', 10);
        $this->assertTrue($actions->settleRest($this->character->fresh()));

        $this->assertEquals(80, $this->character->fresh()->current_fatigue);
        $this->assertNull(CityActivity::query()->find($this->character->id));
    }

    public function test_it_cannot_be_used_away_from_the_district(): void
    {
        $this->home();
        $this->character->forceFill(['district_id' => Safehouse::fallback()->district_id, 'current_fatigue' => 50])->save();

        $this->assertSame(Refusal::NotHere, app(HomeActions::class)->startRest($this->character->fresh()));
    }

    public function test_the_buttons_post_and_the_editor_keeps_the_action_list(): void
    {
        $this->home();
        $this->character->forceFill(['current_fatigue' => 50])->save();
        $user = $this->character->user;

        $this->actingAs($user)->post(route('safehouse.act', 'rest'))->assertSessionHas('status');
        $this->actingAs($user)->get(route('safehouse.show'))->assertOk()->assertSee('Get up');
        $this->actingAs($user)->post(route('safehouse.rest.stop'))->assertSessionHas('status');
        $this->actingAs($user)->post(route('safehouse.act', 'nonsense'))->assertNotFound();

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->put(route('admin.safehouses.update', $this->capsule), [
            'title' => 'Capsule hotel', 'district_id' => $this->capsule->district_id, 'rent' => 40, 'security' => 0, 'stealth' => 0,
            'rest_rate' => 2, 'actions' => ['rest', 'shower'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['rest', 'shower'], $this->capsule->fresh()->actions);
    }

    public function test_a_collapse_wakes_you_where_you_still_live(): void
    {
        $this->passes(5);
        app(Renter::class)->rent($this->character, $this->capsule);
        $this->character->forceFill(['creds' => 0])->save();
        $this->travelTo(now()->addHours(60));

        $this->character->forceFill(['collapse_pending' => true])->save();
        app(Collapse::class)->trigger($this->character->fresh());

        $this->assertSame(Safehouse::fallback()->district_id, $this->character->fresh()->district_id);
    }
}
