<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\City\ActivityKind;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Safehouse;
use App\Models\SafehouseLead;
use App\Safehouses\Leads;
use App\Safehouses\Raid;
use App\Safehouses\Refusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sensing, working out and breaking into another player's home. FATIGUE.md, *Raiding and burning*.
 */
final class SafehouseLeadTest extends TestCase
{
    use RefreshDatabase;

    private Safehouse $house;

    private Character $finder;

    private Character $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $dock = District::query()->where('slug', 'dockside')->firstOrFail();
        $away = District::query()->where('id', '!=', $dock->id)->firstOrFail();
        $this->house = Safehouse::create([
            'slug' => 'capsule', 'title' => 'Capsule hotel', 'district_id' => $dock->id, 'rent' => 40, 'security' => 10, 'stealth' => 1,
            'rest_rate' => 2, 'storage_slots' => 3, 'actions' => ['rest'],
        ]);
        $attributes = array_fill_keys(Character::ATTRIBUTES, 20);
        $this->finder = Character::factory()->create($attributes + ['archetype' => 3, 'district_id' => $dock->id, 'creds' => 100]);
        $this->owner = Character::factory()->create($attributes + ['archetype' => 3, 'district_id' => $away->id, 'safehouse_id' => $this->house->id, 'rent_due_at' => now()->addDay()]);
    }

    /** @param float|int ...$dice the check die first, then the percentile/credit die; the last repeats */
    private function leads(float $check, int ...$rolls): Leads
    {
        return new Leads(
            new CheckRoller(8, 0.0085, ['exploration' => 0.0, 'safehouse_find' => 0.02], fn (): float => $check),
            roll: function (int $min, int $max) use (&$rolls): int {
                $next = array_shift($rolls) ?? $min;

                return max($min, min($max, $next));
            },
        );
    }

    private function lead(array $with = []): SafehouseLead
    {
        return SafehouseLead::query()->create(array_merge([
            'character_id' => $this->finder->id, 'target_id' => $this->owner->id, 'safehouse_id' => $this->house->id, 'number' => 1,
        ], $with));
    }

    public function test_exploring_may_sense_a_held_home_once(): void
    {
        $leads = $this->leads(0.0);

        $this->assertSame('safehouse_sensed', $leads->discover($this->finder));
        $lead = SafehouseLead::query()->firstOrFail();
        $this->assertSame([1, 0.0, 0.0], [$lead->number, $lead->discovery, $lead->security]);
        $this->assertSame('Suspicious place 1', $lead->load('safehouse', 'target')->name());
        $this->assertNull($leads->discover($this->finder), 'a home already sensed is not sensed twice');
    }

    public function test_a_failed_sense_makes_nothing(): void
    {
        $this->assertNull($this->leads(0.9999)->discover($this->finder));
        $this->assertSame(0, SafehouseLead::query()->count());
    }

    public function test_ask_around_outcomes(): void
    {
        $lead = $this->lead();

        // 1 = nothing
        $this->assertFalse($this->leads(0.0, 1)->askAround($this->finder, $lead)->won);
        $this->assertSame(100, $this->finder->refresh()->creds);

        // 70 = clues (61-75): pays 10, discovery +2
        $result = $this->leads(0.0, 70, 10)->askAround($this->finder, $lead);
        $this->assertTrue($result->won);
        $this->assertSame(90, $this->finder->refresh()->creds);
        $this->assertEquals(2, $lead->refresh()->discovery);
        $this->assertTrue($lead->load('safehouse')->isKnown());
        $this->assertSame(Refusal::Identified, $this->leads(0.0, 1)->askAround($this->finder, $lead));

        // misdirected never takes it below nothing
        $other = $this->lead(['target_id' => Character::factory()->create(['archetype' => 3, 'safehouse_id' => $this->house->id])->id]);
        $this->leads(0.0, 80)->askAround($this->finder, $other);
        $this->assertEquals(0, $other->refresh()->discovery);
    }

    public function test_a_stakeout_works_out_who_lives_there_and_stops(): void
    {
        $lead = $this->lead(['discovery' => 0.5]);
        $leads = $this->leads(0.0, 100, 100);

        $this->assertTrue($leads->begin($this->finder, $lead, ActivityKind::Stakeout));
        $this->assertSame(Refusal::Busy, $leads->begin($this->finder, $lead, ActivityKind::Stakeout));

        CityActivity::query()->update(['pass_ends_at' => now()->subSeconds(30)]);
        $messages = $leads->settle($this->finder);

        $this->assertEquals(1.3, $lead->refresh()->discovery);
        $this->assertSame('safehouse_identified', $messages[0][0]);
        $this->assertSame(0, CityActivity::query()->count());
        $this->assertGreaterThan(0, (float) $this->finder->refresh()->current_fatigue);
    }

    public function test_scouting_the_security_clears_it(): void
    {
        $lead = $this->lead(['discovery' => 5, 'security' => 9]);
        $leads = $this->leads(0.0);

        $this->assertTrue($leads->begin($this->finder, $lead, ActivityKind::ScopeOut));
        CityActivity::query()->update(['pass_ends_at' => now()->subSeconds(30)]);
        $messages = $leads->settle($this->finder);

        $this->assertEquals(12, $lead->refresh()->security);
        $this->assertSame('safehouse_scoped', $messages[0][0]);
        $this->assertSame(Refusal::NothingToDo, $leads->begin($this->finder, $lead, ActivityKind::ScopeOut));
    }

    public function test_break_in_then_rob_or_burn(): void
    {
        $lead = $this->lead(['discovery' => 5, 'security' => 11]);
        $stored = InventoryItem::query()->create([
            'character_id' => $this->owner->id, 'item_id' => Item::factory()->create(['container' => 'physical', 'stackable' => false])->id,
            'quantity' => 1, 'safehouse_id' => $this->house->id,
        ]);

        $this->assertSame(Refusal::NotInside, $this->leads(0.0)->rob($this->finder, $lead));
        $this->assertTrue($this->leads(0.0)->breakIn($this->finder, $lead)->won);
        $this->assertTrue($lead->refresh()->inside);

        $this->assertTrue($this->leads(0.0)->rob($this->finder, $lead)->won);
        $this->assertSame($this->finder->id, $stored->refresh()->character_id);

        $this->assertInstanceOf(Raid::class, $this->leads(0.0)->burn($this->finder, $lead));
        $this->assertNull($this->owner->refresh()->safehouse_id);
        $this->assertSame(0, SafehouseLead::query()->count());
    }

    public function test_a_failed_break_in_warns_the_holder(): void
    {
        $lead = $this->lead(['discovery' => 5]);

        $this->assertFalse($this->leads(0.9999)->breakIn($this->finder, $lead)->won);
        $this->assertFalse($lead->refresh()->inside);
        $this->assertSame(1, Notification::query()->where('user_id', $this->owner->user_id)->where('title', 'Somebody tried to break in')->count());
    }

    public function test_a_robbed_home_cools_down_for_everyone(): void
    {
        $lead = $this->lead(['discovery' => 5, 'security' => 11, 'inside' => true]);
        $row = fn () => InventoryItem::query()->create([
            'character_id' => $this->owner->id, 'item_id' => Item::factory()->create(['container' => 'physical', 'stackable' => false])->id,
            'quantity' => 1, 'safehouse_id' => $this->house->id,
        ]);
        $row();

        $this->assertTrue($this->leads(0.0)->rob($this->finder, $lead)->won);
        $this->assertTrue($this->owner->refresh()->raid_cooldown_until->isFuture());

        $row();
        $this->assertSame(Refusal::Cooldown, $this->leads(0.0)->rob($this->finder, $lead));
        $this->assertSame(Refusal::Cooldown, $this->leads(0.0)->breakIn($this->finder, $lead));

        $this->travel(25)->hours();
        $this->assertTrue($this->leads(0.0)->rob($this->finder->refresh(), $lead)->won);
    }

    public function test_the_police_may_turn_up_and_undo_the_scouting(): void
    {
        $lead = $this->lead(['discovery' => 5, 'security' => 4]);

        // check fails (die 0.9999), then the percentile die lands under 15
        $result = $this->leads(0.9999, 10)->breakIn($this->finder, $lead);
        $this->assertFalse($result->won);
        $this->assertStringContainsString('The Police show up', $result->message);
        $this->assertEquals(0, $lead->refresh()->security);

        $lead->forceFill(['security' => 4])->save();
        $result = $this->leads(0.9999, 90)->breakIn($this->finder->refresh(), $lead);
        $this->assertStringNotContainsString('Police', $result->message);
        $this->assertEquals(4, $lead->refresh()->security);
    }

    public function test_a_lead_dies_with_the_holding_and_can_be_forgotten(): void
    {
        $lead = $this->lead();
        $this->owner->forceFill(['safehouse_id' => null])->save();

        $this->assertCount(0, $this->leads(0.0)->all($this->finder));
        $this->assertNull(SafehouseLead::query()->find($lead->id));
    }

    public function test_the_screens_work(): void
    {
        $lead = $this->lead(['discovery' => 5]);
        $user = $this->finder->user;

        $this->actingAs($user)->get(route('lead.show', $lead))->assertOk()->assertSee('Safehouse: '.$this->owner->handle)->assertSee('Break in');
        $this->actingAs($user)->get(route('tasks.index'))->assertOk()->assertSee('Forget about this');
        $this->actingAs($user)->post(route('lead.forget', $lead))->assertRedirect();
        $this->assertSame(0, SafehouseLead::query()->count());
    }

    public function test_somebody_elses_lead_is_a_404(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->owner->user)->get(route('lead.show', $lead))->assertNotFound();
    }
}
