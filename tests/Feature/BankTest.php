<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bank\Bank;
use App\Bank\BankRefusal;
use App\Cyberspace\SystemType;
use App\Models\BankAccount;
use App\Models\Character;
use App\Models\District;
use App\Models\Entity;
use App\Models\Exchange;
use App\Models\Location;
use App\Models\System;
use App\Models\WalletEntry;
use App\Wallet\Reason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/** Kronos Capital's accounts (docs/BANK.md, phase 1): the ledger, the City branch and the Exchange counter. */
final class BankTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => 1000]));
    }

    public function test_a_deposit_and_a_withdrawal_move_cash_and_write_both_ledgers(): void
    {
        $bank = new Bank;

        $this->assertSame(600, $bank->deposit($this->character, 600));
        $this->assertSame(400, $this->character->fresh()->creds);
        $this->assertSame(250, $bank->withdraw($this->character, 350));
        $this->assertSame(750, $this->character->fresh()->creds);
        $this->assertSame(250, $bank->balance($this->character));

        $this->assertSame([-600, 350], WalletEntry::query()->whereIn('reason', [Reason::BankDeposit, Reason::BankWithdrawal])->orderBy('id')->pluck('amount')->all());
        $this->assertSame([600, 250], $bank->statement($this->character)->reverse()->pluck('balance')->all());
        $this->artisan('bank:verify')->assertSuccessful();
    }

    public function test_it_refuses_what_it_cannot_do_and_changes_nothing(): void
    {
        $bank = new Bank;

        $this->assertSame(BankRefusal::Amount, $bank->deposit($this->character, 0));
        $this->assertSame(BankRefusal::NoCash, $bank->deposit($this->character, 1001));
        $this->assertSame(BankRefusal::NoFunds, $bank->withdraw($this->character, 1));

        $this->assertSame(1000, $this->character->fresh()->creds);
        $this->assertSame(0, $bank->balance($this->character));
    }

    public function test_verify_notices_an_account_that_drifted(): void
    {
        (new Bank)->deposit($this->character, 100);
        BankAccount::query()->update(['balance' => 999]);

        $this->artisan('bank:verify')->assertFailed();
    }

    public function test_the_branch_is_a_city_feature_and_the_counter_works_there(): void
    {
        $place = Location::query()->where('slug', 'kronos-capital')->firstOrFail();
        $this->character->forceFill(['district_id' => $place->district_id])->save();
        $user = $this->character->user;

        $this->assertContains('bank', array_map(fn ($f) => $f->value, $place->features()));

        $this->actingAs($user)->get(route('city.place', 'kronos-capital'))->assertOk();
        $this->actingAs($user)->post(route('city.bank', ['kronos-capital', 'deposit']), ['amount' => 300])->assertSessionHas('status');
        $this->assertSame(300, (new Bank)->balance($this->character));

        $this->actingAs($user)->post(route('city.bank', ['kronos-capital', 'withdraw']), ['amount' => 900])->assertSessionHas('error');
        $this->assertSame(300, (new Bank)->balance($this->character));
    }

    public function test_the_counter_is_refused_from_another_district(): void
    {
        $place = Location::query()->where('slug', 'kronos-capital')->firstOrFail();
        $other = District::query()->where('id', '!=', $place->district_id)->firstOrFail();
        $this->character->forceFill(['district_id' => $other->id])->save();

        $this->actingAs($this->character->user)->post(route('city.bank', ['kronos-capital', 'deposit']), ['amount' => 5])->assertSessionHas('error');
        $this->assertSame(0, (new Bank)->balance($this->character));
    }

    public function test_the_exchange_desk_of_kronos_is_a_counter_only_for_somebody_standing_on_it(): void
    {
        $entity = Entity::query()->where('name', config('bank.entity'))->firstOrFail();
        $system = System::factory()->at(8, 8)->ownedBy($entity)->create(['type' => SystemType::Exchange]);
        Exchange::query()->create(['system_id' => $system->id, 'grade' => 1, 'agent' => 'Wren Achebe', 'min_standing' => 0]);
        $user = $this->character->user;

        $this->actingAs($user)->post(route('exchange.bank', 'deposit'), ['amount' => 5])->assertRedirect(route('cyberspace.index'));
        $this->assertSame(0, (new Bank)->balance($this->character));

        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();

        $this->actingAs($user)->get(route('exchange.show'))->assertOk()->assertViewIs('exchange.bank');
        $this->actingAs($user)->post(route('exchange.bank', 'deposit'), ['amount' => 200])->assertSessionHas('status');
        $this->assertSame(200, (new Bank)->balance($this->character));
    }
}
