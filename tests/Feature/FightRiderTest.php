<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Combat\Fight\Action;
use App\Combat\Fight\Arm;
use App\Combat\Fight\Combatant;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\FightState;
use App\Combat\Fight\Result;
use App\Combat\Fight\RoundSpec;
use App\Combat\Fight\Side;
use App\Combat\RiderKind;
use App\Combat\WeaponClass;
use Tests\Support\MapMitigation;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * Riders in a fight. docs/COMBAT.md, *Riders*. Aim equals defence, so a shot is a coin flip: a die of 0.0
 * is Dead-on, 0.2 a Clean hit and 0.5 only Partial. The rider die is rolled after the tier and the
 * place, and a rider with chance 50 lands under 0.5.
 */
final class FightRiderTest extends TestCase
{
    /** @param  list<array{kind: string, chance: int, turns: int, amount: int}>  $riders */
    private function gun(array $riders, ?int $rate = 1): Arm
    {
        return new Arm('Pistol', WeaponClass::Pistol, 10.0, $rate, ['s' => new RoundSpec('s', 'S', 10.0, ['penetrating' => 100], 0, $riders)], 's', null, 12);
    }

    private function plain(): Arm
    {
        return new Arm('Pistol', WeaponClass::Pistol, 10.0, 1, ['s' => new RoundSpec('s', 'S', 4.0, ['penetrating' => 100])], 's');
    }

    /** @param  list<array{kind: string, chance: int, turns: int, amount: int}>  $riders */
    private function knife(array $riders = [], float $damage = 4.0): Arm
    {
        return new Arm('Knife', WeaponClass::Blade, 10.0, 1, ['s' => new RoundSpec('s', 'S', $damage, ['slashing' => 100], 0, $riders)], 's');
    }

    private function burn(int $chance = 100, int $turns = 3, int $amount = 2): array
    {
        return ['kind' => 'burning', 'chance' => $chance, 'turns' => $turns, 'amount' => $amount];
    }

    private function stun(int $chance = 100, int $turns = 1): array
    {
        return ['kind' => 'stun', 'chance' => $chance, 'turns' => $turns, 'amount' => 0];
    }

    /** The player shoots first; the foe has a weak gun of its own. */
    private function state(Arm $mine, int $foeHp = 100, ?Arm $theirs = null, ?Arm $mineClose = null, ?Arm $theirsClose = null): FightState
    {
        return new FightState(
            new Combatant('You', 100, 10.0, ranged: $mine, melee: $mineClose, si: 30),
            new Combatant('Gang member', $foeHp, 10.0, ranged: $theirs ?? $this->plain(), melee: $theirsClose),
            first: Side::A,
        );
    }

    private function engine(): FightEngine
    {
        return new FightEngine(CheckRoller::fromConfig());
    }

    private function round(FightState $state, array $actions, QueueDice $dice, ?MapMitigation $m = null): FightState
    {
        return $this->engine()->round($state, $actions, $dice, $m ?? new MapMitigation);
    }

