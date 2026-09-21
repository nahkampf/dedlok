<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\WaitingRoom;
use App\Drugs\DoseRefusal;
use App\Drugs\DrugLedger;
use App\Drugs\DrugTaker;
use App\Drugs\Family;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\Dose;
use App\Models\Drug;
use App\Models\DrugUse;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Stats\InjuryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Taking one, and what it costs afterwards.
 *
 * THE LOOP: a dose gives what it says, tolerance makes the next one worth
 * less, addiction makes stopping something you carry, and both come off slowly
 * if left alone. Taking more than a body holds is allowed and costs nerve
 * tissue, which is the tension the whole mechanic rests on.
 *
 * TOLERANCE IS A PROPERTY OF THE DOSE. It is read when the dose is taken and
 * frozen onto the row, which is the decision that let this exist without
 * teaching the resolver that one modifier can scale another.
 */
final class DrugTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));
    }

    private function drug(
        Family $family = Family::Stimulant,
        int $minutes = 60,
        float $addictive = 4.0,
        float $tolerance = 5.0,
        int $focus = 10,
    ): Item {
        $item = Item::factory()->create([
            'container' => 'physical',
            'modifiers' => [
                ['op' => 'add', 'amount' => $focus, 'target' => ['id' => 'focus']],
                // A cost as well as a gift, so the rule that tolerance never
                // softens the downside has something to be true about.
                ['op' => 'add', 'amount' => -2, 'target' => ['id' => 'memory']],
            ],
        ]);

        Drug::query()->create([
            'item_id' => $item->id,
            'family' => $family,
            'duration_minutes' => $minutes,
            'addictive' => $addictive,
            'tolerance_gain' => $tolerance,
        ]);

        return $item->fresh();
    }

    private function carrying(Item $item, int $quantity = 5): InventoryItem
    {
        return InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
        ]);
    }

    private function taker(): DrugTaker
    {
        return new DrugTaker;
    }

    private function ledger(): DrugLedger
    {
        return new DrugLedger;
    }

    // ---- taking one --------------------------------------------------------

    public function test_a_dose_is_in_effect_and_spends_a_copy(): void
    {
        $row = $this->carrying($this->drug());

        $before = $this->character->fresh()->stat('focus')->value;

        $result = $this->taker()->take($this->character, $row);

        $this->assertTrue($result->succeeded());
        $this->assertSame(4, (int) $row->fresh()->quantity, 'one copy gone');
        $this->assertSame(
            $before + 10,
            $this->character->fresh()->stat('focus')->value,
            'and it is doing something',
        );
    }

    /** The last copy takes the row with it rather than leaving a zero. */
    public function test_the_last_one_takes_the_row_with_it(): void
    {
        $row = $this->carrying($this->drug(), quantity: 1);

        $this->taker()->take($this->character, $row);

        $this->assertNull(InventoryItem::query()->find($row->id));
        $this->assertSame(1, Dose::query()->count());
    }

    public function test_a_dose_wears_off(): void
    {
        $row = $this->carrying($this->drug(minutes: 30));
        $this->taker()->take($this->character, $row);

        $before = $this->character->fresh()->stat('focus')->value;

        $this->travel(31)->minutes();

        $this->assertSame(
            $before - 10,
            $this->character->fresh()->stat('focus')->value,
            'the effect is gone without anything having swept it',
        );
    }

    /** Two doses are two rows and they add up, with no stacking rule to write. */
    public function test_two_doses_add_up(): void
    {
        $row = $this->carrying($this->drug());

        $before = $this->character->fresh()->stat('focus')->value;

        $this->taker()->take($this->character, $row->fresh());
        $this->taker()->take($this->character, $row->fresh());

        $this->assertSame($before + 20, $this->character->fresh()->stat('focus')->value);
    }

    public function test_something_that_is_not_a_drug_is_refused(): void
    {
        $row = $this->carrying(Item::factory()->create(['container' => 'physical']));

        $result = $this->taker()->take($this->character, $row);

        $this->assertSame(DoseRefusal::NotADrug, $result->refusal);
    }

    // ---- what it costs -----------------------------------------------------

    public function test_the_books_move_per_family(): void
    {
        $this->taker()->take($this->character, $this->carrying($this->drug(Family::Stimulant)));

        $books = $this->ledger()->for($this->character, Family::Stimulant);

        $this->assertSame(4.0, (float) $books->addiction);
        $this->assertSame(5.0, (float) $books->tolerance);

        // And the other family is untouched: somebody can be deep into one and
        // clean of another.
        $this->assertSame(0.0, (float) $this->ledger()->for($this->character, Family::Opiate)->addiction);
    }

    /**
     * TOLERANCE CUTS THE NEXT ONE, not the one being taken, and only its
     * POSITIVE half: a drug never does less TO you for being familiar.
     */
    public function test_tolerance_weakens_what_a_new_dose_gives(): void
    {
        DrugUse::query()->create([
            'character_id' => $this->character->id,
            'family' => Family::Stimulant->value,
            'addiction' => 0,
            'tolerance' => 50,
        ]);

        $row = $this->carrying($this->drug());
        $focus = $this->character->fresh()->stat('focus')->value;
        $memory = $this->character->fresh()->stat('memory')->value;

        $result = $this->taker()->take($this->character, $row);

        $this->assertSame(0.8, $result->potency, 'the second band');
        $this->assertSame($focus + 8, $this->character->fresh()->stat('focus')->value);
        $this->assertSame($memory - 2, $this->character->fresh()->stat('memory')->value, 'the cost is untouched');
    }

    /** And a dose already taken keeps the strength it was taken at. */
    public function test_a_running_dose_is_not_weakened_under_you(): void
    {
        $row = $this->carrying($this->drug());
        $focus = $this->character->fresh()->stat('focus')->value;

        $this->taker()->take($this->character, $row);

        DrugUse::query()->where('character_id', $this->character->id)
            ->update(['tolerance' => 75]);

        $this->assertSame($focus + 10, $this->character->fresh()->stat('focus')->value);
    }

    // ---- withdrawal --------------------------------------------------------

    public function test_going_without_past_the_threshold_is_something_you_carry(): void
    {
        DrugUse::query()->create([
            'character_id' => $this->character->id,
            'family' => Family::Opiate->value,
            'addiction' => 45,
            'tolerance' => 0,
        ]);

        $focus = $this->character->fresh()->stat('focus')->value;

        $this->assertTrue($this->ledger()->reconcile($this->character, Family::Opiate));

        $this->assertSame(1, CharacterModifier::query()->where('definition_key', 'withdrawal_opiate')->count());
        $this->assertSame($focus - 4, $this->character->fresh()->stat('focus')->value);

        // And the player is told, once, when it begins.
        $this->assertSame(1, Notification::query()->count());
        $this->ledger()->reconcile($this->character->fresh(), Family::Opiate);
        $this->assertSame(1, Notification::query()->count(), 'and not again every sweep');
    }

    /**
     * TAKING ONE CLEARS IT AND DOES NOT CURE IT. The row goes for as long as
     * something of that family is in them; the figure underneath does not
     * move, and only time brings it down.
     */
    public function test_a_dose_clears_the_withdrawal_without_curing_it(): void
    {
        DrugUse::query()->create([
            'character_id' => $this->character->id,
            'family' => Family::Stimulant->value,
            'addiction' => 45,
            'tolerance' => 0,
        ]);

        $this->ledger()->reconcile($this->character, Family::Stimulant);
        $this->assertSame(1, CharacterModifier::query()->count());

        $this->taker()->take($this->character, $this->carrying($this->drug()));

        $this->assertSame(0, CharacterModifier::query()->count(), 'cleared');
        $this->assertSame(
            49.0,
            (float) $this->ledger()->for($this->character, Family::Stimulant)->addiction,
            'and the need is worse, not better',
        );
    }

    /** The sweep is what makes it true again once the dose wears off. */
    public function test_the_sweep_writes_it_back_when_the_dose_wears_off(): void
    {
        DrugUse::query()->create([
            'character_id' => $this->character->id,
            'family' => Family::Stimulant->value,
            'addiction' => 45,
            'tolerance' => 0,
        ]);

        $this->taker()->take($this->character, $this->carrying($this->drug(minutes: 30)));
        $this->assertSame(0, CharacterModifier::query()->count());

        $this->travel(31)->minutes();

        $this->artisan('drugs:withdraw')->assertSuccessful();

        $this->assertSame(1, CharacterModifier::query()->where('definition_key', 'withdrawal_stimulant')->count());
    }

    // ---- overdose ----------------------------------------------------------

    /**
     * A BODY HOLDS SO MANY AT ONCE. The third of a family is one too many at
     * no tolerance, and it costs nerve tissue — the same injury a freeze and
     * an ICE flatline deepen.
     */
    /**
     * A BODY HOLDS SO MANY AT ONCE, and going past it costs three separate
     * things: the ceiling comes down as an injury, the body takes ordinary
     * damage through the door, and past the family's line the heart stops.
     */
    public function test_one_too_many_costs_the_ceiling_and_the_body(): void
    {
        $row = $this->carrying($this->drug(tolerance: 0.0));
        $hp = (int) $this->character->fresh()->current_hitpoints;

        $this->taker()->take($this->character, $row->fresh());
        $this->taker()->take($this->character, $row->fresh());

        $result = $this->taker()->take($this->character, $row->fresh());

        $this->assertTrue($result->overdosed());
        $this->assertSame(1, $result->overdose->excess);
        $this->assertSame(2.0, $result->overdose->injury);
        $this->assertSame(4, $result->overdose->hitpoints, 'a stimulant, at four a dose');
        $this->assertFalse($result->overdose->lethal, 'one past the line is not three');

        // Asserted on the INJURY rather than on maximum SI, because the doses
        // themselves move the attributes the maximum is derived from.
        $this->assertSame(
            -2.0,
            (new InjuryLedger)->carried($this->character->fresh(), 'neural_damage'),
            'into the same neural damage a freeze deepens',
        );

        $this->assertSame($hp - 4, (int) $this->character->fresh()->current_hitpoints);
    }

    /** The body's half goes through the damage door, so a resistance reaches it. */
    public function test_the_body_can_be_toughened_against_it(): void
    {
        Config::set('effects.constitution', [
            'label' => 'Constitution',
            'stacking' => 'sum',
            'modifiers' => [
                ['op' => 'percentage_of_base', 'amount' => -50, 'target' => ['all_of' => ['damage', 'overdose']]],
            ],
        ]);

        CharacterModifier::query()->create([
            'character_id' => $this->character->id,
            'definition_key' => 'constitution',
        ]);

        $row = $this->carrying($this->drug(tolerance: 0.0));
        $hp = (int) $this->character->fresh()->current_hitpoints;

        $this->taker()->take($this->character, $row->fresh());
        $this->taker()->take($this->character, $row->fresh());
        $this->taker()->take($this->character, $row->fresh());

        $this->assertSame($hp - 2, (int) $this->character->fresh()->current_hitpoints, 'half of four');
    }

    /**
     * FAR ENOUGH PAST THE LINE AND THE HEART STOPS. Not big damage: the body
     * is emptied outright, because a flatline is not a matter of degree and
     * nothing resists it. What it costs is the waiting screen.
     */
    public function test_far_enough_past_the_line_stops_the_heart(): void
    {
        $row = $this->carrying($this->drug(Family::Opiate, tolerance: 0.0), quantity: 6);

        // An opiate stops a heart at two past what the body holds, so the
        // fourth simultaneous dose is the one.
        foreach (range(1, 3) as $ignored) {
            $this->taker()->take($this->character, $row->fresh());
        }

        $result = $this->taker()->take($this->character, $row->fresh());

        $this->assertTrue($result->overdose->lethal);
        $this->assertSame(0, (int) $this->character->fresh()->current_hitpoints);
        $this->assertTrue(app(WaitingRoom::class)->isDown($this->character->fresh()));
    }

    /** And a family that does not stop hearts never does, however much is taken. */
    public function test_a_psychedelic_does_not_stop_a_heart(): void
    {
        $row = $this->carrying($this->drug(Family::Psychedelic, tolerance: 0.0), quantity: 9);

        foreach (range(1, 8) as $ignored) {
            $result = $this->taker()->take($this->character, $row->fresh());
        }

        $this->assertTrue($result->overdose->happened());
        $this->assertFalse($result->overdose->lethal);
    }

    /** A drug may disagree with its family, which is what the column is for. */
    public function test_a_drug_can_be_deadlier_than_its_family(): void
    {
        $item = $this->drug(Family::Psychedelic, tolerance: 0.0);
        $item->drug->forceFill(['lethal_at' => 1])->save();

        $row = $this->carrying($item->fresh(), quantity: 4);

        $this->taker()->take($this->character, $row->fresh());
        $this->taker()->take($this->character, $row->fresh());

        $this->assertTrue($this->taker()->take($this->character, $row->fresh())->overdose->lethal);
    }

    /** And tolerance buys the headroom back, which is what makes it worth having. */
    public function test_tolerance_widens_what_a_body_holds(): void
    {
        DrugUse::query()->create([
            'character_id' => $this->character->id,
            'family' => Family::Stimulant->value,
            'addiction' => 0,
            'tolerance' => 25,
        ]);

        $row = $this->carrying($this->drug(tolerance: 0.0));

        $this->taker()->take($this->character, $row->fresh());
        $this->taker()->take($this->character, $row->fresh());

        $this->assertFalse($this->taker()->take($this->character, $row->fresh())->overdosed());
    }

    // ---- a day off ---------------------------------------------------------

    public function test_both_figures_come_off_overnight(): void
    {
        DrugUse::query()->create([
            'character_id' => $this->character->id,
            'family' => Family::Stimulant->value,
            'addiction' => 10,
            'tolerance' => 10,
        ]);

        $this->artisan('drugs:metabolise')->assertSuccessful();

        $books = $this->ledger()->for($this->character, Family::Stimulant);

        $this->assertSame(9.0, (float) $books->addiction);
        $this->assertSame(8.0, (float) $books->tolerance, 'tolerance falls faster');
    }

    /** Books that reach nothing on both figures are deleted rather than kept at zero. */
    public function test_clean_books_are_closed(): void
    {
        DrugUse::query()->create([
            'character_id' => $this->character->id,
            'family' => Family::Stimulant->value,
            'addiction' => 0.5,
            'tolerance' => 1,
        ]);

        $this->artisan('drugs:metabolise')->assertSuccessful();

        $this->assertSame(0, DrugUse::query()->count());
    }

    public function test_the_metabolism_sweeps_up_what_has_worn_off(): void
    {
        $this->taker()->take($this->character, $this->carrying($this->drug(minutes: 10)));

        $this->travel(11)->minutes();
        $this->artisan('drugs:metabolise')->assertSuccessful();

        $this->assertSame(0, Dose::query()->count());
    }

    // ---- over HTTP ---------------------------------------------------------

    public function test_the_stash_can_take_one(): void
    {
        $row = $this->carrying($this->drug());

        $this->actingAs($this->character->user)
            ->from(route('inventory.index'))
            ->post(route('inventory.take', $row))
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('status');

        $this->assertSame(1, Dose::query()->count());
    }

    public function test_an_overdose_is_said_out_loud(): void
    {
        Config::set('drugs.overdose.base', 0);

        $row = $this->carrying($this->drug());

        $this->actingAs($this->character->user)
            ->from(route('inventory.index'))
            ->post(route('inventory.take', $row))
            ->assertSessionHas('error');
    }

    public function test_somebody_elses_dose_is_not_there(): void
    {
        $stranger = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));

        $row = InventoryItem::query()->create([
            'character_id' => $stranger->id,
            'item_id' => $this->drug()->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->character->user)
            ->post(route('inventory.take', $row))
            ->assertNotFound();
    }
}
