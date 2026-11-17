<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bank\Bank;
use App\Bank\BankRefusal;
use App\Bank\LoanRefusal;
use App\Bank\Loans;
use App\Bank\LoanState;
use App\Bank\PaymentSource;
use App\Models\Character;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Location;
use App\Models\Safehouse;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kronos Capital's loans (docs/BANK.md, phase 2, slice 1): taking, instalments, interest, grace, default and payoff. */
final class LoanTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Loans $loans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => 1000]));
        $this->loans = new Loans;
    }

    private function standing(): float
    {
        return (new StandingLedger)->standingFor($this->character, Entity::query()->where('name', config('bank.entity'))->firstOrFail());
    }

    /** Empty the wallet, so an instalment cannot be drawn. */
    private function broke(): void
    {
        $this->character->forceFill(['creds' => 0])->save();
    }

    private function take(int $amount = 400): Loan
    {
        $loan = $this->loans->take($this->character, $amount);
        $this->assertInstanceOf(Loan::class, $loan);

        return $loan;
    }

    public function test_a_loan_pays_into_the_wallet_with_a_fixed_instalment_rounded_up(): void
    {
        $loan = $this->take(410);

        $this->assertSame(1410, $this->character->fresh()->creds);
        $this->assertSame(17, $loan->payment, '4% of 410 is 16.4, rounded up');
        $this->assertSame(410, $loan->principal);
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $loan->next_due_at->timestamp, 5);
    }

    public function test_the_limit_follows_standing_and_is_nothing_below_zero(): void
    {
        foreach ([0 => 3000, 1 => 6000, 5 => 12000, 10 => 35000, 25 => 70000, 50 => 125000, 75 => 225000, 100 => 400000, 7 => 21200] as $standing => $limit) {
            $this->loans->setStanding($this->character, StandingReason::BankBalance, (float) $standing);
            $this->assertSame($limit, $this->loans->limit($this->character), "standing {$standing}");
        }

        $this->loans->setStanding($this->character, StandingReason::LoanDefault, -5.0);
        $this->assertSame(0, $this->loans->limit($this->character));
        $this->assertSame(LoanRefusal::BadStanding, $this->loans->take($this->character, 100));
    }

    public function test_it_refuses_a_second_loan_too_small_and_too_large(): void
    {
        $this->assertSame(LoanRefusal::TooSmall, $this->loans->take($this->character, 99));
        $this->assertSame(LoanRefusal::OverLimit, $this->loans->take($this->character, 3001));
        $this->take(200);
        $this->assertSame(LoanRefusal::HaveLoan, $this->loans->take($this->character, 100));
    }

    public function test_the_nightly_interest_cannot_outrun_the_instalment_even_on_the_smallest_loan(): void
    {
        $min = (int) config('bank.min_loan');
        $perDay = $this->loans->instalment($min) / (config('bank.payment_hours') / 24);

        $this->assertLessThan($perDay, $this->loans->nightlyInterest($min));
    }

    public function test_interest_accrues_every_night_with_nobody_logged_in(): void
    {
        $loan = $this->take(400);

        $this->loans->nightly();
        $this->loans->nightly();

        $this->assertSame(4, $loan->fresh()->interest, '0.5% of 400 is 2 a night');
    }

    public function test_the_sweep_takes_a_due_instalment_from_the_wallet_interest_first(): void
    {
        $loan = $this->take(400);
        $this->loans->nightly();

        $this->travel(49)->hours();
        $this->loans->sweep();

        $loan->refresh();
        $this->assertSame(16, $loan->payment);
        $this->assertSame(0, $loan->interest, 'the 2 of interest was paid first');
        $this->assertSame(386, $loan->principal);
        $this->assertSame(1400 - 16, $this->character->fresh()->creds);
        $this->assertNull($loan->missed_at);
        $this->assertGreaterThan(0, $this->standing(), 'interest paid earns standing');
        $this->artisan('bank:verify')->assertSuccessful();
    }

    public function test_an_instalment_that_cannot_be_covered_starts_the_grace_clock_then_defaults(): void
    {
        $loan = $this->take(400);
        $this->broke();

        $this->travel(49)->hours();
        $this->loans->sweep();
        $this->assertNotNull($loan->fresh()->missed_at);
        $this->assertSame(LoanState::Active, $loan->fresh()->state);

        $this->travel(25)->hours();
        $this->loans->sweep();

        $this->assertSame(LoanState::Defaulted, $loan->fresh()->state);
        $this->assertEqualsWithDelta(-5.0, $this->standing(), 0.001);
    }

    public function test_money_arriving_inside_the_grace_stops_the_clock(): void
    {
        $loan = $this->take(400);
        $this->broke();
        $this->travel(49)->hours();
        $this->loans->sweep();

        Wallet::move($this->character->fresh(), 200, Reason::Mission);
        $this->loans->sweep();

        $this->assertNull($loan->fresh()->missed_at);
        $this->assertSame(384, $loan->fresh()->principal);
    }

    public function test_a_default_only_lowers_standing(): void
    {
        $loan = $this->take(400);
        $this->broke();
        $this->loans->setStanding($this->character, StandingReason::LoanDefault, -20.0);
        $this->travel(75)->hours();
        $this->loans->sweep();

        $this->assertSame(LoanState::Defaulted, $loan->fresh()->state);
        $this->assertEqualsWithDelta(-20.0, $this->standing(), 0.001);
    }

    public function test_a_default_freezes_withdrawals_but_not_deposits(): void
    {
        $this->take(400);
        (new Bank)->deposit($this->character, 500);
        $loan = Loan::query()->firstOrFail();
        $loan->forceFill(['state' => LoanState::Defaulted])->save();

        $this->assertSame(BankRefusal::Frozen, (new Bank)->withdraw($this->character, 10));
        $this->assertIsInt((new Bank)->deposit($this->character, 10));

        $loan->forceFill(['settlement' => 50])->save();
        $this->assertIsInt((new Bank)->withdraw($this->character, 10), 'a settlement lifts the freeze');
    }

    public function test_the_instalment_by_hand_only_when_one_is_due(): void
    {
        $this->take(400);

        $this->assertSame(LoanRefusal::NotDue, $this->loans->payInstalment($this->character, PaymentSource::Wallet));

        $this->travel(49)->hours();
        $loan = $this->loans->payInstalment($this->character, PaymentSource::Wallet);

        $this->assertInstanceOf(Loan::class, $loan);
        $this->assertSame(384, $loan->principal);
    }

    public function test_paying_off_early_costs_the_fee_and_a_defaulted_loan_back_to_zero_standing(): void
    {
        $loan = $this->take(400);
        $this->loans->nightly();

        $paid = $this->loans->payoff($this->character, PaymentSource::Wallet);

        $this->assertInstanceOf(Loan::class, $paid);
        $this->assertSame(LoanState::Repaid, $paid->state);
        $this->assertSame(1000 + 400 - 400 - 2 - 8, $this->character->fresh()->creds, 'principal 400, interest 2, fee 2% of 400 = 8');
        $this->assertNull($this->loans->open($this->character));

        $second = $this->take(400);
        $this->broke();
        $this->travel(75)->hours();
        $this->loans->sweep();
        $this->assertSame(LoanState::Defaulted, $second->fresh()->state);
        $this->character->forceFill(['creds' => 1000])->save();

        $this->loans->payoff($this->character, PaymentSource::Wallet);
        $this->assertEqualsWithDelta(0.0, $this->standing(), 0.001);
    }

    public function test_held_balances_earn_standing_overnight(): void
    {
        (new Bank)->deposit($this->character, 1000);

        $this->loans->nightly();
        $first = $this->standing();
        $this->loans->nightly();

        $this->assertEqualsWithDelta(0.02, $first, 0.0001, '1 000 creds at 0.02 per 1 000');
        $this->assertEqualsWithDelta(0.04, $this->standing(), 0.0001);
    }

    public function test_the_nightly_pass_puts_a_drifted_default_back_to_minus_five(): void
    {
        $loan = $this->take(400);
        $loan->forceFill(['state' => LoanState::Defaulted])->save();
        $this->loans->setStanding($this->character, StandingReason::LoanDefault, -3.0);

        $this->loans->nightly();

        $this->assertEqualsWithDelta(-5.0, $this->standing(), 0.001);
    }

    private function defaulted(int $amount = 400): Loan
    {
        $loan = $this->take($amount);
        $this->broke();
        $this->travel(75)->hours();
        $this->loans->sweep();
        $this->assertSame(LoanState::Defaulted, $loan->fresh()->state);
        $this->character->forceFill(['creds' => 1000])->save();

        return $loan->fresh();
    }

    public function test_every_wallet_reason_is_classified_for_the_settlement(): void
    {
        foreach (Reason::cases() as $reason) {
            $this->assertIsBool($reason->settlesLoans(), $reason->value);
        }

        $this->assertFalse(Reason::BankWithdrawal->settlesLoans());
        $this->assertFalse(Reason::LoanPayout->settlesLoans());
        $this->assertTrue(Reason::Mission->settlesLoans());
    }

    public function test_a_settlement_takes_its_share_of_what_comes_in_and_never_more_than_owed(): void
    {
        $loan = $this->defaulted();
        $this->loans->settle($this->character);
        $before = $this->character->fresh();
        $creds = $before->creds;

        Wallet::move($before, 100, Reason::Mission);

        $this->assertSame($creds + 50, $before->fresh()->creds, 'half of 100 went to the loan');
        $this->assertSame(50, (int) LoanPayment::query()->where('loan_id', $loan->id)->sum('principal'));

        Wallet::move($before->fresh(), 100, Reason::BankWithdrawal);
        $this->assertSame($creds + 150, $before->fresh()->creds, 'a withdrawal is not income');

        Wallet::move($before->fresh(), 5000, Reason::Mission);
        $this->assertNull($this->loans->open($this->character), 'it stops at what is owed, and the loan is cleared');
        $this->assertEqualsWithDelta(0.0, $this->standing(), 0.001);
    }

    public function test_the_call_waits_while_something_else_has_the_screen_and_then_takes_it(): void
    {
        $this->defaulted();
        $user = $this->character->user;

        // Down on the floor: another lock owns the screen, and the call waits behind it.
        $this->character->forceFill(['waiting_until' => now()->addMinutes(5), 'waiting_cause' => ['kind' => 'test']])->save();
        $this->assertNotSame(route('bank.call'), $this->actingAs($user)->get(route('wallet.index'))->headers->get('Location'));

        $this->character->forceFill(['waiting_until' => null, 'waiting_cause' => null])->save();
        $user = $this->character->fresh()->user;
        $this->actingAs($user)->get(route('wallet.index'))->assertRedirect(route('bank.call'));
    }

    public function test_the_three_answers(): void
    {
        $loan = $this->defaulted();
        $user = $this->character->user;

        $this->actingAs($user)->get(route('bank.call'))->assertOk();

        $this->actingAs($user)->post(route('bank.call.answer', 'refuse'))->assertRedirect(route('city.index'));
        $loan->refresh();
        $this->assertNotNull($loan->refused_at);
        $this->assertSame((int) ceil($loan->owed() * 0.10), $this->character->fresh()->bounty);
        $this->actingAs($user)->get(route('wallet.index'))->assertOk();

        $other = $this->defaultedFor();
        $this->actingAs($this->character->user)->post(route('bank.call.answer', 'settle'))->assertRedirect(route('city.index'));
        $this->assertSame(50, Loan::query()->where('character_id', $other->character_id)->value('settlement'));
    }

    private function defaultedFor(): Loan
    {
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => 1000]));

        return $this->defaulted();
    }

    /** A refused default with the die pinned, so the night's roll is exactly what the test says. */
    private function refused(int $amount = 400): Loan
    {
        $loan = $this->defaulted($amount);
        $this->loans->refuse($this->character);

        return $loan->fresh();
    }

    private function home(int $rent): Safehouse
    {
        $home = Safehouse::query()->create([
            'slug' => 'test-flat-'.$rent, 'title' => 'Test flat', 'district_id' => $this->character->district_id, 'rent' => $rent,
            'security' => 1, 'stealth' => 1, 'rest_rate' => 1, 'meal_fullness' => 1, 'meal_fatigue' => 1, 'storage_slots' => 10,
        ]);
        $this->character->forceFill(['safehouse_id' => $home->id])->save();

        return $home;
    }

    private function stored(Safehouse $home, int $baseCost, int $quantity): InventoryItem
    {
        $item = Item::factory()->physical()->create(['base_cost' => $baseCost, 'stackable' => true]);

        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => $quantity, 'safehouse_id' => $home->id]);
    }

    public function test_the_seizure_roll_starts_at_ten_percent_and_grows_five_a_night(): void
    {
        $loan = $this->refused();
        $rolls = [10, 99, 15];
        $this->loans = new Loans(function () use (&$rolls): int {
            return array_shift($rolls);
        });
        (new Bank)->deposit($this->character, 5);

        $this->loans->nightly();
        $this->assertSame(0, (new Bank)->balance($this->character), 'a 10 hits on a 10% night');

        $loan->refresh();
        $this->assertSame(1, $loan->seizure_nights);
        $this->loans->nightly();
        $this->assertSame(2, $loan->fresh()->seizure_nights, 'a 99 misses');
    }

    public function test_no_seizure_roll_before_a_refusal_or_during_a_settlement(): void
    {
        $this->defaulted();
        $this->loans = new Loans(fn (): int => 1);
        (new Bank)->deposit($this->character, 5);

        $this->loans->nightly();
        $this->assertSame(5, (new Bank)->balance($this->character), 'unanswered');

        $this->loans->settle($this->character);
        $this->loans->nightly();
        $this->assertSame(5, (new Bank)->balance($this->character), 'settling ends the roll');
    }

    public function test_a_seizure_takes_the_account_first(): void
    {
        $loan = $this->refused();
        (new Bank)->deposit($this->character, 150);

        $this->loans->seize($this->character->fresh(), $loan->fresh());

        $this->assertSame(0, (new Bank)->balance($this->character));
        $this->assertSame(250, $loan->fresh()->principal);
        $this->artisan('bank:verify')->assertSuccessful();
    }

    public function test_the_safehouse_covers_the_debt_and_the_surplus_goes_to_the_account(): void
    {
        $loan = $this->refused();
        $home = $this->home(100);
        $row = $this->stored($home, 100, 3);

        $this->loans->seize($this->character->fresh(), $loan->fresh());

        $this->assertSame(LoanState::Repaid, $loan->fresh()->state);
        $this->assertNull($this->character->fresh()->safehouse_id, 'back in the box');
        $this->assertSame(800 - 400, (new Bank)->balance($this->character), '8 days of 100 rent, less the debt');
        $this->assertNotNull($row->fresh()->storage_expires_at, 'what was left is put in storage, not taken');
        $this->assertSame(3, $row->fresh()->quantity);
        $this->assertEqualsWithDelta(0.0, $this->standing(), 0.001);
    }

    public function test_stored_items_count_at_seventy_five_percent_and_only_as_many_as_needed(): void
    {
        $loan = $this->refused();
        $home = $this->home(20);
        $row = $this->stored($home, 100, 5);

        $this->loans->seize($this->character->fresh(), $loan->fresh());

        $this->assertSame(160, 8 * 20);
        $this->assertSame(1, $row->fresh()->quantity, '240 left needs 4 units at 75 each');
        $this->assertSame(LoanState::Repaid, $loan->fresh()->state);
        $this->assertSame(60, (new Bank)->balance($this->character), '4 x 75 = 300, less the 240 needed');
    }

    public function test_the_box_itself_is_exempt_but_what_is_stored_in_it_is_not(): void
    {
        $loan = $this->refused();
        $box = Safehouse::fallback();
        $row = $this->stored($box, 100, 2);

        $this->loans->seize($this->character->fresh(), $loan->fresh());

        $this->assertNull($this->character->fresh()->safehouse_id);
        $this->assertNull($row->fresh(), '2 x 75 = 150 of the 400, the whole row');
        $this->assertSame(250, $loan->fresh()->principal);
    }

    public function test_the_branch_page_takes_a_loan_and_pays_the_instalment_by_hand(): void
    {
        $place = Location::query()->where('slug', 'kronos-capital')->firstOrFail();
        $this->character->forceFill(['district_id' => $place->district_id])->save();
        $user = $this->character->user;

        $this->actingAs($user)->get(route('city.place', 'kronos-capital'))->assertOk();
        $this->actingAs($user)->post(route('city.loan', ['kronos-capital', 'take']), ['amount' => 300])->assertSessionHas('status');
        $this->assertSame(1300, $this->character->fresh()->creds);

        $this->actingAs($user)->post(route('city.loan', ['kronos-capital', 'pay']), ['source' => 'wallet'])->assertSessionHas('error');
        $this->actingAs($user)->get(route('city.place', 'kronos-capital'))->assertOk();
        $this->actingAs($user)->post(route('city.loan', ['kronos-capital', 'payoff']), ['source' => 'wallet'])->assertSessionHas('status');
        $this->assertNull($this->loans->open($this->character));
    }
}
