<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\Lockdown;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\ScanFact;
use App\Missions\MissionAction;
use App\Missions\MissionState;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Entity;
use App\Models\Exchange;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Mission;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\RunPartyMember;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
use App\Programs\ProgramKind;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * The briefing: everything decided before a run, and everything not known.
 *
 * The warnings are the product. A run cannot be taken back — the purpose is
 * fixed at the door, a backdoor's kind is permanent, the route is snapshotted
 * — so the screen's job is to say what is unread, what the deck is about to do
 * to itself, and what is not running.
 */
final class RunBriefingTest extends TestCase
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
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();
    }

    // ---- getting to it ------------------------------------------------------

    public function test_it_draws_the_machine_you_are_standing_on(): void
    {
        $system = $this->machine();

        $this->visit()
            ->assertOk()
            ->assertSee($system->displayName())
            ->assertSee($system->address);
    }

    /** Nothing here is nothing to plan against. */
    public function test_it_turns_you_away_from_empty_ground(): void
    {
        $this->visit()->assertRedirect(route('cyberspace.index'));
    }

    public function test_it_turns_away_somebody_who_is_not_in_cyberspace(): void
    {
        $this->machine();
        $this->character->forceFill(['grid_x' => null, 'grid_y' => null])->save();

        $this->visit()->assertRedirect(route('cyberspace.index'));
    }

    /**
     * SOMEBODY IN A RUN IS SENT TO THE RUN. Planning the next one from inside
     * this one is not a thing to do, and the lock already says so — this is
     * the assertion that the briefing was not accidentally added to its
     * allow-list.
     */
    public function test_the_run_lock_holds_it_too(): void
    {
        $this->machine();

        $run = Run::query()->create([
            'character_id' => $this->character->id,
            'address' => 'DEADBEEF',
            'x' => 8, 'y' => 8,
            'purpose' => RunPurpose::Extraction->value,
            'turn' => 1,
            'started_at' => now(),
        ]);
        $this->character->forceFill(['current_run_id' => $run->id])->save();

        $this->visit()->assertRedirect(route('run.show'));
    }

    // ---- what is not known --------------------------------------------------

    /** An unread machine says so, because a blank column reads as "nothing there". */
    public function test_it_says_when_nothing_has_been_read(): void
    {
        $this->machine();

        $this->visit()
            ->assertOk()
            ->assertSee(Text::get('cyberspace.run.setup.unread'))
            ->assertSee(Text::get('cyberspace.run.setup.unknown'));
    }

    public function test_a_partial_file_is_called_incomplete(): void
    {
        $system = $this->machine();
        $this->scan($system, ['grade' => 1]);

        $this->visit()
            ->assertOk()
            ->assertSee(Text::get('cyberspace.run.setup.partial'))
            // The fact that IS known is shown beside the ones that are not, in the scanner's own boxes.
            ->assertSee(Text::get('cyberspace.reading.unread'))
            ->assertSee('tag--ember', false);
    }

    public function test_a_complete_file_says_so(): void
    {
        $system = $this->machine();
        // Every discoverable fact answered. Zero is a real answer here — "it
        // has none" — which is the whole reason these columns are nullable.
        // The operating system is the exception: null means NOT READ there,
        // so a complete file has to name the skill the machine runs.
        $this->scan($system, [
            'grade' => 1,
            'os_skill_id' => $system->os_skill_id ?? Skill::query()->value('id'),
            'ids_detection' => 0,
            'tracer_detection' => 0,
            'tracer_rate' => 0,
            'ice_level' => 0,
            'ice_bounty' => 0,
        ]);

        $this->visit()->assertOk()->assertSee(Text::get('cyberspace.run.setup.complete'));
    }

    // ---- what the deck is about to do ---------------------------------------

    public function test_it_names_what_is_running_and_what_each_thing_is(): void
    {
        $this->machine();
        $item = $this->runProgram('scanner-mk1');

        $this->visit()
            ->assertOk()
            ->assertSee($item->displayName())
            ->assertSee(ProgramKind::Analyser->label());
    }

    /**
     * NO ICEBREAKER TO LOAD is the warning that decides whether a fight is
     * survivable — and it is worth saying even when something else is running,
     * because a deck full of analysers still cannot cut. An icebreaker is
     * never running here: the run starts it, from the loadout.
     */
    public function test_it_warns_when_there_is_no_icebreaker_to_load(): void
    {
        $this->machine();
        $this->runProgram('scanner-mk1');

        $this->visit()->assertOk()->assertSee('If something wakes up, you cannot fight it');
    }

    /** And offers it instead, when one is held. */
    public function test_it_offers_an_icebreaker_that_is_held(): void
    {
        $this->machine();
        InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => Item::query()->where('slug', 'icebreaker-1')->value('id'),
            'quantity' => 1,
        ]);

        $this->visit()->assertOk()->assertDontSee('you cannot fight it')->assertSee('name="icebreaker"', false);
    }

    public function test_the_loadout_hint_says_what_to_do_and_when_it_locks(): void
    {
        $this->machine();

        $this->visit()->assertOk()->assertSee('Select an icebreaker to apply to this run. It cannot be changed once the run is initiated.');
    }

    public function test_it_says_nothing_when_nothing_is_running(): void
    {
        $this->machine();

        $this->visit()->assertOk()->assertDontSee('Nothing is running')->assertDontSee('you cannot scan');
    }

    /** The deck screen's own bars, not a sentence: compute, memory and power draw against what it resolves. */
    public function test_the_deck_is_shown_as_the_deck_screens_bars(): void
    {
        $this->machine();

        $this->visit()
            ->assertOk()
            ->assertSee('class="vitals"', false)
            ->assertSee(Text::get('decks.fitting.compute'))
            ->assertSee(Text::get('decks.fitting.memory'))
            ->assertSee(Text::get('decks.fitting.draw'))
            ->assertDontSee('Thermals are fine');
    }

    // ---- the overview ---------------------------------------------------------

    public function test_the_overview_lists_every_fact_a_scan_can_read_either_way(): void
    {
        $this->machine();

        $html = $this->visit()->assertOk()->assertSeeText(Text::get('cyberspace.run.setup.known'))->getContent();

        foreach (ScanFact::discoverable() as $fact) {
            $this->assertStringContainsString('data-fact="'.$fact->value.'"', $html, "{$fact->value} is listed even though nothing is read");
        }

        $this->assertStringNotContainsString('data-fact="cloaked"', $html, 'a cloak nobody has seen through is not a blank row');
    }

    public function test_an_incomplete_file_wears_a_thin_hazard_stripe_and_a_complete_one_does_not(): void
    {
        $system = $this->machine();

        $this->visit()->assertOk()->assertSee('hazard-stripe hazard-stripe--thin', false);

        $this->scan($system, [
            'grade' => 1, 'os_skill_id' => Skill::query()->value('id'),
            'ids_detection' => 0, 'tracer_detection' => 0, 'tracer_rate' => 0, 'ice_level' => 0, 'ice_bounty' => 0,
        ]);

        $this->visit()->assertOk()->assertDontSee('hazard-stripe', false);
    }

    public function test_the_header_panel_is_gone_and_the_way_back_remains(): void
    {
        $this->machine();

        $this->visit()
            ->assertOk()
            ->assertDontSee(Text::get('cyberspace.run.setup.title').'</h2>', false)
            ->assertSee(route('cyberspace.index'), false);
    }

    // ---- the route ---------------------------------------------------------------

    public function test_every_node_of_the_route_opens_a_card_and_the_count_says_hops(): void
    {
        $this->machine();
        // Routing is a skill: untrained, the chain is empty and there is no route to draw.
        $routing = Skill::factory()->rank(4)->governedBy('intellect', 'memory')->create([
            'slug' => 'a_routing_skill',
            'modifiers' => array_map(static fn (int $at): array => ['op' => 'add', 'level' => $at, 'amount' => 1, 'target' => ['id' => 'routing_hops']], range(1, 5)),
        ]);
        CharacterSkill::query()->create(['character_id' => $this->character->id, 'skill_id' => $routing->id, 'attained_level' => 2, 'skill_points' => 0]);
        $relay = System::factory()->at(2, 2)->grade(3)->create(['address' => 'FEEDF00D']);
        (new Backdoors)->install($this->character, $relay, BackdoorKind::Routing);
        $this->scan($relay, ['grade' => 3, 'ids_detection' => 0]);

        $html = $this->visit()->assertOk()->getContent();

        $this->assertStringContainsString('data-inspect="#hop-'.$relay->id.'"', $html);
        $this->assertStringContainsString('data-inspect="#hop-you"', $html);
        $this->assertStringContainsString('<template id="hop-'.$relay->id.'">', $html);
        $this->assertStringContainsString('<template id="hop-you">', $html);
        // The relay's card carries the scanner rows for what was read of it.
        $this->assertStringContainsString('FEEDF00D', $html);
        $this->assertMatchesRegularExpression('#<template id="hop-'.$relay->id.'">.*?data-fact="grade".*?</template>#s', $html);
        $this->assertStringContainsString('1/', $html);
        $this->assertStringContainsString('hops', $html);
    }

    // ---- the words -----------------------------------------------------------------

    public function test_the_mission_goals_read_as_they_were_asked_to(): void
    {
        $this->machine();

        $this->visit()
            ->assertOk()
            ->assertSeeText('Mission goal')
            ->assertDontSeeText('What you came to do')
            ->assertSeeText('Extract data')
            ->assertSeeText('Ingress, steal some data, egress. Does not trigger a lockdown')
            ->assertSeeText('Install a siphon that redirects some cashflow every night')
            ->assertSeeText('Backdoor for routing')
            ->assertSeeText('Add this system to your routing chain. The more systems you route through, the longer a tracer will take to trace you');
    }

    public function test_a_placement_job_for_this_system_turns_the_siphon_button_into_place_backdoor_and_green(): void
    {
        $system = $this->machine();

        $this->visit()->assertSeeText('Install a siphon')->assertDontSeeText('Place backdoor');

        $entity = Entity::query()->interactable()->firstOrFail();
        $deskSystem = System::factory()->at(2, 2)->ownedBy($entity)->create(['type' => 'exchange', 'grade' => 1]);
        $desk = Exchange::query()->create(['system_id' => $deskSystem->id, 'grade' => 1, 'agent' => 'Mr Nakata', 'min_standing' => 0]);
        $job = Mission::query()->create([
            'character_id' => $this->character->id, 'entity_id' => $entity->id, 'exchange_id' => $desk->id,
            'action' => MissionAction::Plant, 'state' => MissionState::Accepted, 'quantity_required' => 0,
            'creds_reward' => 100, 'standing_reward' => 0.02, 'loyalty_reward' => 1, 'penalty' => 0.02, 'offered_at' => now(),
            'accepted_at' => now(), 'expires_at' => now()->addHours(5),
        ]);
        $job->targets()->create(['system_id' => $system->id, 'address' => $system->address]);

        $html = $this->visit()->assertOk()->assertSeeText('Place backdoor')->getContent();
        $this->assertMatchesRegularExpression('/btn--go"[^<]*>\s*Place backdoor/', $html);
    }

    // ---- the intentions ------------------------------------------------------

    public function test_it_offers_every_intention_there_is(): void
    {
        $this->machine();

        $this->visit()
            ->assertOk()
            ->assertSee(Text::get('cyberspace.run.setup.intent_extraction'))
            ->assertSee(Text::get('cyberspace.run.setup.intent_siphon'))
            ->assertSee(Text::get('cyberspace.run.setup.intent_routing'))
            ->assertSee(Text::get('cyberspace.run.setup.intent_burn'))
            // Every intention is a real submit now. Burn was the one shown
            // disabled with a "Not built yet" tag; it starts a run like the
            // rest, and the tag is gone rather than merely unasserted.
            ->assertDontSee('Not built yet');
    }

    /** Holding one already is worth saying: the approach is free from now on. */
    public function test_it_says_when_you_already_hold_the_machine(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        $this->visit()
            ->assertOk()
            ->assertSee(Text::get('cyberspace.run.setup.held'))
            ->assertSee(Text::get('cyberspace.run.setup.held_kind', [
                'kind' => BackdoorKind::Siphon->label(),
            ]));
    }

    /** A shut machine cannot be entered: the briefing closes before the run is even asked. */
    public function test_a_shut_machine_offers_nothing(): void
    {
        $system = $this->machine();
        Lockdown::fromConfig(static fn (int $min, int $max): int => $max)->shut($system);

        // The planning screen is closed on them, with a toast on the map saying why.
        $this->visit()
            ->assertRedirect(route('cyberspace.index'))
            ->assertSessionHas('toast', Text::get('cyberspace.run.refused.locked_while_planning'));
    }

    // ---- fixtures ------------------------------------------------------------

    public function test_the_owner_starts_a_run_for_every_joined_member_and_the_party_ends_with_it(): void
    {
        Config::set('cyberspace.coop.slots', 2);
        $this->machine();
        $friend = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($friend);
        $friend->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();

        $owner = $this->actingAs($this->character->user);
        $owner->post(route('run.party.open'), ['share_credits' => 'even', 'share_extraction' => 'even']);
        $owner->post(route('run.party.invite'), ['handle' => $friend->handle]);
        $this->actingAs($friend->user)->post(route('run.party.accept'))->assertRedirect(route('run.setup'));

        // A member cannot start a run of their own.
        $this->actingAs($friend->user)->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value])
            ->assertSessionHas('error');

        $this->actingAs($this->character->user)->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value])
            ->assertRedirect(route('run.show'));

        $mine = $this->character->fresh()->currentRun;
        $theirs = $friend->fresh()->currentRun;
        $this->assertNotNull($mine);
        $this->assertNotNull($theirs);
        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame($mine->run_party_id, $theirs->run_party_id);
        $this->assertNotNull($mine->run_party_id);

        // A round: the first choice waits for the other, and the second resolves it for both.
        $this->actingAs($this->character->user)->post(route('run.act'), ['action' => 'hold'])->assertRedirect(route('run.show'));
        $this->assertSame('hold', $mine->fresh()->pending_action);
        $this->assertSame(0, $mine->fresh()->turn);

        // A fresh user: the one held above has the character from before the run began cached on it.
        $this->actingAs($friend->fresh()->user)->post(route('run.act'), ['action' => 'hold'])->assertRedirect(route('run.show'));
        $this->assertSame(1, $mine->fresh()->turn);
        $this->assertSame(1, $theirs->fresh()->turn);
        $this->assertNull($mine->fresh()->pending_action);

        // Nobody answering is not a stall: past the deadline the next look resolves it by policy.
        RunParty::query()->update(['round_started_at' => now()->subSeconds(31)]);
        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk();
        $this->assertSame(2, $mine->fresh()->turn);
    }

    private function machine(?int $iceLevel = null): System
    {
        $factory = System::factory()->at(8, 8)->grade(1)->withTracer(2, 4);

        if ($iceLevel !== null) {
            $factory = $factory->withIce(Ice::query()->where('level', $iceLevel)->sole());
        }

        return $factory->create(['address' => 'DEADBEEF']);
    }

    /** @param  array<string, mixed>  $known */
    private function scan(System $system, array $known): void
    {
        SystemScan::query()->create(array_merge([
            'character_id' => $this->character->id,
            'address' => $system->address,
            'x' => $system->x,
            'y' => $system->y,
            'passes' => 1,
            'scanned_at' => now(),
            'expires_at' => now()->addDay(),
        ], $known));
    }

    private function runProgram(string $slug): Item
    {
        $item = Item::query()->where('slug', $slug)->sole();

        InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'running_on_deck_id' => $this->character->equipped_deck_id,
            'started_at' => now(),
        ]);

        return $item;
    }

    private function visit()
    {
        return $this->actingAs($this->character->fresh()->user)->get(route('run.setup'));
    }

    public function test_a_trained_runner_forms_a_party_and_invites_from_the_briefing(): void
    {
        Config::set('cyberspace.coop.slots', 2);
        $this->machine();
        $friend = Character::factory()->create();

        $this->actingAs($this->character->user)->post(route('run.party.open'), ['share_credits' => 'half', 'share_extraction' => 'full'])
            ->assertRedirect(route('run.setup'));
        $this->actingAs($this->character->user)->post(route('run.party.invite'), ['handle' => $friend->handle])
            ->assertRedirect(route('run.setup'));

        $this->visit()->assertOk()->assertSee($friend->handle)->assertSee(Text::get('cyberspace.party.disband'));
        $this->assertSame(1, RunPartyMember::query()->count());
    }
}
