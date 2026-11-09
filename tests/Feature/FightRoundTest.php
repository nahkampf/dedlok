<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Combat\Fight\Action;
use App\Combat\Fight\Arm;
use App\Combat\Fight\Combatant;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\FightState;
use App\Combat\Fight\Initiative;
use App\Combat\Fight\Range;
use App\Combat\Fight\Result;
use App\Combat\Fight\RoundSpec;
use App\Combat\Fight\Side;
use App\Combat\WeaponClass;
use Tests\Support\MapMitigation;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * A whole round: whose turn, in what order, when it ends, and when the range moves on.
 * docs/COMBAT.md, *The fight*. Aim equals defence throughout, so a shot is a coin flip; a die of 0.0
 * is Dead-on for the whole 10 and 0.5 puts it on the right leg.
 */
final class FightRoundTest extends TestCase
{
    private function gun(?int $rounds = 12, string $name = 'Pistol'): Arm
    {
        return new Arm($name, WeaponClass::Pistol, 10.0, 1, ['std' => new RoundSpec('std', 'Standard', 10.0, ['penetrating' => 100])], 'std', $rounds, 12);
    }

    private function knife(string $name = 'Knife'): Arm
    {
        return new Arm($name, WeaponClass::Blade, 10.0, 1, ['own' => new RoundSpec('own', 'Own', 10.0, ['slashing' => 100])], 'own');
    }

    private function state(?Arm $aRanged, ?Arm $bRanged, int $aHp = 40, int $bHp = 40, ?Arm $aMelee = null, ?Arm $bMelee = null, Side $first = Side::B): FightState
    {
        return new FightState(
            new Combatant('You', $aHp, 10.0, ranged: $aRanged, melee: $aMelee, si: 30),
            new Combatant('Gang member', $bHp, 10.0, ranged: $bRanged, melee: $bMelee),
            first: $first,
        );
    }

    private function engine(): FightEngine
    {
        return new FightEngine(CheckRoller::fromConfig());
    }

    private function round(FightState $state, array $actions, QueueDice $dice): FightState
    {
        return $this->engine()->round($state, $actions, $dice, new MapMitigation);
    }

