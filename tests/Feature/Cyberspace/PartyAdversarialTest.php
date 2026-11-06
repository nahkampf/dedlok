<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Chat\Chats;
use App\Cyberspace\Navigator;
use App\Cyberspace\Party\Parties;
use App\Cyberspace\Party\PartyRefusal;
use App\Cyberspace\Party\ShareMode;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Notification;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\RunPartyMember;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * ADVERSARIAL SIMULATIONS of co-op runs: every test states what SHOULD hold and a failure is a finding.
 */
final class PartyAdversarialTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.coop.slots', 3);
        System::factory()->at(8, 8)->grade(1)->withTracer(2, 4)->create(['address' => 'DEADBEEF']);
    }

    private function runner(int $x = 8, int $y = 8): Character
    {
        $c = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($c);
        $c->forceFill(['grid_x' => $x, 'grid_y' => $y])->save();

        return $c->fresh();
    }

    /** @return array{0: Character, 1: list<Character>} */
    private function launched(int $members = 2): array
    {
        $owner = $this->runner();
        $parties = new Parties;
        $this->assertInstanceOf(RunParty::class, $parties->open($owner));
        $mates = [];

        for ($i = 0; $i < $members; $i++) {
            $m = $this->runner();
            $this->assertNull($parties->invite($owner, $m->handle));
            $this->assertNull($parties->accept($m));
            $mates[] = $m;
        }

        $result = $parties->launch($owner, RunPurpose::Extraction, null, [], app(RunStarter::class));
        $this->assertTrue($result->succeeded(), 'owner start refused');

        return [$owner->fresh(), array_map(fn (Character $m): Character => $m->fresh(), $mates)];
    }

    private function act(Character $c, string $action = 'hold'): void
    {
        $this->actingAs($c->fresh()->user)->post(route('run.act'), ['action' => $action]);
    }

    // 1 ---------------------------------------------------------------------------------------------------------------
    public function test_nobody_can_be_invited_to_a_party_that_has_already_started(): void
    {
        [$owner] = $this->launched(1);
        $late = $this->runner();

        // The owner is in a run, but chat is allowed through the lock: a pasted run:<id> in a direct line invites.
        $party = RunParty::query()->sole();
        (new Chats)->post($owner, (new Chats)->direct($owner, $late), "run:{$party->id}");

        $this->assertSame(0, RunPartyMember::query()->where('character_id', $late->id)->count(), 'invited to a run already begun');
    }

    // 2 ---------------------------------------------------------------------------------------------------------------
    public function test_a_late_accept_cannot_strand_a_member_in_a_party_that_never_started_them(): void
    {
        [$owner] = $this->launched(1);
        $late = $this->runner();
        $party = RunParty::query()->sole();
        $party->members()->create(['character_id' => $late->id]);

        $refusal = (new Parties)->accept($late);

        $this->assertSame(PartyRefusal::Started, $refusal);
    }

    // 3 ---------------------------------------------------------------------------------------------------------------
    public function test_the_owner_cannot_walk_away_from_the_door_and_launch(): void
    {
        Config::set('cyberspace.size', 16);
        System::factory()->at(4, 4)->grade(1)->withTracer(2, 4)->create(['address' => 'FEEDF00D']);
        $owner = $this->runner();
        $parties = new Parties;
        $parties->open($owner);
        $friend = $this->runner();
        $parties->invite($owner, $friend->handle);
        $parties->accept($friend);

        // The owner jumps somewhere else and starts the "party" run from there.
        $owner->forceFill(['grid_x' => 4, 'grid_y' => 4])->save();
        $result = $parties->launch($owner->fresh(), RunPurpose::Extraction, null, [], app(RunStarter::class));

        $runs = Run::query()->get();
        $addresses = $runs->pluck('address')->unique()->values()->all();
        $this->assertLessThanOrEqual(1, count($addresses), 'party runs split over '.implode(',', $addresses).' ('.($result->succeeded() ? 'started' : 'refused').')');
    }

    // 4 ---------------------------------------------------------------------------------------------------------------
    public function test_disbanding_after_leaving_does_not_strand_the_runners_still_inside(): void
    {
        [$owner, [$a, $b]] = $this->launched(2);

        // The owner's own run ends (egress) and, being out, they press Disband.
        app(RunStarter::class)->end($owner, $owner->currentRun, RunEnding::Egress);
        $this->actingAs($owner->fresh()->user)->post(route('run.party.leave'));

        // Two runners are still in. Both choose; the round must resolve.
        $this->act($a);
        $this->act($b);

        $turns = Run::query()->whereIn('character_id', [$a->id, $b->id])->pluck('turn')->all();
        $this->assertSame([1, 1], $turns, 'the round never resolved: '.json_encode(Run::query()->whereIn('character_id', [$a->id, $b->id])->pluck('pending_action')->all()));
    }

    // 5 ---------------------------------------------------------------------------------------------------------------
    public function test_somebody_merely_invited_can_still_run_something_of_their_own_elsewhere(): void
    {
        System::factory()->at(2, 2)->grade(1)->withTracer(2, 4)->create(['address' => 'ABCDEF12']);
        $owner = $this->runner();
        $parties = new Parties;
        $parties->open($owner);
        $victim = $this->runner(2, 2);
        $parties->invite($owner, $victim->handle);

        $this->actingAs($victim->fresh()->user)->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value]);

        $this->assertNotNull($victim->fresh()->current_run_id, 'an unanswered invitation to a party elsewhere blocks every run of their own');
    }

    // 6 ---------------------------------------------------------------------------------------------------------------
    public function test_a_party_left_behind_by_an_owner_who_jacked_out_does_not_hold_its_invitees(): void
    {
        $owner = $this->runner();
        $parties = new Parties;
        $parties->open($owner);
        $friend = $this->runner();
        $parties->invite($owner, $friend->handle);

        Navigator::fromConfig(app(Resolver::class))->jackOut($owner->fresh());

        $this->assertNull($parties->partyOf($friend->fresh()), 'the invitee is still held by a party whose owner left cyberspace');
    }

    // 7 ---------------------------------------------------------------------------------------------------------------
    public function test_two_requests_in_flight_cannot_start_two_runs_for_one_character(): void
    {
        $c = $this->runner();
        $a = Character::query()->find($c->id);
        $b = Character::query()->find($c->id);
        $starter = app(RunStarter::class);

        $starter->start($a, RunPurpose::Extraction);
        $starter->start($b, RunPurpose::Extraction);

        $this->assertSame(1, Run::query()->where('character_id', $c->id)->live()->count(), 'live runs for one character');
    }

    // 8 ---------------------------------------------------------------------------------------------------------------
    public function test_an_idle_player_with_nothing_left_to_take_egresses_by_the_clock(): void
    {
        [$owner, [$a]] = $this->launched(1);

        Run::query()->where('character_id', $owner->id)->update(['extracted' => true]);

        // The mate answers at once; the owner is still reading. 31 seconds later the mate's page refreshes.
        $this->act($a);
        RunParty::query()->update(['round_started_at' => now()->subSeconds(31)]);
        $this->actingAs($a->fresh()->user)->get(route('run.show'));

        // Egress is a roll that can fail, so the run may still be live: either way the idle runner's turn was taken.
        $run = Run::query()->where('character_id', $owner->id)->first();
        $this->assertTrue($run->ended_at !== null || $run->turn >= 1, 'a finished runner who idles should have egressed');
    }

    // 9 ---------------------------------------------------------------------------------------------------------------
    public function test_the_standalone_egress_route_obeys_the_round(): void
    {
        [$owner, [$a]] = $this->launched(1);
        $turn = Run::query()->where('character_id', $a->id)->value('turn');

        $this->actingAs($a->fresh()->user)->post(route('run.egress'));

        $this->assertSame($turn, (int) Run::query()->where('character_id', $a->id)->value('turn'), 'egress route spent a turn outside the round');
    }

    // 10 --------------------------------------------------------------------------------------------------------------
    public function test_a_second_party_cannot_be_opened_by_a_double_click(): void
    {
        $owner = $this->runner();
        $parties = new Parties;
        $parties->open($owner);
        $this->assertSame(PartyRefusal::AlreadyInParty, $parties->open($owner));
        $this->assertSame(1, RunParty::query()->count());
    }

    // 11 --------------------------------------------------------------------------------------------------------------
    public function test_a_member_jacked_out_mid_run_does_not_freeze_the_others(): void
    {
        [$owner, [$a, $b]] = $this->launched(2);

        // A is ejected by something outside the party (a cancellation, a collapse).
        app(RunStarter::class)->end($a, $a->currentRun, RunEnding::Cancelled);

        $this->act($owner);
        $this->act($b);

        $this->assertSame([1, 1], Run::query()->whereIn('character_id', [$owner->id, $b->id])->orderBy('character_id')->pluck('turn')->all());
    }

    // 12 --------------------------------------------------------------------------------------------------------------
    public function test_the_party_row_is_gone_once_everyone_is_out_and_nobody_is_left_held(): void
    {
        [$owner, [$a]] = $this->launched(1);

        app(RunStarter::class)->end($owner, $owner->currentRun, RunEnding::Egress);
        app(RunStarter::class)->end($a, $a->currentRun, RunEnding::Egress);

        $this->assertSame(0, RunParty::query()->count());
        $this->assertSame(0, RunPartyMember::query()->count());
    }

    // 13 --------------------------------------------------------------------------------------------------------------
    public function test_a_member_dropped_at_launch_is_not_left_in_a_ghost_party(): void
    {
        $owner = $this->runner();
        $parties = new Parties;
        $parties->open($owner);
        $m = $this->runner();
        $parties->invite($owner, $m->handle);
        $parties->accept($m);
        // The member's deck dies between accepting and the launch.
        $m->forceFill(['grid_x' => 3, 'grid_y' => 3])->save();

        $parties->launch($owner->fresh(), RunPurpose::Extraction, null, [], app(RunStarter::class));

        $seat = RunPartyMember::query()->where('character_id', $m->id)->first();
        $this->assertNotNull($seat?->dropped_at, 'the member stays, marked, so they cannot start a run of their own beside the party');
        $this->assertNull($m->fresh()->current_run_id);

        $parties->leave($m);
        $this->assertNotNull(RunPartyMember::query()->where('character_id', $m->id)->first(), 'leaving does not free a dropped seat');

        $handle = $m->handle;
        $this->assertSame(1, Notification::query()->where('user_id', $owner->user_id)->where('title', 'Co-op run member dropped')->where('body', 'like', "%{$handle}%")->count(), 'the owner is told who dropped');

        app(RunStarter::class)->end($owner, $owner->fresh()->currentRun, RunEnding::Egress);
        $this->assertNull(RunPartyMember::query()->where('character_id', $m->id)->first(), 'the seat goes with the party');
    }

    public function test_the_screen_and_the_launch_agree_on_who_can_go(): void
    {
        $owner = $this->runner();
        $parties = new Parties;
        $party = $parties->open($owner);
        $m = $this->runner();
        $parties->invite($owner, $m->handle);
        $parties->accept($m);

        $this->assertNull($parties->obstacle($m->fresh(), $party, app(RunStarter::class)));

        $m->forceFill(['grid_x' => 3, 'grid_y' => 3])->save();
        $this->assertNotNull($parties->obstacle($m->fresh(), $party, app(RunStarter::class)), 'away from the door');
    }

    // 14 --------------------------------------------------------------------------------------------------------------
    public function test_a_party_whose_system_vanished_before_launch_can_be_disbanded_by_members_anywhere(): void
    {
        $owner = $this->runner();
        $parties = new Parties;
        $parties->open($owner);
        $m = $this->runner();
        $parties->invite($owner, $m->handle);
        System::query()->where('address', 'DEADBEEF')->delete();

        $result = $parties->launch($owner->fresh(), RunPurpose::Extraction, null, [], app(RunStarter::class));

        $this->assertFalse($result->succeeded());
        // The party still stands, with its invitee held; the owner can disband it from anywhere that has a system.
        $this->assertNotNull($parties->partyOf($m->fresh()));
    }

    // 15 --------------------------------------------------------------------------------------------------------------
    public function test_the_owner_cannot_start_a_run_while_somebody_else_still_holds_the_party_open(): void
    {
        [$owner, [$a]] = $this->launched(1);

        // The owner egresses and, still standing at the door, starts again on their own while a is inside.
        app(RunStarter::class)->end($owner, $owner->currentRun, RunEnding::Egress);
        $this->actingAs($owner->fresh()->user)->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value]);

        $mine = Run::query()->where('character_id', $owner->id)->live()->first();
        $this->assertTrue($mine === null || $mine->run_party_id === null, 'a second run of the owner was tied to a party already started: '.json_encode($mine?->only(['run_party_id'])));
    }

    // 16 --------------------------------------------------------------------------------------------------------------
    public function test_a_runner_in_the_middle_of_a_download_is_not_egressed_by_the_clock(): void
    {
        [$owner, [$a]] = $this->launched(1);

        Run::query()->where('character_id', $owner->id)->update(['extracted' => true, 'extracting_qb' => 8.0]);
        $this->act($a);
        RunParty::query()->update(['round_started_at' => now()->subSeconds(31)]);
        $this->actingAs($a->fresh()->user)->get(route('run.show'));

        $this->assertNotNull($owner->fresh()->current_run_id, 'a half-finished download was aborted by the default policy');
    }

    // 17 --------------------------------------------------------------------------------------------------------------
    public function test_a_member_loading_the_run_late_still_gets_to_choose_their_first_turn(): void
    {
        [$owner, [$a]] = $this->launched(1);

        // The owner chooses at once; their page then refreshes every five seconds. The member reads the notification a minute later.
        $this->act($owner);
        Run::query()->update(['started_at' => now()->subSeconds(60)]);
        RunParty::query()->update(['started_at' => now()->subSeconds(60)]);
        $this->actingAs($owner->fresh()->user)->get(route('run.show'));
        $this->actingAs($a->fresh()->user)->get(route('run.show'));

        $this->assertSame(0, (int) Run::query()->where('character_id', $a->id)->value('turn'), 'the member lost a turn before ever seeing the screen');
    }

    /** @return array<int, array{string, string}> */
    public static function seeds(): array
    {
        return array_map(fn (int $i): array => [(string) $i], range(1, 20));
    }

    // The terms are what the invitation promised ----------------------------------------------------------------------
    public function test_the_terms_cannot_be_changed_once_somebody_is_invited(): void
    {
        $owner = $this->runner();
        $parties = new Parties;
        $parties->open($owner);

        $this->assertNull($parties->options($owner, ShareMode::Half, ShareMode::Half), 'an empty party may still set its terms');
        $this->assertNull($parties->invite($owner, $this->runner()->handle));

        $this->assertSame(PartyRefusal::TermsLocked, $parties->options($owner, ShareMode::Full, ShareMode::Full));
        $party = RunParty::query()->sole();
        $this->assertSame(ShareMode::Half, $party->share_credits);
        $this->assertSame(ShareMode::Half, $party->share_extraction);
    }

    public function test_everybody_goes_in_sharing_the_partys_detection(): void
    {
        [$owner, $mates] = $this->launched(2);

        $detected = Run::query()->pluck('detected')->map(fn ($d): bool => (bool) $d)->unique();
        $this->assertCount(1, $detected, 'the runs of a party start with different detection');
    }

    public function test_a_flood_can_be_a_co_op_run(): void
    {
        $owner = $this->runner();
        $parties = new Parties;
        $parties->open($owner);
        $mate = $this->runner();
        $parties->invite($owner, $mate->handle);
        $parties->accept($mate);

        $result = $parties->launch($owner, RunPurpose::Denial, null, [], app(RunStarter::class));

        $this->assertTrue($result->succeeded());
        $this->assertSame(2, Run::query()->where('purpose', RunPurpose::Denial->value)->count());
    }

    /**
     * RANDOM OPERATIONS against a party of three, and after each the structural invariants: nobody pointing at nothing,
     * nobody live and unpointed, no run of a party that is gone while two or more are still inside, and the clock
     * always able to move a stuck round on.
     */
    #[DataProvider('seeds')]
    public function test_random_operations_never_break_the_invariants(string $seed): void
    {
        $this->randomOperations($seed);
    }

    #[DataProvider('seeds')]
    public function test_random_operations_against_ice_never_break_the_invariants(string $seed): void
    {
        $this->randomOperations($seed, ice: true);
    }

    private function randomOperations(string $seed, bool $ice = false): void
    {
        if ($ice) {
            System::query()->where('address', 'DEADBEEF')->update(['ice_id' => Ice::query()->orderBy('level')->value('id')]);
        }

        mt_srand((int) $seed);
        [$owner, [$a, $b]] = $this->launched(2);
        $everyone = [$owner, $a, $b];
        $late = $this->runner();
        $actions = ['hold', 'extract', 'attack', 'egress', 'install', 'burn'];
        $trail = [];

        for ($step = 0; $step < 30; $step++) {
            $who = $everyone[mt_rand(0, 2)];
            $op = mt_rand(1, 10);

            switch (true) {
                case $op <= 4:
                    $act = $actions[mt_rand(0, 5)];
                    $trail[] = "act {$who->id} {$act}";
                    $this->act($who, $act);
                    break;
                case $op === 5:
                    $trail[] = 'clock +31s';
                    RunParty::query()->update(['round_started_at' => now()->subSeconds(31)]);
                    $this->actingAs($who->fresh()->user)->get(route('run.show'));
                    break;
                case $op === 6:
                    $trail[] = "show {$who->id}";
                    $this->actingAs($who->fresh()->user)->get(route('run.show'));
                    break;
                case $op === 7:
                    $trail[] = "cancel {$who->id}";
                    $run = Run::query()->where('character_id', $who->id)->live()->first();
                    if ($run !== null) {
                        app(RunStarter::class)->end($who->fresh(), $run, RunEnding::Cancelled);
                    }
                    break;
                case $op === 8:
                    // The owner's Disband is the known orphaning (test 4); here only the others leave.
                    if ($who->id === $owner->id) {
                        break;
                    }
                    $trail[] = "leave {$who->id}";
                    $this->actingAs($who->fresh()->user)->post(route('run.party.leave'));
                    break;
                case $op === 9:
                    $trail[] = 'late invite';
                    $party = RunParty::query()->first();
                    if ($party !== null) {
                        (new Parties)->invite(Character::query()->find($party->owner_character_id), $late->handle);
                    }
                    break;
                default:
                    $trail[] = 'late accept';
                    (new Parties)->accept($late->fresh());
            }

            $this->assertInvariants($trail);
        }
    }

    private function assertInvariants(array $trail): void
    {
        $say = 'after: '.implode(' | ', $trail);

        foreach (Run::query()->live()->get() as $run) {
            $c = Character::query()->find($run->character_id);
            $this->assertSame($run->id, (int) $c->current_run_id, "live run {$run->id} not pointed at by its runner. {$say}");

            if ($run->run_party_id !== null && Run::query()->where('run_party_id', $run->run_party_id)->live()->count() > 1) {
                $this->assertTrue(RunParty::query()->whereKey($run->run_party_id)->exists(), "party {$run->run_party_id} is gone with several runners still inside. {$say}");
            }
        }

        foreach (Character::query()->whereNotNull('current_run_id')->get() as $c) {
            $this->assertTrue(Run::query()->live()->whereKey($c->current_run_id)->exists(), "character {$c->id} points at a run that is not live. {$say}");
        }

        foreach (RunPartyMember::query()->get() as $m) {
            $this->assertTrue(RunParty::query()->whereKey($m->run_party_id)->exists());
        }

        // At rest (nobody has chosen), everybody inside agrees about the shared things.
        foreach (Run::query()->live()->whereNotNull('run_party_id')->get()->groupBy('run_party_id') as $runs) {
            if ($runs->count() < 2 || $runs->contains(fn (Run $r): bool => $r->pending_action !== null || $r->turn < 1)) {
                continue;
            }

            $this->assertCount(1, $runs->map(fn (Run $r): string => (int) $r->detected.'|'.($r->ice_id ?? 'x').'|'.($r->ice_integrity ?? 'x'))->unique(), 'shared state diverged: '.$runs->map(fn (Run $r): string => $r->id.':'.(int) $r->detected.'/'.($r->ice_id ?? 'x').'/'.($r->ice_integrity ?? 'x'))->implode(' ').". {$say}");
        }

        // Liveness: any party with two or more runners inside must be moved on by the clock alone.
        foreach (Run::query()->live()->whereNotNull('run_party_id')->get()->groupBy('run_party_id') as $partyId => $runs) {
            if ($runs->count() < 2) {
                continue;
            }

            $before = $runs->sum('turn');
            RunParty::query()->whereKey($partyId)->update(['round_started_at' => now()->subSeconds(31)]);
            $member = Character::query()->find($runs->first()->character_id);
            $this->actingAs($member->user)->get(route('run.show'));
            $after = Run::query()->where('run_party_id', $partyId)->get()->sum('turn');
            $this->assertGreaterThan($before, $after, "party {$partyId} cannot be moved on by the clock. {$say}");
        }
    }
}
