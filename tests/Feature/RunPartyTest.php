<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cyberspace\Extractor;
use App\Cyberspace\Party\Parties;
use App\Cyberspace\Party\PartyRefusal;
use App\Cyberspace\Party\ShareMode;
use App\Models\Character;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\System;
use App\Models\SystemContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RunPartyTest extends TestCase
{
    use RefreshDatabase;

    public function test_share_modes_divide_the_whole(): void
    {
        $this->assertEqualsWithDelta(0.25, ShareMode::Even->ownerShare(4), 1e-9);
        $this->assertEqualsWithDelta(0.5, ShareMode::Half->ownerShare(4), 1e-9);
        $this->assertEqualsWithDelta(1 / 6, ShareMode::Half->memberShare(4), 1e-9);
        $this->assertSame(1.0, ShareMode::Full->ownerShare(3));
        $this->assertSame(0.0, ShareMode::Full->memberShare(3));
        $this->assertSame(1.0, ShareMode::Even->ownerShare(1));
    }

    public function test_nobody_opens_a_party_without_slots(): void
    {
        $owner = Character::factory()->create();

        $this->assertSame(PartyRefusal::NoSlots, (new Parties)->open($owner));
        $this->assertSame(0, RunParty::query()->count());
    }

    public function test_invitations_stop_at_the_owners_slots_and_one_party_at_a_time(): void
    {
        config(['cyberspace.coop.slots' => 1]);
        $owner = Character::factory()->create();
        $a = Character::factory()->create();
        $b = Character::factory()->create();
        $parties = new Parties;

        // Not in cyberspace: refused whatever the slots say.
        $this->assertSame(PartyRefusal::NotJackedIn, $parties->open($owner));
        $this->assertSame(PartyRefusal::NoParty, $parties->invite($owner, $a->handle));

        $party = RunParty::query()->create(['owner_character_id' => $owner->id, 'address' => 'ABCDEF12', 'x' => 1, 'y' => 1]);

        $this->assertNull($parties->invite($owner, $a->handle));
        $this->assertSame(PartyRefusal::Full, $parties->invite($owner, $b->handle));
        $this->assertSame(PartyRefusal::Yourself, $parties->invite($owner, $owner->handle));
        $this->assertSame(PartyRefusal::NotAtTheDoor, $parties->accept($a));

        $parties->leave($a);
        $this->assertSame(0, $party->members()->count());
        $this->assertNull($parties->invite($owner, $b->handle));
    }

    public function test_a_partys_creds_are_dealt_by_the_chosen_mode(): void
    {
        $owner = Character::factory()->create(['creds' => 0]);
        $a = Character::factory()->create(['creds' => 0]);
        $b = Character::factory()->create(['creds' => 0]);
        $system = System::factory()->at(3, 3)->create(['address' => 'CAFEBABE']);

        $party = RunParty::query()->create([
            'owner_character_id' => $owner->id, 'address' => 'CAFEBABE', 'x' => 3, 'y' => 3,
            'share_credits' => 'half', 'share_extraction' => 'even',
        ]);

        foreach ([$owner, $a, $b] as $who) {
            $run = Run::query()->create([
                'character_id' => $who->id, 'run_party_id' => $party->id, 'address' => 'CAFEBABE', 'x' => 3, 'y' => 3,
                'purpose' => 'extraction', 'turn' => 0, 'started_at' => now(),
            ]);
            $who->forceFill(['current_run_id' => $run->id])->save();
        }

        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 101]);

        // The owner takes half (51 with the odd one), the others share the rest.
        (new Extractor)->takeCredsAndItems($a->fresh(), 'CAFEBABE');

        $this->assertSame(51, (int) $owner->fresh()->creds);
        $this->assertSame(25, (int) $a->fresh()->creds);
        $this->assertSame(25, (int) $b->fresh()->creds);
    }
}