    public function test_in_an_encounter_the_enemy_acts_first_and_then_the_player(): void
    {
        $dice = new QueueDice([0.0, 0.5,  0.0, 0.5]);    // B's shot, then A's

        $after = $this->round($this->state($this->gun(), $this->gun(name: 'Pistol')), ['a' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(30, $after->a->hp);
        $this->assertSame(30, $after->b->hp);
        $this->assertStringStartsWith('Gang member:', $after->log[0]['text'], 'the enemy fired first');
        $this->assertStringStartsWith('You:', $after->log[1]['text']);
    }

    public function test_the_first_side_is_whoever_the_fight_was_opened_with(): void
    {
        $dice = new QueueDice([0.0, 0.5,  0.0, 0.5]);

        $after = $this->round($this->state($this->gun(), $this->gun(), first: Side::A), ['a' => Action::attack()], $dice);

        $this->assertStringStartsWith('You:', $after->log[0]['text']);
    }

    public function test_a_round_is_both_turns_and_leaves_the_distance_alone(): void
    {
        $after = $this->round($this->state($this->gun(), $this->gun()), ['a' => Action::attack()], new QueueDice([0.0, 0.5,  0.0, 0.5]));

        $this->assertSame(2, $after->round, 'the next round');
        $this->assertSame(2, $after->distance, 'only an action moves it');
        $this->assertSame(Range::Ranged, $after->range);
        $this->assertNull($after->result);
        $this->assertSame([1, 1], array_column($after->log, 'round'), 'every line is tagged with the round it happened in');
    }

    public function test_at_close_only_a_melee_weapon_is_used(): void
    {
        $state = $this->state($this->gun(), $this->gun(), aMelee: $this->knife('Katana'), bMelee: $this->knife('Shiv'))->withDistance(0);
        $this->assertSame(Range::Close, $state->range);

        $dice = new QueueDice([0.0, 0.5,  0.0, 0.5]);
        $after = $this->round($state, ['a' => Action::attack(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(2, count(array_filter($after->log, fn (array $l): bool => $l['round'] === 1)));
        $this->assertSame(12, $after->a->ranged->rounds, 'the pistol was not fired at Close');
        $this->assertStringContainsString('slashing', $after->log[count($after->log) - 1]['text']);
    }

    public function test_a_player_who_goes_down_ends_the_round_before_they_act(): void
    {
        $dice = new QueueDice([0.0, 0.5]);                // only the enemy's shot is rolled

        $after = $this->round($this->state($this->gun(), $this->gun(), aHp: 10), ['a' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(Result::ADown, $after->result);
        $this->assertSame(Side::B, $after->result->winner());
        $this->assertSame(40, $after->b->hp, 'the player never got their turn');
        $this->assertSame(1, $after->round, 'a finished fight does not move on');
        $this->assertStringContainsString('You goes down.', $after->log[array_key_last($after->log)]['text']);
    }

    public function test_an_opponent_who_goes_down_ends_the_fight_with_the_player_still_standing(): void
    {
        $after = $this->round($this->state($this->gun(), $this->gun(), bHp: 10), ['a' => Action::attack()], new QueueDice([0.0, 0.5,  0.0, 0.5]));

        $this->assertSame(Result::BDown, $after->result);
        $this->assertSame(Side::A, $after->result->winner());
        $this->assertSame(30, $after->a->hp);
    }

    public function test_the_floor_is_either_resource(): void
    {
        $state = $this->state($this->gun(), $this->gun());
        $state = $state->with(Side::A, $state->a->with(si: 0));

        $after = $this->round($state, ['a' => Action::attack()], new QueueDice([0.0, 0.5]));

        $this->assertSame(Result::ADown, $after->result, 'a mind at zero is down as surely as a body');
    }

    public function test_a_side_with_only_a_knife_tries_to_close_in_rather_than_stand_there(): void
    {
        // The enemy has only a knife, and the fight opens two steps apart.
        $dice = new QueueDice([0.9,  0.0, 0.5]);          // it fails to close; the player shoots it

        $after = $this->round($this->state($this->gun(), null, bMelee: $this->knife()), ['a' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame('Gang member tries to close in and cannot.', $after->log[0]['text']);
        $this->assertSame(30, $after->b->hp);
        $this->assertSame(2, $after->distance);
    }

    public function test_who_closes_in_and_who_holds_range_is_whichever_they_are_better_at(): void
    {
        $engine = $this->engine();
        $hands = new Arm('Fists', WeaponClass::Unarmed, 10.0, 1, ['own' => new RoundSpec('own', 'Fists', 2.0, ['blunt' => 100])], 'own');
        $knife = new Arm('Machete', WeaponClass::Blade, 10.0, 1, ['own' => new RoundSpec('own', 'Own', 15.0, ['slashing' => 100])], 'own');
        $at = fn (int $d, ?Arm $melee) => (new FightState(new Combatant('You', 40, 10.0, ranged: $this->gun(), si: 30), new Combatant('Gang member', 40, 10.0, ranged: $this->gun(), melee: $melee), distance: $d, first: Side::B));

        $this->assertSame('attack', $engine->choose($at(2, $hands), Side::B)->kind->value, 'a gun beats fists: stays at range and shoots');
        $this->assertSame('create_distance', $engine->choose($at(0, $hands), Side::B)->kind->value, 'and backs off when closed on');
        $this->assertSame('close_in', $engine->choose($at(2, $knife), Side::B)->kind->value, 'a blade that beats the gun comes in');
        $this->assertSame('attack', $engine->choose($at(0, $knife), Side::B)->kind->value, 'and stabs once there');
    }

    public function test_an_illegal_attack_becomes_a_brace_and_says_so(): void
    {
        $dice = new QueueDice([0.0, 0.5]);                // only the enemy fires

        $after = $this->round($this->state($this->gun(rounds: 0), $this->gun()), ['a' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(30, $after->a->hp);
        $this->assertSame(40, $after->b->hp);
        $this->assertSame('You has nothing to attack with and braces.', $after->log[1]['text']);
    }

    public function test_a_chosen_brace_is_always_legal(): void
    {
        $after = $this->round($this->state($this->gun(), $this->gun()), ['a' => Action::brace()], new QueueDice([0.0, 0.5]));

        $this->assertSame('You braces.', $after->log[array_key_last($after->log)]['text']);
    }

    public function test_a_finished_fight_cannot_be_played_further(): void
    {
        $over = $this->round($this->state($this->gun(), $this->gun(), aHp: 10), ['a' => Action::attack()], new QueueDice([0.0, 0.5]));

        $this->expectException(\LogicException::class);
        $this->round($over, ['a' => Action::attack()], new QueueDice([]));
    }

    public function test_a_state_with_its_order_and_result_survives_being_stored(): void
    {
        $over = $this->round($this->state($this->gun(), $this->gun(), aHp: 10), ['a' => Action::attack()], new QueueDice([0.0, 0.5]));

        $rebuilt = FightState::fromArray(json_decode((string) json_encode($over->toArray()), true));

        $this->assertEquals($over, $rebuilt);
        $this->assertSame(Result::ADown, $rebuilt->result);
        $this->assertSame(Side::B, $rebuilt->first);
    }

    // ---- fleeing -------------------------------------------------------------------------

    private function wounded(float $fleesAt, int $hp, int $distance = 3): FightState
    {
        $state = (new FightState(new Combatant('You', 40, 10.0, ranged: $this->gun(), si: 30), new Combatant('Gang member', 40, 10.0), distance: $distance, first: Side::B, youAt: 0));   // the enemy stands the whole gap out from the meeting point

        return $state->with(Side::B, new Combatant('Gang member', $hp, 10.0, 10.0, $this->gun(), null, null, [], 40, $fleesAt));
    }

    public function test_an_npc_hurt_past_its_limit_tries_to_get_away_instead_of_fighting(): void
    {
        $engine = $this->engine();

        $this->assertSame('disengage', $engine->choose($this->wounded(0.5, 15), Side::B)->kind->value, '15 of 40 is under half, and far enough off to run');
        $this->assertSame('create_distance', $engine->choose($this->wounded(0.5, 15, distance: 2), Side::B)->kind->value, 'nobody runs from within two: back off first');
        $this->assertSame('attack', $engine->choose($this->wounded(0.5, 25), Side::B)->kind->value, '25 of 40 is not');
        $this->assertSame('attack', $engine->choose($this->wounded(0.0, 1), Side::B)->kind->value, 'a limit of nothing never flees');
        $this->assertSame('attack', $engine->choose($this->state($this->gun(), $this->gun()), Side::B)->kind->value, 'no limit at all');
    }

    public function test_a_successful_escape_ends_the_fight_with_no_winner(): void
    {
        $dice = new QueueDice([0.2]);                     // the flight roll; the player never acts

        $after = $this->round($this->wounded(0.5, 15), ['a' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(Result::BEscaped, $after->result);
        $this->assertNull($after->result->winner(), 'getting away is not losing');
        $this->assertSame(Side::B, $after->result->escaped());
        $this->assertSame(15, $after->b->hp, 'untouched');
        $this->assertSame(40, $after->a->hp);
    }

    public function test_a_failed_escape_costs_the_npc_its_turn_and_the_player_goes_on(): void
    {
        $dice = new QueueDice([0.9,  0.0, 0.5]);          // the NPC fails to leave; the player shoots it

        $after = $this->round($this->wounded(0.5, 15), ['a' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertNull($after->result);
        $this->assertSame(5, $after->b->hp);
        $this->assertSame(40, $after->a->hp, 'it did not shoot back');
        $this->assertSame('Gang member tries to get away and cannot.', $after->log[0]['text']);
    }

    public function test_the_flight_limit_survives_being_stored(): void
    {
        $state = $this->wounded(0.5, 15);

        $this->assertEquals($state, FightState::fromArray(json_decode((string) json_encode($state->toArray()), true)));
        $this->assertTrue(FightState::fromArray($state->toArray())->b->wantsOut());
    }

    // ---- initiative ---------------------------------------------------------------------

    public function test_the_higher_score_acts_first_and_a_tie_is_a_coin_toss(): void
    {
        $never = new QueueDice([]);

        $this->assertSame(Side::A, Initiative::highest(30.0, 25.0, $never), 'no die needed');
        $this->assertSame(Side::B, Initiative::highest(25.0, 30.0, $never));

        $this->assertSame(Side::A, Initiative::highest(30.0, 30.0, new QueueDice([0.2])));
        $this->assertSame(Side::B, Initiative::highest(30.0, 30.0, new QueueDice([0.7])));
    }
}
