<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Beta\AlphaGift;
use App\Models\BetaKey;
use App\Models\Character;
use App\Models\WalletEntry;
use App\Wallet\Reason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AlphaGiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tester_is_paid_once_and_nobody_else_is(): void
    {
        $tester = Character::factory()->create(['creds' => 100]);
        $other = Character::factory()->create(['creds' => 100]);
        BetaKey::query()->create(['code' => 'AAAA-BBBB-CCCC', 'user_id' => $tester->user_id, 'used_at' => now()]);

        $this->assertTrue($tester->user->isTester());
        $this->assertFalse($other->user->isTester());

        $this->assertSame(1, app(AlphaGift::class)->grantAll());
        $this->assertSame(0, app(AlphaGift::class)->grantAll(), 'a second run pays nobody');

        $this->assertSame(5100, $tester->fresh()->creds);
        $this->assertSame(100, $other->fresh()->creds);
        $this->assertSame(1, WalletEntry::query()->where('reason', Reason::AlphaGift->value)->count());
        $this->assertSame('A gift for alpha testing dedlok!', Reason::AlphaGift->label());
    }
}
