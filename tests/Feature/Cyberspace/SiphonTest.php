<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\SiphonPayout;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\Notification;
use App\Models\System;
use App\Notifications\Category;
use App\Support\Text;
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

    /** The base is a table by grade, not a rate. */
    public function test_the_base_is_a_table_by_grade(): void
    {
        $siphons = SiphonPayout::fromConfig();

        $this->assertSame(45, $siphons->baseFor(1));
        $this->assertSame(160, $siphons->baseFor(5));
        $this->assertSame(290, $siphons->baseFor(9));
    }

    public function test_a_night_pays_every_siphon_held(): void
    {
        $this->hold($this->machine(1, 9));
        $this->hold($this->machine(2, 4));

        $result = SiphonPayout::fromConfig()->run();

        $this->assertSame(290 + 125, $result['creds']);
        $this->assertSame(290 + 125, $this->character->fresh()->creds);
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

        $this->assertSame(290, $this->character->fresh()->creds);
        $this->assertSame(65, $stranger->fresh()->creds);
    }

    /** Two nights pay twice: there is no stored clock and nothing accrues. */
    public function test_it_pays_again_the_next_night(): void
    {
        $this->hold($this->machine(1, 5));

        SiphonPayout::fromConfig()->run();
        SiphonPayout::fromConfig()->run();

        $this->assertSame(320, $this->character->fresh()->creds);
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

        // 290 and 45, each half again as much (floored).
        $this->assertSame(435 + 67, $this->character->fresh()->creds);
    }

    // ---- the command ---------------------------------------------------------

    public function test_the_command_pays_and_reports(): void
    {
        $this->hold($this->machine(1, 3));

        $this->artisan('cyberspace:siphons --dry-run')
            ->expectsOutputToContain('95 creds before modifiers')
            ->assertSuccessful();

        $this->assertSame(0, $this->character->fresh()->creds, 'a dry run wrote nothing');

        $this->artisan('cyberspace:siphons')
            ->expectsOutputToContain('paid 95 creds')
            ->assertSuccessful();

        $this->assertSame(95, $this->character->fresh()->creds);
    }

    public function test_the_command_says_when_nobody_is_draining(): void
    {
        $this->artisan('cyberspace:siphons --dry-run')
            ->expectsOutputToContain('Nobody is draining anything')
            ->assertSuccessful();
    }

    // ---- fixtures ------------------------------------------------------------

    // ---- the night's report ------------------------------------------------

    /**
     * ONE REPORT PER CHARACTER, NOT A MESSAGE PER SIPHON: the total in the
     * sentence, and a line per system under it, as true that night.
     */
    public function test_a_night_is_one_report_listing_every_system(): void
    {
        $nine = $this->machine(1, 9);
        $four = $this->machine(2, 4);
        $this->hold($nine);
        $this->hold($four);

        SiphonPayout::fromConfig()->run();

        $report = Notification::query()->sole();

        $this->assertSame($this->character->user_id, $report->user_id);
        $this->assertSame(Category::Cyberspace, $report->category);
        $this->assertSame(Text::get('notifications.messages.siphons_ran', ['creds' => '415']), $report->body);
        $this->assertSame([
            ['system' => $nine->displayName(), 'address' => 'SIPHON01', 'grade' => 9, 'creds' => 290],
            ['system' => $four->displayName(), 'address' => 'SIPHON02', 'grade' => 4, 'creds' => 125],
        ], $report->details);
    }

    /** Each holder gets their own report, of their own systems. */
    public function test_every_holder_gets_their_own_report(): void
    {
        $stranger = Character::factory()->create(['archetype' => 3, 'creds' => 0]);

        $this->hold($this->machine(1, 9));
        (new Backdoors)->install($stranger, $this->machine(2, 4), BackdoorKind::Siphon);

        SiphonPayout::fromConfig()->run();

        $this->assertSame(2, Notification::query()->count());
        $this->assertSame(125, Notification::query()->where('user_id', $stranger->user_id)->sole()->details[0]['creds']);
    }

    /** Nobody draining anything hears nothing. */
    public function test_a_routing_hop_alone_sends_no_report(): void
    {
        $this->hold($this->machine(1, 9), BackdoorKind::Routing);

        SiphonPayout::fromConfig()->run();

        $this->assertSame(0, Notification::query()->count());
    }

    /** The inbox draws the list under the sentence, each address a copy button. */
    public function test_the_inbox_lists_each_system_with_a_copyable_address(): void
    {
        $this->hold($this->machine(1, 9));
        SiphonPayout::fromConfig()->run();

        $this->actingAs($this->character->user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSeeText(Text::get('notifications.messages.siphons_ran', ['creds' => '290']))
            ->assertSee('data-copy="SIPHON01"', false)
            ->assertSeeText(Text::get('notifications.details.grade', ['grade' => 9]))
            ->assertSeeText('290 ¤');
    }

    private function machine(int $n, int $grade): System
    {
        return System::factory()->at($n, 1)->grade($grade)->create(['address' => 'SIPHON0'.$n]);
    }

    private function hold(System $system, BackdoorKind $kind = BackdoorKind::Siphon): void
    {
        (new Backdoors)->install($this->character->fresh(), $system, $kind);
    }
}
