<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Notification;
use App\Models\WalletEntry;
use App\Wallet\Reason;
use App\Wallet\TransferRefusal;
use App\Wallet\Transfers;
use App\Wallet\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WalletTest extends TestCase
{
    use RefreshDatabase;

    private function character(int $creds): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3, 'creds' => $creds],
        ));
    }

    public function test_a_movement_changes_the_balance_and_is_logged(): void
    {
        $c = $this->character(100);

        Wallet::move($c, -30, Reason::Market, 'Thing × 1');

        $entry = WalletEntry::query()->sole();
        $this->assertSame(70, $c->fresh()->creds);
        $this->assertSame([-30, 70, Reason::Market], [$entry->amount, $entry->balance, $entry->reason]);
    }

    public function test_a_transfer_moves_cash_logs_both_sides_and_tells_the_recipient(): void
    {
        $a = $this->character(100);
        $b = $this->character(5);

        $result = (new Transfers)->send($a, $b->handle, 40);

        $this->assertInstanceOf(Character::class, $result);
        $this->assertSame(60, $a->fresh()->creds);
        $this->assertSame(45, $b->fresh()->creds);
        $this->assertSame(1, WalletEntry::query()->where('character_id', $a->id)->where('amount', -40)->count());
        $this->assertSame(1, WalletEntry::query()->where('character_id', $b->id)->where('amount', 40)->count());
        $this->assertSame(1, Notification::query()->where('user_id', $b->user_id)->count());
    }

    public function test_a_transfer_is_refused_for_the_usual_reasons(): void
    {
        $a = $this->character(10);
        $b = $this->character(0);
        $t = new Transfers;

        $this->assertSame(TransferRefusal::CannotAfford, $t->send($a, $b->handle, 11));
        $this->assertSame(TransferRefusal::Yourself, $t->send($a, $a->handle, 1));
        $this->assertSame(TransferRefusal::NoSuchCharacter, $t->send($a, 'nobody-here', 1));
        $this->assertSame(TransferRefusal::Amount, $t->send($a, $b->handle, 0));
        $this->assertSame(0, WalletEntry::query()->count());
    }

    public function test_the_screens_render(): void
    {
        $a = $this->character(10);
        Wallet::move($a, 5, Reason::Siphon);

        $this->actingAs($a->user)->get(route('wallet.index'))->assertOk()->assertSeeText('Siphon payout');
        $this->actingAs($a->user)->get(route('character.index'))->assertOk()->assertSeeText('Bounty');
    }
}
