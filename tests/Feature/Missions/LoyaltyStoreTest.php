<?php

declare(strict_types=1);

namespace Tests\Feature\Missions;

use App\Models\Character;
use App\Models\Entity;
use App\Models\EntityOffer;
use App\Models\Exchange;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
use App\Standing\LedgerKind;
use App\Standing\LoyaltyExchange;
use App\Standing\LoyaltyRefusal;
use App\Standing\Service;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Spending loyalty at an entity's own shelf.
 *
 * WHAT THIS FILE IS FOR: loyalty is the only currency the store takes, the
 * books stay equal to the sum of their entries afterwards, and the two gates
 * are asked in the right order — the desk decides whether it deals with
 * somebody at all, and only then does the row get to ask for more.
 */
final class LoyaltyStoreTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Entity $entity;

    private Exchange $desk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(['creds' => 0]);
        $this->entity = Entity::query()->interactable()->firstOrFail();

        $system = System::factory()->at(8, 8)->ownedBy($this->entity)->create([
            'type' => 'exchange',
            'grade' => 1,
        ]);

        $this->desk = Exchange::query()->create([
            'system_id' => $system->id,
            'grade' => 2,
            'agent' => 'Mr Nakata',
            'min_standing' => 0,
        ]);
    }

    public function test_loyalty_buys_the_thing_and_the_books_still_balance(): void
    {
        $offer = $this->offering(price: 40);
        $this->earn(loyalty: 100, standing: 5);

        $item = app(LoyaltyExchange::class)->buy($this->character, $this->desk, $offer);

        $this->assertInstanceOf(Item::class, $item);

        // It lands LOOSE, through the same granter every other way of
        // acquiring something uses.
        $row = InventoryItem::query()->where('character_id', $this->character->id)->sole();
        $this->assertSame($offer->item_id, $row->item_id);
        $this->assertNull($row->parent_id);

        $ledger = new StandingLedger;

        $this->assertSame(60, $ledger->loyaltyFor($this->character, $this->entity));

        // THE BOOKS ARE ALWAYS THE SUM OF THE LEDGER, which is the invariant
        // every writer of these two tables has to leave standing.
        $this->assertSame([], $ledger->verify());
    }

    /** Spending is a courtesy, so it nudges their opinion — and only just. */
    public function test_spending_earns_a_little_standing(): void
    {
        Config::set('standing.store.standing_per_loyalty', 0.001);

        $offer = $this->offering(price: 1000);
        $this->earn(loyalty: 1000, standing: 5);

        $before = (new StandingLedger)->standingFor($this->character, $this->entity);

        app(LoyaltyExchange::class)->buy($this->character, $this->desk, $offer);

        $after = (new StandingLedger)->standingFor($this->character, $this->entity);

        $this->assertSame(round($before + 1.0, 3), round($after, 3), 'a thousand loyalty is worth one point');
        $this->assertSame([], (new StandingLedger)->verify());
    }

    public function test_it_cannot_be_bought_without_the_loyalty(): void
    {
        $offer = $this->offering(price: 40);
        $this->earn(loyalty: 10, standing: 5);

        $this->assertSame(
            LoyaltyRefusal::CannotAfford,
            app(LoyaltyExchange::class)->buy($this->character, $this->desk, $offer),
        );

        $this->assertSame(0, InventoryItem::query()->where('character_id', $this->character->id)->count());
        $this->assertSame(10, (new StandingLedger)->loyaltyFor($this->character, $this->entity));
    }

    /** The DESK's gate, which is the same one their work reads. */
    public function test_a_desk_that_will_not_deal_with_you_will_not_sell_to_you(): void
    {
        $this->desk->forceFill(['min_standing' => 20])->save();

        $offer = $this->offering(price: 1);
        $this->earn(loyalty: 500, standing: 5);

        $this->assertSame(
            LoyaltyRefusal::NotWelcome,
            app(LoyaltyExchange::class)->buy($this->character, $this->desk->fresh(), $offer),
        );
    }

    /** The ROW's gate, asked second and almost always nothing. */
    public function test_a_row_can_ask_for_more_than_the_desk_does(): void
    {
        $offer = $this->offering(price: 1, minStanding: 25);
        $this->earn(loyalty: 500, standing: 5);

        $this->assertSame(
            LoyaltyRefusal::NotForYou,
            app(LoyaltyExchange::class)->buy($this->character, $this->desk, $offer),
        );

        // And the same row, once they are trusted.
        $this->earn(loyalty: 0, standing: 30);

        $this->assertInstanceOf(
            Item::class,
            app(LoyaltyExchange::class)->buy($this->character, $this->desk, $offer),
        );
    }

    /**
     * A ROW OUTSIDE THIS DESK'S GRADE IS NOT ON ITS SHELF.
     *
     * What a grade 5 desk keeps must not be buyable at the grade 2 desk round
     * the corner, and as far as this desk is concerned the row does not exist.
     */
    public function test_another_grades_row_is_not_on_this_shelf(): void
    {
        $offer = $this->offering(price: 1, minGrade: 5);
        $this->earn(loyalty: 500, standing: 5);

        $this->assertSame(
            LoyaltyRefusal::Gone,
            app(LoyaltyExchange::class)->buy($this->character, $this->desk, $offer),
        );

        $this->assertCount(0, app(LoyaltyExchange::class)->shelf($this->entity, (int) $this->desk->grade));
    }

    /**
     * AN OFFER SPANS A RANGE, so one row reaches several desks.
     *
     * Most of what an entity sells is available across several of its desks,
     * and the common case should not be the one that needs a row each.
     */
    public function test_a_range_reaches_every_desk_inside_it(): void
    {
        $offer = $this->offering(price: 10, minGrade: 1, maxGrade: 3);
        $this->earn(loyalty: 100, standing: 5);

        // The desk in this fixture is grade 2, inside the span.
        $this->assertTrue($offer->keptAtGrade(2));
        $this->assertCount(1, app(LoyaltyExchange::class)->shelf($this->entity, 2));

        // Both ends are inclusive, and grade 4 is outside.
        $this->assertCount(1, app(LoyaltyExchange::class)->shelf($this->entity, 1));
        $this->assertCount(1, app(LoyaltyExchange::class)->shelf($this->entity, 3));
        $this->assertCount(0, app(LoyaltyExchange::class)->shelf($this->entity, 4));

        $this->assertInstanceOf(
            Item::class,
            app(LoyaltyExchange::class)->buy($this->character, $this->desk, $offer),
        );
    }

    /** One offer per item per entity: an overlap would be two prices at one desk. */
    public function test_an_entity_cannot_offer_one_item_twice(): void
    {
        $offer = $this->offering(price: 10, minGrade: 1, maxGrade: 2);

        $this->expectException(UniqueConstraintViolationException::class);

        EntityOffer::query()->create([
            'entity_id' => $this->entity->id,
            'min_grade' => 3,
            'max_grade' => 5,
            'item_id' => $offer->item_id,
            'loyalty_price' => 5,
            'min_standing' => 0,
        ]);
    }

    public function test_the_shelf_lists_what_this_desk_keeps(): void
    {
        $this->offering(price: 90);
        $this->offering(price: 10);
        $this->offering(price: 50, minGrade: 4);

        $shelf = app(LoyaltyExchange::class)->shelf($this->entity, (int) $this->desk->grade);

        $this->assertCount(2, $shelf);
        $this->assertSame([10, 90], $shelf->pluck('loyalty_price')->all(), 'cheapest first');
    }

    /**
     * A SERVICE IS DONE, NOT HANDED OVER: heat comes down, nothing lands in
     * the stash, and the books still balance.
     */
    public function test_a_service_sheds_heat_and_floors_at_nothing(): void
    {
        $offer = $this->servicing(price: 1000, amount: 10);
        $this->earn(loyalty: 2000, standing: 5);
        $this->character->forceFill(['heat' => 13.5])->save();

        $store = app(LoyaltyExchange::class);

        $this->assertSame(Service::ShedHeat, $store->buy($this->character, $this->desk, $offer));
        $this->assertSame(3.5, (float) $this->character->fresh()->heat);

        // Less left than the service takes: it floors, and costs the same.
        $this->assertSame(Service::ShedHeat, $store->buy($this->character, $this->desk, $offer));
        $this->assertSame(0.0, (float) $this->character->fresh()->heat);

        $this->assertSame(0, InventoryItem::query()->where('character_id', $this->character->id)->count());
        $this->assertSame(0, (new StandingLedger)->loyaltyFor($this->character, $this->entity));
        $this->assertSame([], (new StandingLedger)->verify());
    }

    /** Loyalty spent on nothing is refused, and none of it is taken. */
    public function test_a_service_that_would_do_nothing_is_refused(): void
    {
        $offer = $this->servicing(price: 1000, amount: 10);
        $this->earn(loyalty: 1000, standing: 5);
        $this->character->forceFill(['heat' => 0])->save();

        $this->assertSame(
            LoyaltyRefusal::NothingToDo,
            app(LoyaltyExchange::class)->buy($this->character, $this->desk, $offer),
        );

        $this->assertSame(1000, (new StandingLedger)->loyaltyFor($this->character, $this->entity));
    }

    /** It has no item, and must not be filtered off the shelf for that. */
    public function test_a_service_is_on_the_shelf(): void
    {
        $this->servicing(price: 1000, amount: 10);

        $this->assertCount(1, app(LoyaltyExchange::class)->shelf($this->entity, (int) $this->desk->grade));
    }

    /** Lockjaw's arrives with the schema. */
    public function test_lockjaw_sells_ten_heat_for_a_thousand(): void
    {
        $offer = EntityOffer::query()
            ->whereHas('entity', fn ($q) => $q->where('name', 'Lockjaw'))
            ->where('service', Service::ShedHeat->value)
            ->sole();

        $this->assertSame([1000, 10.0, 1, 5], [$offer->loyalty_price, $offer->service_amount, $offer->min_grade, $offer->max_grade]);
    }

    // ---- fixtures -----------------------------------------------------------

    private function servicing(int $price, float $amount): EntityOffer
    {
        // Clear whatever a migration already put on this entity's shelf.
        EntityOffer::query()->where('entity_id', $this->entity->id)->where('service', Service::ShedHeat->value)->delete();

        return EntityOffer::query()->create([
            'entity_id' => $this->entity->id,
            'min_grade' => 1,
            'max_grade' => 5,
            'service' => Service::ShedHeat,
            'service_amount' => $amount,
            'loyalty_price' => $price,
            'min_standing' => 0,
        ]);
    }

    /** A row on the shelf. Kept by this desk's grade alone unless told otherwise. */
    private function offering(int $price, float $minStanding = 0, ?int $minGrade = null, ?int $maxGrade = null): EntityOffer
    {
        $low = $minGrade ?? (int) $this->desk->grade;

        return EntityOffer::query()->create([
            'entity_id' => $this->entity->id,
            'min_grade' => $low,
            'max_grade' => $maxGrade ?? $low,
            'item_id' => Item::factory()->create(['on_market' => false])->id,
            'loyalty_price' => $price,
            'min_standing' => $minStanding,
        ]);
    }

    /** Both books moved the only way anything moves them. */
    private function earn(int $loyalty, float $standing): void
    {
        $ledger = new StandingLedger;

        if ($loyalty > 0) {
            $ledger->record($this->character, $this->entity, StandingReason::MissionCompleted, $loyalty, LedgerKind::Loyalty);
        }

        $current = $ledger->standingFor($this->character, $this->entity);

        if ($standing > $current) {
            $ledger->record($this->character, $this->entity, StandingReason::MissionCompleted, $standing - $current);
        }
    }
}
