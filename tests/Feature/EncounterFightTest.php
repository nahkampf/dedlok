<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Flags;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\City\CityClock;
use App\City\CityRefusal;
use App\City\EncounterRunner;
use App\City\Encounters;
use App\City\EncounterStep;
use App\City\Explorer;
use App\Combat\Fight\Action;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\Result;
use App\Combat\FightEnding;
use App\Combat\Fights;
use App\Combat\WeaponClass;
use App\Models\Ammunition;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\Encounter;
use App\Models\Fight;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Npc;
use App\Models\Weapon;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * A fight started by an encounter's button, and where it takes the player when it ends. COMBAT.md, step 5.
 *
 * A fight choice names an opponent and three destinations — win, lose, and nobody winning — and the
 * encounter row STAYS while the fight is live. Rewards are the arrival outcomes of the win screen.
 */
final class EncounterFightTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => 500]));
        $this->character->forceFill(['district_id' => District::query()->where('slug', 'dockside')->firstOrFail()->id])->save();
    }

    /** An opponent whose every shot is Dead-on at a die of 0.0, and who has a few hit points. */
    private function npc(int $hp = 40, string $slug = 'thug'): Npc
    {
        $gun = Item::factory()->physical()->create(['name' => 'Gang pistol', 'stackable' => false]);
        $calibre = 'gang-'.$gun->id;
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => $calibre]);
        $round = Item::factory()->physical()->create(['name' => 'Gang round', 'stackable' => true]);
        Ammunition::create(['item_id' => $round->id, 'calibre' => $calibre, 'type' => 'standard', 'damage' => 5, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);

        return Npc::create(['slug' => $slug, 'name' => 'Thug', 'grade' => 2, 'hitpoints' => $hp, 'aim' => 99, 'defence' => 0, 'pursuit' => 8, 'ranged_weapon_id' => $gun->id, 'ammo_item_id' => $round->id]);
    }

    /**
     * An encounter whose opening screen has one button, a fight, and an ending screen for each way it can go.
     *
     * @param  array<string, mixed>  $choice  overrides for the fight choice
     * @param  array<string, array<string, mixed>>  $nodes  overrides or additions, by key
     */
    private function ambush(Npc $npc, array $choice = [], array $nodes = [], string $startsOn = 'travel'): Encounter
    {
        $encounter = Encounter::create(['slug' => 'ambush', 'title' => 'Ambush', 'starts_on' => $startsOn, 'weight' => 100]);

        $screens = array_replace_recursive([
            'start' => ['body' => 'Somebody steps out.', 'entry' => true, 'choices' => [array_merge([
                'label' => 'Fight', 'fight_npc_id' => $npc->id, 'win_key' => 'won', 'lose_key' => 'lost', 'flee_key' => 'away',
            ], $choice)]],
            'won' => ['body' => 'You win.', 'ends' => true],
            'lost' => ['body' => 'You wake in the gutter.', 'ends' => true],
            'away' => ['body' => 'You slip away.', 'ends' => true],
        ], $nodes);

        foreach ($screens as $key => $spec) {
            $node = $encounter->nodes()->create(['node_key' => $key, 'body' => $spec['body'], 'entry' => $spec['entry'] ?? false, 'ends' => $spec['ends'] ?? false]);

            foreach ($spec['outcomes'] ?? [] as $i => $outcome) {
                $node->outcomes()->create(['position' => $i, 'branch' => 'always'] + $outcome);
            }

            foreach ($spec['choices'] ?? [] as $position => $c) {
                $node->choices()->create(['position' => $position] + $c);
            }
        }

        return $encounter;
    }

    /** Put the character on the encounter's opening screen, as a pass would. */
    private function enter(Encounter $encounter): CharacterEncounter
    {
        $live = CharacterEncounter::create(['character_id' => $this->character->id, 'encounter_id' => $encounter->id, 'node_key' => 'start', 'log' => [], 'started_at' => now()]);
        $this->character->forceFill(['current_encounter_id' => $live->id])->save();

        return $live;
    }

    private function runner(): EncounterRunner
    {
        return app(EncounterRunner::class);
    }

    private function choiceId(Encounter $encounter): int
    {
        return $encounter->nodes()->where('node_key', 'start')->firstOrFail()->choices()->firstOrFail()->id;
    }

    /** Bind the screens' own Fights to a pinned die. */
    private function dice(array $values): QueueDice
    {
        $dice = new QueueDice($values);
        $this->app->bind(Fights::class, fn (): Fights => new Fights(new FightEngine(CheckRoller::fromConfig()), $dice));
        // The ending's own die: the lowest roll, so the pay and the mugging are the band's floors.
        $this->app->bind(FightEnding::class, fn (): FightEnding => FightEnding::fromConfig(fn (int $min, int $max): int => $min));

        return $dice;
    }

    // ---- opening it ---------------------------------------------------------------------

    public function test_the_choice_opens_a_fight_and_the_encounter_waits(): void
    {
        $this->dice([]);
        $encounter = $this->ambush($this->npc(), ['cost' => 25]);
        $live = $this->enter($encounter);

        $step = $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        $this->assertInstanceOf(EncounterStep::class, $step);
        $this->assertTrue($step->fight);
        $this->assertFalse($step->closed);

        $character = $this->character->fresh();
        $this->assertNotNull($character->current_fight_id);
        $this->assertSame($live->id, $character->current_encounter_id, 'the encounter stays while the fight is live');
        $this->assertSame('start', $live->fresh()->node_key, 'and has not moved');
        $this->assertSame(475, $character->creds, 'a fight can cost');

        $fight = Fight::query()->sole();
        $this->assertSame($live->id, $fight->encounter_row_id);
        $this->assertSame(['won', 'lost', 'away'], [$fight->win_key, $fight->lose_key, $fight->flee_key]);
        $this->assertSame('Thug', $fight->opponent_name);

        $this->assertSame('Fight', $live->fresh()->log[0]['label']);
        $this->assertSame('You are in a fight with Thug.', $live->fresh()->log[0]['lines'][0]);
    }

    public function test_a_fight_that_cannot_open_costs_nothing(): void
    {
        $encounter = $this->ambush($this->npc(), ['cost' => 25]);
        $this->enter($encounter);
        $this->character->forceFill(['grid_x' => 3, 'grid_y' => 4])->save();      // jacked in: a fight is in the City

        $result = $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        $this->assertSame(CityRefusal::NotAChoice, $result);
        $this->assertSame(500, $this->character->fresh()->creds, 'the refusal commits, so the cost must never have been charged');
        $this->assertSame(0, Fight::query()->count());
    }

    public function test_it_will_not_start_what_you_cannot_pay_for(): void
    {
        $encounter = $this->ambush($this->npc(), ['cost' => 900]);
        $this->enter($encounter);

        $this->assertSame(CityRefusal::CannotPay, $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter)));
        $this->assertSame(0, Fight::query()->count());
        $this->assertSame(500, $this->character->fresh()->creds);
    }

    public function test_a_second_press_while_a_fight_is_live_is_refused(): void
    {
        $encounter = $this->ambush($this->npc());
        $this->enter($encounter);

        $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));
        $again = $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        $this->assertSame(CityRefusal::Fighting, $again);
        $this->assertSame(1, Fight::query()->count());
    }

    public function test_a_fight_choice_gated_on_a_flag_is_hidden_until_it_is_held(): void
    {
        $encounter = $this->ambush($this->npc(), ['requires_flag' => 'armed']);
        $this->enter($encounter);

        $this->assertSame(CityRefusal::NotAChoice, $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter)));
        $this->assertSame(0, Fight::query()->count());

        Flags::set($this->character, 'armed');
        $this->assertTrue($this->runner()->choose($this->character->fresh(), $this->choiceId($encounter))->fight);
    }

    public function test_an_encounter_a_real_explore_pass_opened_can_start_a_fight(): void
    {
        $this->dice([]);
        $encounter = $this->ambush($this->npc(), startsOn: 'explore');
        $this->app->bind(Encounters::class, fn ($app): Encounters => Encounters::fromConfig($app->make(Resolver::class), static fn (int $min, int $max): int => min($max, max($min, 1))));
        $this->app->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.99));

        app(Explorer::class)->begin($this->character);
        $this->travel(10)->minutes();
        app(CityClock::class)->settle($this->character->fresh());

        $this->assertNotNull($this->character->fresh()->current_encounter_id, 'the pass opened it');
        $this->assertNull(CityActivity::query()->find($this->character->id), 'and the pass is gone, so nothing reads as busy');

        $step = $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        $this->assertInstanceOf(EncounterStep::class, $step);
        $this->assertTrue($step->fight);
    }

    public function test_pressing_it_sends_the_player_to_the_fight_and_the_encounter_screen_is_locked_out(): void
    {
        $this->dice([]);
        $encounter = $this->ambush($this->npc());
        $this->enter($encounter);
        $user = $this->character->user;

        $this->actingAs($user)->post(route('encounter.choose'), ['choice' => $this->choiceId($encounter)])
            ->assertRedirect(route('fight.show'))->assertSessionHas('status', 'You are in a fight with Thug.');

        // A fresh user each time: the first request cached this one's character, pointer and all.
        $this->actingAs($user->fresh())->get(route('fight.show'))->assertOk()->assertSeeText('Thug');
        $this->actingAs($user->fresh())->get(route('encounter.show'))->assertRedirect(route('fight.show'));
    }

    // ---- how it ends --------------------------------------------------------------------

    /** The character carries a loaded pistol: Dead-on for 8 at a die of 0.0 and a body die of 0.5. */
    private function armed(): void
    {
        $gun = Item::factory()->physical()->create(['name' => 'Pistol', 'stackable' => false]);
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => '9mm']);
        $row = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $gun->id, 'quantity' => 1]);
        $round = Item::factory()->physical()->create(['name' => '9mm', 'stackable' => true]);
        Ammunition::create(['item_id' => $round->id, 'calibre' => '9mm', 'type' => 'standard', 'damage' => 8, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);
        $row->forceFill(['loaded_ammo_id' => $round->id, 'loaded_rounds' => 12])->save();
        $this->character->forceFill(['equipped_ranged_id' => $row->id])->save();
    }

    /** Start the fight from its button, then play one round with these dice. */
    private function fightAndAct(Encounter $encounter, array $dice, Action $action, int $distance = 2): Fight
    {
        $this->dice($dice);
        $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        if ($distance !== 2) {
            $row = Fight::query()->sole();
            $row->update(['state' => [...$row->state, 'distance' => $distance]]);
        }

        $fight = app(Fights::class)->act($this->character->fresh(), $action);
        $this->assertInstanceOf(Fight::class, $fight);

        return $fight;
    }

    public function test_a_win_goes_to_the_win_screen_and_its_arrival_outcomes_are_the_reward(): void
    {
        $this->armed();
        $encounter = $this->ambush($this->npc(hp: 5), nodes: ['won' => ['outcomes' => [['kind' => 'creds', 'amount' => 100]]]]);
        $live = $this->enter($encounter);

        $fight = $this->fightAndAct($encounter, [0.0, 0.5,  0.0, 0.5], Action::attack());

        $this->assertSame(Result::BDown, $fight->result);
        $character = $this->character->fresh();
        $this->assertNull($character->current_fight_id);
        $this->assertSame($live->id, $character->current_encounter_id, 'the encounter carries on');
        $this->assertSame('won', $live->fresh()->node_key);
        $pay = (int) config('combat.pay.2.min');
        $this->assertSame(500 + $pay + 100, $character->creds, 'the fight paid its band, then the win screen\'s arrival outcome paid out');

        $entry = $live->fresh()->log[array_key_last($live->fresh()->log)];
        $this->assertSame('Fight with Thug', $entry['label']);
        $this->assertSame('Thug is down.', $entry['lines'][0]);
        $this->assertSame("You take {$pay} ¤ off it.", $entry['lines'][1], 'what the fight was worth is in the encounter\'s log, not only the fight\'s');
    }

    public function test_a_loss_goes_to_the_loss_screen_which_they_read_after_they_get_up(): void
    {
        $this->armed();
        $this->character->forceFill(['current_hitpoints' => 3])->save();
        $encounter = $this->ambush($this->npc(), nodes: ['lost' => ['outcomes' => [['kind' => 'creds', 'amount' => -50]]]]);
        $live = $this->enter($encounter);

        $fight = $this->fightAndAct($encounter, [0.0, 0.5], Action::attack());

        $this->assertSame(Result::ADown, $fight->result);
        $character = $this->character->fresh();
        $this->assertTrue(app(WaitingRoom::class)->isDown($character), 'on the floor first');
        $this->assertSame('lost', $live->fresh()->node_key);
        $mugged = (int) floor(500 * (int) config('combat.mugging.min') / 100);
        $this->assertSame(500 - $mugged - 50, $character->creds, 'mugged on the way down, then the loss screen applied to somebody already down');

        // A minute later they get up, and the encounter is where it was left.
        $this->travel(2)->minutes();
        $this->actingAs($this->character->user->fresh())->get(route('encounter.show'))
            ->assertOk()->assertSeeText('You wake in the gutter.')->assertSeeText('Fight with Thug')->assertSeeText('You are down.');
    }

    public function test_getting_away_goes_to_the_nobody_won_screen_and_pays_nothing(): void
    {
        $this->armed();
        $encounter = $this->ambush($this->npc(), nodes: ['won' => ['outcomes' => [['kind' => 'creds', 'amount' => 100]]]]);
        $live = $this->enter($encounter);

        $fight = $this->fightAndAct($encounter, [0.0, 0.5,  0.0], Action::disengage(), distance: 3);

        $this->assertSame(Result::AEscaped, $fight->result);
        $this->assertSame('away', $live->fresh()->node_key);
        $this->assertSame(500, $this->character->fresh()->creds, 'no gains');
    }

    public function test_an_opponent_getting_away_goes_to_the_same_screen_and_is_not_a_win(): void
    {
        $this->armed();
        $encounter = $this->ambush($this->npc(), nodes: ['won' => ['outcomes' => [['kind' => 'creds', 'amount' => 100]]]]);
        $live = $this->enter($encounter);
        $this->dice([]);
        $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        // The fight ends with the opponent gone. (The engine's own tests cover how; this is where it leads.)
        $fight = Fight::query()->sole();
        $fight->forceFill(['result' => Result::BEscaped, 'resolved_at' => now()])->save();
        $character = Character::query()->lockForUpdate()->find($this->character->id);
        $character->forceFill(['current_fight_id' => null])->save();
        $this->runner()->afterFight($character, $fight);

        $this->assertSame('away', $live->fresh()->node_key, 'the screen for nobody winning');
        $this->assertSame(500, $this->character->fresh()->creds, 'the win screen is where rewards sit, and this is not it');
    }

    public function test_a_null_key_closes_the_encounter_with_the_fights_ending_for_a_line(): void
    {
        $this->armed();
        $encounter = $this->ambush($this->npc(hp: 5), ['win_key' => null]);
        $live = $this->enter($encounter);
        $this->dice([0.0, 0.5,  0.0, 0.5]);
        $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        $user = $this->character->user->fresh();
        $this->actingAs($user)->post(route('fight.act'), ['action' => 'attack'])
            ->assertRedirect(route('city.index'))->assertSessionHas('status', 'Thug is down. You take '.config('combat.pay.2.min').' ¤ off it.');

        $this->assertNull($this->character->fresh()->current_encounter_id, 'closed');
        $this->assertNull(CharacterEncounter::query()->find($live->id));
    }

    public function test_a_screen_an_editor_removed_in_the_meantime_closes_it_too(): void
    {
        $this->armed();
        $encounter = $this->ambush($this->npc(hp: 5));
        $live = $this->enter($encounter);
        $this->dice([0.0, 0.5,  0.0, 0.5]);
        $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        $encounter->nodes()->where('node_key', 'won')->delete();      // the editor replaced the graph under them

        app(Fights::class)->act($this->character->fresh(), Action::attack());

        $this->assertNull($this->character->fresh()->current_encounter_id);
        $this->assertNull(CharacterEncounter::query()->find($live->id));
    }

    public function test_an_encounter_deleted_mid_fight_does_not_break_the_fight(): void
    {
        $this->armed();
        $encounter = $this->ambush($this->npc(hp: 5));
        $this->enter($encounter);
        $this->dice([0.0, 0.5,  0.0, 0.5]);
        $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        $encounter->delete();                                         // cascades the live row and nulls the pointer

        $fight = app(Fights::class)->act($this->character->fresh(), Action::attack());

        $this->assertInstanceOf(Fight::class, $fight);
        $this->assertSame(Result::BDown, $fight->result);
        $this->assertNull($this->character->fresh()->current_encounter_id);
        $this->assertNull($this->character->fresh()->current_fight_id);
    }

    public function test_a_fight_started_any_other_way_is_not_an_encounters_business(): void
    {
        $this->armed();
        $this->dice([0.0, 0.5,  0.0, 0.5]);
        app(Fights::class)->open($this->character->fresh(), $this->npc(hp: 5));

        $fight = app(Fights::class)->act($this->character->fresh(), Action::attack());

        $this->assertSame(Result::BDown, $fight->result);
        $this->assertNull($fight->encounter_row_id);
    }

    public function test_a_finished_fight_goes_back_to_the_encounter_screen_with_its_flash(): void
    {
        $this->armed();
        $encounter = $this->ambush($this->npc(hp: 5));
        $this->enter($encounter);
        $this->dice([0.0, 0.5,  0.0, 0.5]);
        $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        $this->actingAs($this->character->user->fresh())->post(route('fight.act'), ['action' => 'attack'])
            ->assertRedirect(route('encounter.show'))->assertSessionHas('status', 'Thug is down. You take '.config('combat.pay.2.min').' ¤ off it.');

        $this->actingAs($this->character->user->fresh())->get(route('encounter.show'))
            ->assertOk()->assertSeeText('You win.')->assertSeeText('Fight with Thug');
    }

    public function test_going_down_sends_the_player_to_the_waiting_room_and_not_the_encounter_screen(): void
    {
        $this->armed();
        $this->character->forceFill(['current_hitpoints' => 3])->save();
        $encounter = $this->ambush($this->npc());
        $this->enter($encounter);
        $this->dice([0.0, 0.5]);
        $this->runner()->choose($this->character->fresh(), $this->choiceId($encounter));

        $this->actingAs($this->character->user->fresh())->post(route('fight.act'), ['action' => 'attack'])->assertRedirect(route('city.index'));

        $this->actingAs($this->character->user->fresh())->get(route('city.index'))->assertRedirect();
        $this->assertTrue(app(WaitingRoom::class)->isDown($this->character->fresh()));
    }

    public function test_the_encounter_screen_marks_a_fight_button(): void
    {
        $encounter = $this->ambush($this->npc());
        $this->enter($encounter);

        $this->actingAs($this->character->user)->get(route('encounter.show'))->assertOk()->assertSeeText('Fight');
    }
}
