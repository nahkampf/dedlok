<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Combat\Fight\Action;
use App\Combat\Fight\Arm;
use App\Combat\Fight\Combatant;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\FightState;
use App\Combat\Fight\Range;
use App\Combat\Fight\Result;
use App\Combat\Fight\RoundSpec;
use App\Combat\Fight\Side;
use App\Combat\WeaponClass;
use Tests\Support\MapMitigation;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * Moving the distance and getting away. docs/COMBAT.md, *The fight*. Defence equals pursuit throughout, so
 * every attempt is a coin flip: a die under 0.5 works, a die over it does not, and for Create distance
 * the luckiest 40% of the successes (under 0.2) win two steps rather than one.
 */
final class FightRangeTest extends TestCase
{
    private function knife(): Arm
    {
        return new Arm('Knife', WeaponClass::Blade, 10.0, 1, ['own' => new RoundSpec('own', 'Own', 10.0, ['slashing' => 100])], 'own');
    }

    private function gun(): Arm
    {
        return new Arm('Pistol', WeaponClass::Pistol, 10.0, 1, ['std' => new RoundSpec('std', 'Standard', 10.0, ['penetrating' => 100])], 'std', null, 12);
    }

    /** At Close, both with a knife, the enemy first. */
    private function close(): FightState
    {
        return new FightState(
            new Combatant('You', 40, 10.0, 10.0, $this->gun(), $this->knife(), 30),
            new Combatant('Gang member', 40, 10.0, 10.0, $this->gun(), $this->knife()),
            distance: 0,
        );
    }

    /** The same two with each placed on its own side of the meeting point: `$you` steps out on yours, `$foe` on theirs. */
    private function placed(int $you, int $foe): FightState
    {
        return new FightState($this->close()->a, $this->close()->b, distance: $you + $foe, youAt: $you);
    }

    /** The same two, the enemy first, `$distance` apart (split evenly about the meeting point). */
    private function at(int $distance): FightState
    {
        return new FightState($this->close()->a, $this->close()->b, distance: $distance);
    }

    private function engine(): FightEngine
    {
        return new FightEngine(CheckRoller::fromConfig());
    }

    private function round(FightState $state, array $actions, QueueDice $dice): FightState
    {
        return $this->engine()->round($state, $actions, $dice, new MapMitigation);
    }

    public function test_the_flight_chance_is_defence_against_pursuit_and_the_button_quotes_it(): void
    {
        $this->assertEqualsWithDelta(0.5, $this->engine()->quoteFlight($this->close(), Side::A), 1e-9);

        $hard = new FightState(
            new Combatant('You', 40, 10.0, 10.0),
            new Combatant('Hunter', 40, 10.0, 18.0),
        );
        $this->assertLessThan(0.5, $this->engine()->quoteFlight($hard, Side::A), 'a pursuer that is hard to get away from');
        $this->assertEqualsWithDelta(0.5, $this->engine()->quoteFlight($hard, Side::B), 1e-9, 'the hunter escaping us is a different number');
    }

    public function test_disengaging_ends_the_fight_with_nothing_lost(): void
    {
        $dice = new QueueDice([0.2]);                     // the enemy braces (no die), then A gets away

        $after = $this->round($this->placed(3, 0), ['a' => Action::disengage(), 'b' => Action::brace()], $dice);

        $dice->assertSpent();
        $this->assertSame(Result::AEscaped, $after->result);
        $this->assertNull($after->result->winner());
    }

    public function test_nobody_gets_away_from_within_the_fleeing_distance(): void
    {
        foreach ([0, 1, 2] as $distance) {
            $after = $this->round($this->placed($distance, 0), ['a' => Action::disengage(), 'b' => Action::brace()], new QueueDice([]));

            $this->assertNull($after->result, "at $distance");
            $this->assertSame('You is too close to get away and braces.', $after->log[1]['text']);
        }
    }

