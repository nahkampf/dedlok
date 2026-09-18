<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\SiphonPayout;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * A little from every machine somebody is draining, once a night.
 *
 * The safe half of a backdoor portfolio: it pays whether or not its holder
 * goes anywhere, and nothing takes it but the ageing roll.
 */
final class SiphonTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3, 'creds' => 0],
        ));
    }

    // ---- what a machine is worth draining ----------------------------------

    /** Thirty a grade: (3000 x grade) / 100. */
    public function test_the_base_is_thirty_a_grade(): void
    {
        $siphons = SiphonPayout::fromConfig();

        $this->assertSame(30, $siphons->baseFor(1));
        $this->assertSame(150, $siphons->baseFor(5));
        $this->assertSame(270, $siphons->baseFor(9));
    }

    public function test_a_night_pays_every_siphon_held(): void
    {
        $this->hold($this->machine(1, 9));
        $this->hold($this->machine(2, 4));

        $result = SiphonPayout::fromConfig()->run();

        $this->assertSame(270 + 120, $result['creds']);
        $this->assertSame(270 + 120, $this->character->fresh()->creds);
        $this->assertSame(2, $result['backdoors']);
        $this->assertSame(1, $result['characters']);
    }

    /** A routing hop is the other kind and pays nothing. */
    public function test_routing_backdoors_pay_nothing(): void
    {
        $this->hold($this->machine(1, 9), BackdoorKind::Routing);

        $this->assertSame(0, SiphonPayout::fromConfig()->run()['creds']);
        $this->assertSame(0, $this->character->fresh()->creds);
    }

    /** Everybody who holds one is paid, and out of their own machines. */
    public function test_each_holder_is_paid_for_their_own(): void
    {
        $stranger = Character::factory()->create(['archetype' => 3, 'creds' => 0]);

        $this->hold($this->machine(1, 9));
        (new Backdoors)->install($stranger, $this->machine(2, 2), BackdoorKind::Siphon);

        SiphonPayout::fromConfig()->run();

        $this->assertSame(270, $this->character->fresh()->creds);
        $this->assertSame(60, $stranger->fresh()->creds);
    }

    /** Two nights pay twice: there is no stored clock and nothing accrues. */
    public function test_it_pays_again_the_next_night(): void
    {
        $this->hold($this->machine(1, 5));

        SiphonPayout::fromConfig()->run();
        SiphonPayout::fromConfig()->run();

        $this->assertSame(300, $this->character->fresh()->creds);
    }

    // ---- and what its holder does to it -------------------------------------

    /**
     * THE BASE IS THE MACHINE'S AND THE MODIFIERS ARE THE PERSON'S. There is
     * no skill for this yet; this is the id the first one will target, and the
     * proof that it will need no code here.
     */
    public function test_a_modifier_raises_every_siphon_at_once(): void
    {
        $this->hold($this->machine(1, 9));
        $this->hold($this->machine(2, 1));

        Config::set('effects.deep_drain', [
            'label' => 'Deep drain',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [[
                'op' => 'percentage_of_base',
                'amount' => 50,
                'target' => ['id' => 'siphon_yield'],
            ]],
        ]);

        CharacterModifier::query()->create([
            'character_id' => $this->character->id,
            'definition_key' => 'deep_drain',
        ]);

        SiphonPayout::fromConfig()->run();

        // 270 and 30, each half again as much.
        $this->assertSame(405 + 45, $this->character->fresh()->creds);
    }

    // ---- the command ---------------------------------------------------------

    public function test_the_command_pays_and_reports(): void
    {
        $this->hold($this->machine(1, 3));

        $this->artisan('cyberspace:siphons --dry-run')
            ->expectsOutputToContain('90 creds before modifiers')
            ->assertSuccessful();

        $this->assertSame(0, $this->character->fresh()->creds, 'a dry run wrote nothing');

        $this->artisan('cyberspace:siphons')
            ->expectsOutputToContain('paid 90 creds')
            ->assertSuccessful();

        $this->assertSame(90, $this->character->fresh()->creds);
    }

    public function test_the_command_says_when_nobody_is_draining(): void
    {
        $this->artisan('cyberspace:siphons --dry-run')
            ->expectsOutputToContain('Nobody is draining anything')
            ->assertSuccessful();
    }

    // ---- fixtures ------------------------------------------------------------

    private function machine(int $n, int $grade): System
    {
        return System::factory()->at($n, 1)->grade($grade)->create(['address' => 'SIPHON0'.$n]);
    }

    private function hold(System $system, BackdoorKind $kind = BackdoorKind::Siphon): void
    {
        (new Backdoors)->install($this->character->fresh(), $system, $kind);
    }
}
