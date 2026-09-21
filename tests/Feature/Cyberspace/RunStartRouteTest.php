<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\Run;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * `run.start`, over HTTP — the door the briefing's buttons post to.
 *
 * ## Why this exists
 *
 * It was the last route in the application with no HTTP test of any kind.
 * `RunStarter` is exercised thoroughly by `RunTest`, and the briefing that
 * renders the buttons by `RunBriefingTest`, but nothing between the two ever
 * posted the form — so the controller's own half (validation, the enums
 * coming back off a request as strings, and what a refusal looks like to a
 * player) was covered by inference rather than by a request.
 *
 * That is the gap `RunScreenSmokeTest` was written after two 500s shipped in
 * 0.10.0, and the rule it left behind: a mechanic tested only through its own
 * class cannot find a broken screen, and anything with a route wants at least
 * one test that takes what a real request takes. This is that test for the one
 * route that still lacked one.
 *
 * ## What it asserts, and what it deliberately does not
 *
 * That the route resolves, that every purpose is accepted or refused rather
 * than exploding, and that a refusal comes back as a message a player can read
 * instead of a server error. WHAT a run then does is `RunTest`'s business.
 *
 * The purposes are walked off the enum rather than named, the way
 * `RunScreenSmokeTest` walks `RunAction` — so a new purpose is covered the day
 * it is added. The assertion is therefore "not a server error" rather than a
 * verdict, because a purpose the game has not built yet is entitled to be
 * refused and a built one is entitled to begin.
 */