    public function test_a_failed_disengage_costs_the_turn_and_the_other_side_still_acts(): void
    {
        $dice = new QueueDice([0.9,  0.0, 0.5]);          // A fails to get away; B (second now) shoots

        $state = new FightState($this->close()->a, $this->close()->b, distance: 3, first: Side::A, youAt: 3);
        $after = $this->round($state, ['a' => Action::disengage(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertNull($after->result);
        $this->assertSame(30, $after->a->hp);
        $this->assertSame('You tries to get away and cannot.', $after->log[0]['text']);
    }

    public function test_getting_away_mid_round_stops_the_other_side_from_acting(): void
    {
        $dice = new QueueDice([0.2]);                     // only A's attempt is rolled; B never gets a turn

        $state = new FightState($this->close()->a, $this->close()->b, distance: 3, first: Side::A, youAt: 3);
        $after = $this->round($state, ['a' => Action::disengage(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(Result::AEscaped, $after->result);
        $this->assertSame(40, $after->a->hp);
    }

    public function test_a_lucky_success_wins_two_steps_at_once(): void
    {
        // The enemy braces, then the player breaks away. 0.1 is inside the luckiest 40% of the 50% that work.
        $one = $this->round($this->at(0), ['a' => Action::createDistance(), 'b' => Action::brace()], new QueueDice([0.1]));

        $this->assertSame(2, $one->distance);
        $this->assertSame(Range::Ranged, $one->range);
        $this->assertSame('You breaks away cleanly and wins real distance.', $one->log[1]['text']);
    }

    public function test_an_ordinary_success_wins_one_and_a_failure_none(): void
    {
        $one = $this->round($this->at(0), ['a' => Action::createDistance(), 'b' => Action::brace()], new QueueDice([0.3]));
        $this->assertSame(1, $one->distance);
        $this->assertSame('You breaks away and wins a little space.', $one->log[1]['text']);

        $none = $this->round($this->at(0), ['a' => Action::createDistance(), 'b' => Action::brace()], new QueueDice([0.9]));
        $this->assertSame(0, $none->distance);
        $this->assertSame('You tries to open the range and cannot.', $none->log[1]['text']);
    }

    public function test_nothing_moves_the_distance_but_an_action(): void
    {
        $state = $this->at(2);

        for ($i = 0; $i < 3; $i++) {
            $state = $this->round($state, ['a' => Action::attack(), 'b' => Action::attack()], new QueueDice([0.0, 0.5,  0.0, 0.5]));
        }

        $this->assertSame(2, $state->distance, 'three rounds of shooting and nobody has moved');
        $this->assertSame(4, $state->round);
    }

    public function test_a_player_may_keep_adding_distance_up_to_the_maximum(): void
    {
        $state = $this->at(2);
        $this->assertFalse($this->engine()->legal($state, Side::A, Action::disengage()), 'not yet');

        $state = $this->round($state, ['a' => Action::createDistance(), 'b' => Action::brace()], new QueueDice([0.1]));
        $this->assertSame(4, $state->distance);
        $this->assertTrue($this->engine()->legal($state, Side::A, Action::disengage()), 'beyond two, running is possible');

        // Three steps out from the meeting point is as far as one side can go: only the gap the other side adds goes further.
        $this->assertSame(3, $state->offset(Side::A));
        $this->assertFalse($this->engine()->legal($state, Side::A, Action::createDistance()), 'no further out than the edge');

        $capped = $this->round($this->at(5), ['a' => Action::createDistance(), 'b' => Action::brace()], new QueueDice([0.1]));
        $this->assertSame(6, $capped->distance, 'a gain of two from two steps out stops at the edge, three each side');
    }

    public function test_closing_in_wins_one_step_on_a_success(): void
    {
        $one = $this->round($this->at(2), ['a' => Action::closeIn(), 'b' => Action::brace()], new QueueDice([0.3]));
        $this->assertSame(1, $one->distance);
        $this->assertSame('You closes in.', $one->log[1]['text']);

        $failed = $this->round($this->at(2), ['a' => Action::closeIn(), 'b' => Action::brace()], new QueueDice([0.9]));
        $this->assertSame(2, $failed->distance);
        $this->assertSame('You tries to close in and cannot.', $failed->log[1]['text']);

        $hard = new FightState(new Combatant('You', 40, 10.0, 10.0), new Combatant('Runner', 40, 18.0, 10.0));
        $this->assertLessThan(0.5, $this->engine()->quoteClosing($hard, Side::A), 'a target with better defence is harder to close on');
    }

    public function test_there_is_nothing_to_close_in_on_at_arms_reach(): void
    {
        $after = $this->round($this->at(0), ['a' => Action::closeIn(), 'b' => Action::brace()], new QueueDice([]));

        $this->assertSame('You is already in reach and braces.', $after->log[1]['text']);
    }

    public function test_a_flight_in_flight_survives_being_stored(): void
    {
        $one = $this->round($this->at(0), ['a' => Action::createDistance(), 'b' => Action::brace()], new QueueDice([0.1]));
        $rebuilt = FightState::fromArray(json_decode((string) json_encode($one->toArray()), true));

        $this->assertEquals($one, $rebuilt);
    }

    public function test_a_fight_stored_before_distance_was_a_number_still_opens(): void
    {
        $data = $this->close()->toArray();
        unset($data['distance']);

        $this->assertSame(0, FightState::fromArray($data + ['range' => 'close'])->distance);
        $this->assertSame(2, FightState::fromArray($data + ['range' => 'ranged'])->distance);
    }

    // ---- two positions about a meeting point ---------------------------------------------------

    public function test_a_step_in_and_a_step_out_cancel_instead_of_drifting(): void
    {
        $state = $this->placed(0, 2);

        // You close one step: standing at the meeting point already, the enemy is pulled in. Then they back off one.
        $state = $this->round($state, ['a' => Action::closeIn(), 'b' => Action::brace()], new QueueDice([0.3]));
        $this->assertSame([0, 1], [$state->offset(Side::A), $state->offset(Side::B)]);

        $state = $this->round($state, ['a' => Action::brace(), 'b' => Action::createDistance()], new QueueDice([0.3]));
        $this->assertSame([0, 2], [$state->offset(Side::A), $state->offset(Side::B)], 'back at 0 and 2, not 1 and 3');
    }

    public function test_each_side_escapes_by_its_own_distance(): void
    {
        $edgy = new Combatant('Gang member', 40, 10.0, 10.0, $this->gun(), $this->knife(), fleeBeyond: 1);
        $state = new FightState($this->close()->a, $edgy, distance: 2, youAt: 0);   // you at the meeting point, it two steps out

        $this->assertTrue($this->engine()->legal($state, Side::B, Action::disengage()), 'it may go from more than one step out');
        $this->assertFalse($this->engine()->legal($state, Side::A, Action::disengage()), 'you are at the meeting point');
        $this->assertFalse($this->engine()->legal($this->placed(0, 2), Side::B, Action::disengage()), 'the default is more than two');
    }

    public function test_the_positions_survive_being_stored(): void
    {
        $edgy = new Combatant('Gang member', 40, 10.0, 10.0, $this->gun(), $this->knife(), fleeBeyond: 1);
        $state = new FightState($this->close()->a, $edgy, distance: 3, youAt: 2);

        $rebuilt = FightState::fromArray(json_decode((string) json_encode($state->toArray()), true));

        $this->assertSame([2, 1], [$rebuilt->offset(Side::A), $rebuilt->offset(Side::B)]);
        $this->assertSame(1, $rebuilt->b->escapeBeyond());
    }
}
