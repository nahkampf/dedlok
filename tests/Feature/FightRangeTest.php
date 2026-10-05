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
 * Getting away and opening the range. COMBAT.md, *The fight*. Defence equals pursuit throughout, so
 * either attempt is a coin flip: a die under 0.5 works, a die over it does not.
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
            range: Range::Close,
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

        $after = $this->round($this->close(), ['a' => Action::disengage(), 'b' => Action::brace()], $dice);

        $dice->assertSpent();
        $this->assertSame(Result::AEscaped, $after->result);
        $this->assertNull($after->result->winner());
    }

    public function test_a_failed_disengage_costs_the_turn_and_the_other_side_still_acts(): void
    {
        $dice = new QueueDice([0.9,  0.0, 0.5]);          // A fails to get away; B (second now) shoots

        $state = new FightState($this->close()->a, $this->close()->b, range: Range::Close, first: Side::A);
        $after = $this->round($state, ['a' => Action::disengage(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertNull($after->result);
        $this->assertSame(30, $after->a->hp);
        $this->assertSame('You tries to get away and cannot.', $after->log[0]['text']);
    }

    public function test_getting_away_mid_round_stops_the_other_side_from_acting(): void
    {
        $dice = new QueueDice([0.2]);                     // only A's attempt is rolled; B never gets a turn

        $state = new FightState($this->close()->a, $this->close()->b, range: Range::Close, first: Side::A);
        $after = $this->round($state, ['a' => Action::disengage(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(Result::AEscaped, $after->result);
        $this->assertSame(40, $after->a->hp);
    }

    public function test_disengaging_is_offered_at_ranged_too(): void
    {
        $state = new FightState($this->close()->a, $this->close()->b, first: Side::A);   // Ranged

        $after = $this->round($state, ['a' => Action::disengage()], new QueueDice([0.2]));

        $this->assertSame(Result::AEscaped, $after->result);
    }

    public function test_creating_distance_puts_the_next_round_at_ranged_and_then_it_closes_again(): void
    {
        // Round 1 at Close: enemy braces, player breaks away.
        $one = $this->round($this->close(), ['a' => Action::createDistance(), 'b' => Action::brace()], new QueueDice([0.2]));
        $this->assertSame(Range::Ranged, $one->range, 'the next round opens at Ranged');
        $this->assertSame(2, $one->round);
        $this->assertSame(Range::Ranged, $one->range);

        // Round 2 at Ranged: both shoot; then it closes.
        $two = $this->round($one, ['a' => Action::attack(), 'b' => Action::attack()], new QueueDice([0.0, 0.5,  0.0, 0.5]));
        $this->assertSame(Range::Close, $two->range, 'one round at range, and then it closes again');
        $this->assertSame(3, $two->round);
        $this->assertSame(30, $two->a->hp);
    }

    public function test_a_failed_attempt_to_open_the_range_leaves_it_closed(): void
    {
        $after = $this->round($this->close(), ['a' => Action::createDistance(), 'b' => Action::brace()], new QueueDice([0.9]));

        $this->assertSame(Range::Close, $after->range);
        $this->assertSame('You tries to open the range and cannot.', $after->log[1]['text']);
    }

    public function test_creating_distance_is_only_for_when_you_are_close(): void
    {
        $state = new FightState($this->close()->a, $this->close()->b, first: Side::A);   // at Ranged
        $dice = new QueueDice([]);

        $after = $this->round($state, ['a' => Action::createDistance(), 'b' => Action::brace()], $dice);

        $this->assertSame('You is already at range and braces.', $after->log[0]['text']);
        $this->assertSame(Range::Close, $after->range, 'and the round went by as ever');
    }

    public function test_a_flight_in_flight_survives_being_stored(): void
    {
        $one = $this->round($this->close(), ['a' => Action::createDistance(), 'b' => Action::brace()], new QueueDice([0.2]));
        $rebuilt = FightState::fromArray(json_decode((string) json_encode($one->toArray()), true));

        $this->assertEquals($one, $rebuilt);
    }
}