final class RunStartRouteTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->equipReadyDeck($this->character);
        $this->standAt(8, 8);
    }

    // ---- the door opens ----------------------------------------------------

    public function test_posting_the_briefing_begins_a_run(): void
    {
        $this->machine();

        $this->actingAs($this->user())
            ->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value])
            ->assertRedirect(route('run.show'));

        $run = $this->character->fresh()->currentRun;

        $this->assertNotNull($run, 'the character is in a run');
        $this->assertSame(RunPurpose::Extraction, $run->purpose);
        $this->assertSame('DEADBEEF', $run->address);
    }

    /**
     * EVERY PURPOSE GOES THROUGH THE DOOR. Accepted or refused is not the
     * point — that it is one of those two, and never a 500, is.
     */
    public function test_every_purpose_is_answered_rather_than_exploding(): void
    {
        foreach (RunPurpose::values() as $purpose) {
            $this->reset();
            $this->machine();

            $response = $this->actingAs($this->user())
                ->post(route('run.start'), ['purpose' => $purpose]);

            $response->assertRedirect();
            $this->assertLessThan(
                500,
                $response->getStatusCode(),
                "the {$purpose} purpose came back a server error",
            );
        }
    }

    /**
     * A backdoor's kind is chosen at the door and is permanent, so the form
     * carries it and the run has to record the one that was posted.
     */
    public function test_a_backdoor_run_records_the_kind_it_was_started_with(): void
    {
        foreach (BackdoorKind::cases() as $kind) {
            $this->reset();
            $this->machine();

            $this->actingAs($this->user())->post(route('run.start'), [
                'purpose' => RunPurpose::Backdoor->value,
                'backdoor_kind' => $kind->value,
            ])->assertRedirect(route('run.show'));

            $this->assertSame($kind, $this->character->fresh()->currentRun->backdoor_kind);
        }
    }

    // ---- and refuses, in words -------------------------------------------

    /**
     * EVERY REFUSAL IS A MESSAGE AND NEVER A 500. The controller hands
     * `RunResult::refusal` straight to the session, so a refusal that produced
     * an exception instead would reach a player as a blank error page at the
     * moment they pressed the one button on the screen.
     */
    public function test_a_machine_that_is_not_there_is_refused_in_words(): void
    {
        $this->assertRefused();
    }

    public function test_the_portal_is_refused_in_words(): void
    {
        System::factory()->at(8, 8)->type(SystemType::Portal)->create(['address' => 'PORTAL00']);

        $this->assertRefused();
    }

    public function test_a_locked_down_machine_is_refused_in_words(): void
    {
        $this->machine()->forceFill(['locked_down_until' => now()->addHours(3)])->save();

        $this->assertRefused();
    }

    public function test_being_jacked_out_is_refused_in_words(): void
    {
        $this->machine();
        $this->character->forceFill(['grid_x' => null, 'grid_y' => null])->save();

        $this->assertRefused();
    }

    /**
     * A DECK THAT STOPPED RUNNING NEVER REACHES THE CONTROLLER, and this is
     * the test that found that out: it was written expecting
     * `RunRefusal::DeckNotReady` and passed even with the controller's refusal
     * path deliberately broken.
     *
     * `EnsureDeckStillRuns` is why. It runs before every jacked-in request,
     * and `run.start` is a cyberspace route — so the net ejects the player and
     * turns the request away with its own message rather than the controller's
     * one, which would have said *you are not in cyberspace* and explained
     * nothing. The refusal in `RunStarter` is therefore defence in depth for
     * the callers that are not requests, and `RunTest` is where it is proved.
     *
     * Asserted by the ejection rather than by the message: being put out of
     * cyberspace is the thing only the net does.
     */
    public function test_a_deck_that_stopped_running_is_caught_by_the_net_first(): void
    {
        $this->machine();
        $this->character->forceFill(['equipped_deck_id' => null])->save();

        $this->actingAs($this->user())
            ->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value])
            ->assertRedirect();

        $character = $this->character->fresh();

        $this->assertNull($character->current_run_id, 'no run was begun');
        $this->assertFalse($character->isJackedIn(), 'the net put them out of cyberspace');
    }

    /**
     * The second press. The lock ordinarily keeps somebody in a run away from
     * this route altogether, so reaching the refusal at all takes a request
     * the middleware lets through — but the writer refuses it either way, and
     * which of the two answers arrives is not worth pinning.
     */
    public function test_a_second_run_never_becomes_a_second_run(): void
    {
        $this->machine();
        app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction);

        $response = $this->actingAs($this->user())
            ->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value]);

        $response->assertRedirect();
        $this->assertSame(1, Run::query()->count(), 'still exactly one run');
    }

    // ---- what the form is allowed to say -----------------------------------

    public function test_a_purpose_nothing_knows_is_a_validation_error(): void
    {
        $this->machine();

        $this->actingAs($this->user())
            ->post(route('run.start'), ['purpose' => 'loiter'])
            ->assertSessionHasErrors('purpose');

        $this->assertNull($this->character->fresh()->current_run_id);
    }

    public function test_a_missing_purpose_is_a_validation_error(): void
    {
        $this->machine();

        $this->actingAs($this->user())
            ->post(route('run.start'), [])
            ->assertSessionHasErrors('purpose');
    }

    public function test_a_backdoor_kind_nothing_knows_is_a_validation_error(): void
    {
        $this->machine();

        $this->actingAs($this->user())->post(route('run.start'), [
            'purpose' => RunPurpose::Backdoor->value,
            'backdoor_kind' => 'skeleton',
        ])->assertSessionHasErrors('backdoor_kind');
    }

    /**
     * An extraction posted with a kind is not an error — `RunStarter` drops it
     * for every purpose but a backdoor rather than trusting the form, and the
     * run has to come back carrying nothing.
     */
    public function test_a_kind_posted_with_the_wrong_purpose_is_dropped(): void
    {
        $this->machine();

        $this->actingAs($this->user())->post(route('run.start'), [
            'purpose' => RunPurpose::Extraction->value,
            'backdoor_kind' => BackdoorKind::Siphon->value,
        ])->assertRedirect(route('run.show'));

        $this->assertNull($this->character->fresh()->currentRun->backdoor_kind);
    }

    // ---- fixtures ----------------------------------------------------------

    private function machine(): System
    {
        return System::factory()->at(8, 8)->grade(1)->create(['address' => 'DEADBEEF']);
    }

    private function standAt(int $x, int $y): void
    {
        $this->character->forceFill(['grid_x' => $x, 'grid_y' => $y])->save();
        $this->character->refresh();
    }

    private function user(): object
    {
        return $this->character->fresh()->user;
    }

    /** Back to the door, for the next case in a loop. */
    private function reset(): void
    {
        Run::query()->delete();
        System::query()->delete();

        $this->character->forceFill(['current_run_id' => null])->save();
        $this->standAt(8, 8);
    }

    /** Refused with something to read, and no run to show for it. */
    private function assertRefused(): void
    {
        $this->actingAs($this->user())
            ->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($this->character->fresh()->current_run_id, 'no run was begun');
    }
}