    public function test_a_clean_hit_can_leave_a_rider_and_the_die_comes_after_the_tier_and_the_place(): void
    {
        $dice = new QueueDice([0.2, 0.5, 0.3,   0.0, 0.5]);   // Clean hit, right leg, rider 30 < 50; then the foe's own shot (Dead-on)

        $after = $this->round($this->state($this->gun([$this->burn(chance: 50)])), ['a' => Action::attack(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(['burning'], array_column($after->b->statuses, 'kind'));
        // Applied on the player's turn, so it is already burning when the foe's own turn begins that
        // same round: a tick has landed and a turn is spent.
        $this->assertSame(2, $after->b->statuses[0]['turns']);
        $this->assertSame(2, $after->b->statuses[0]['amount']);
        $this->assertStringContainsString('Gang member is Burning for 3.', $after->log[1]['text']);
    }

    public function test_a_rider_that_fails_its_chance_is_not_applied(): void
    {
        $after = $this->round($this->state($this->gun([$this->burn(chance: 50)])), ['a' => Action::attack(), 'b' => Action::attack()], new QueueDice([0.2, 0.5, 0.9,   0.0, 0.5]));

        $this->assertSame([], $after->b->statuses);
    }

    public function test_a_weaker_hit_does_not_even_roll_for_a_rider(): void
    {
        $dice = new QueueDice([0.5, 0.5,   0.0, 0.5]);        // Partial: no rider die; then the foe's shot

        $after = $this->round($this->state($this->gun([$this->burn()])), ['a' => Action::attack(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame([], $after->b->statuses);
    }

    public function test_dead_on_rolls_too_and_each_rider_has_its_own_die(): void
    {
        $dice = new QueueDice([0.0, 0.5, 0.1, 0.1]);          // the foe is stunned and rolls nothing

        $after = $this->round($this->state($this->gun([$this->burn(chance: 50), $this->stun(chance: 50, turns: 2)])), ['a' => Action::attack(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertEqualsCanonicalizing(['burning', 'stun'], array_column($after->b->statuses, 'kind'));
    }

    public function test_a_burst_rolls_riders_for_every_shot_that_qualifies(): void
    {
        // Three shots: Dead-on (+ rider), Partial (no rider die), Clean (+ rider). Then the foe.
        $dice = new QueueDice([0.0, 0.5, 0.1,  0.5, 0.5,  0.2, 0.5, 0.1,   0.0, 0.5]);

        $after = $this->round($this->state($this->gun([$this->burn(chance: 50)], rate: 3)), ['a' => Action::attack(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(['burning'], array_column($after->b->statuses, 'kind'), 'one status, refreshed');
    }

    public function test_a_rider_is_not_rolled_for_a_target_the_shot_has_put_down(): void
    {
        $dice = new QueueDice([0.0, 0.5]);                    // Dead-on kills outright: no rider die follows

        $after = $this->round($this->state($this->gun([$this->burn()]), foeHp: 5), ['a' => Action::attack(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(Result::BDown, $after->result);
    }

    // ---- ticks --------------------------------------------------------------------------

    public function test_burning_hits_at_the_start_of_each_of_its_turns_for_its_duration_and_then_stops(): void
    {
        $state = $this->state($this->plain());
        $state = $state->with(Side::B, $state->b->afflicted(RiderKind::Burning, 3, 2));
        $mitigation = new MapMitigation;
        $dice = new QueueDice([0.99, 0.5, 0.99, 0.5,  0.99, 0.5, 0.99, 0.5,  0.99, 0.5, 0.99, 0.5,  0.99, 0.5, 0.99, 0.5]);   // four rounds of near misses
        $hp = [];

        for ($i = 0; $i < 4; $i++) {
            $state = $this->round($state, ['a' => Action::brace(), 'b' => Action::brace()], new QueueDice([]), $mitigation);
            $hp[] = $state->b->hp;
        }

        $this->assertSame([98, 96, 94, 94], $hp, 'three ticks of 2, then it has worn off');
        $this->assertSame([], $state->b->statuses);
        $this->assertNull($mitigation->seen[0]['damage']->location, 'a burn is not a blow: no body part');
        $this->assertSame(0, $mitigation->seen[0]['damage']->penetration);
        $this->assertSame('thermal', $mitigation->seen[0]['damage']->origin->value);
    }

    public function test_poison_is_chemical_and_resisted_as_such(): void
    {
        $state = $this->state($this->plain());
        $state = $state->with(Side::B, $state->b->afflicted(RiderKind::Poisoned, 2, 10));

        $after = $this->round($state, ['a' => Action::brace(), 'b' => Action::brace()], new QueueDice([]), new MapMitigation(['b' => ['chemical' => 50]]));

        $this->assertSame(95, $after->b->hp);
        $this->assertSame('Gang member takes 5 from Poisoned.', $after->log[1]['text']);
    }

    public function test_reapplying_refreshes_to_the_longer_and_the_larger_and_never_the_weaker(): void
    {
        $c = (new Combatant('X', 10, 0.0))->afflicted(RiderKind::Burning, 3, 2);

        $this->assertSame(['kind' => 'burning', 'turns' => 3, 'amount' => 5], $c->afflicted(RiderKind::Burning, 1, 5)->statuses[0]);
        $this->assertSame(['kind' => 'burning', 'turns' => 6, 'amount' => 2], $c->afflicted(RiderKind::Burning, 6, 1)->statuses[0]);
        $this->assertCount(1, $c->afflicted(RiderKind::Burning, 6, 1)->statuses, 'one of a kind');
        $this->assertCount(2, $c->afflicted(RiderKind::Poisoned, 2, 1)->statuses, 'different kinds sit together');
    }

    public function test_a_tick_that_downs_a_side_ends_the_fight_before_it_acts(): void
    {
        $state = $this->state($this->plain(), foeHp: 2);
        $state = $state->with(Side::B, $state->b->afflicted(RiderKind::Burning, 3, 5));

        $dice = new QueueDice([0.0, 0.5]);                    // only the player's shot; the foe never acts

        $after = $this->round($state, ['a' => Action::attack(), 'b' => Action::attack()], $dice);

        // The player is first and downs the foe with the shot itself, before any tick.
        $this->assertSame(Result::BDown, $after->result);

        // Now with the foe first, so its own burn kills it at the start of its turn.
        $first = new FightState($state->a, $state->b, first: Side::B);
        $dice = new QueueDice([]);
        $after = $this->round($first, ['a' => Action::attack(), 'b' => Action::attack()], $dice);

        $dice->assertSpent();
        $this->assertSame(Result::BDown, $after->result);
        $this->assertSame(100, $after->a->hp, 'it never got its action');
    }

    // ---- stun ---------------------------------------------------------------------------

    public function test_a_stunned_side_loses_its_action_for_the_turns_it_lasts(): void
    {
        $state = $this->state($this->plain(), theirsClose: $this->knife());
        $state = $state->with(Side::B, $state->b->afflicted(RiderKind::Stun, 2, 0));

        $dice = new QueueDice([0.0, 0.5]);                    // only the player's shot in round 1
        $one = $this->round($state, ['a' => Action::attack(), 'b' => Action::attack()], $dice);
        $dice->assertSpent();
        $this->assertSame(100, $one->a->hp, 'stunned: no shot back');
        $this->assertSame('Gang member is stunned and loses the turn.', $one->log[1]['text']);

        $two = $this->round($one, ['a' => Action::brace(), 'b' => Action::attack()], new QueueDice([]));
        $this->assertSame(100, $two->a->hp, 'still stunned');

        $three = $this->round($two, ['a' => Action::brace(), 'b' => Action::attack()], new QueueDice([0.0, 0.5]));
        $this->assertSame(96, $three->a->hp, 'two turns later it shakes it off and shoots');
    }

    public function test_a_stun_does_not_stop_a_side_being_stunned_again_so_it_can_be_locked(): void
    {
        // The player stuns every round (chance 100): the foe never acts across three rounds.
        $state = $this->state($this->gun([$this->stun()]), mineClose: $this->knife([$this->stun()], damage: 10.0), theirsClose: $this->knife());

        for ($i = 0; $i < 3; $i++) {
            $state = $this->round($state, ['a' => Action::attack(), 'b' => Action::attack()], new QueueDice([0.0, 0.5, 0.0]));
            $this->assertSame(100, $state->a->hp, "round {$i}: the foe was stunned");
        }

        $this->assertSame(70, $state->b->hp, 'and takes ten a round while it is');
    }

    public function test_statuses_survive_being_stored(): void
    {
        $state = $this->state($this->plain());
        $state = $state->with(Side::B, $state->b->afflicted(RiderKind::Burning, 3, 2)->afflicted(RiderKind::Stun, 1, 0));

        $rebuilt = FightState::fromArray(json_decode((string) json_encode($state->toArray()), true));

        $this->assertEquals($state, $rebuilt);
        $this->assertCount(2, $rebuilt->b->statuses);
    }
}
