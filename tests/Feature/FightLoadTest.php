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
use App\Combat\Fight\RoundSpec;
use App\Combat\Fight\Side;
use App\Combat\WeaponClass;
use Tests\Support\MapMitigation;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * Magazines in a fight. COMBAT.md, *Actions*. Reloading an EMPTY magazine, or switching to a
 * different round at any time, each costs the whole turn; a part-full magazine of the same round is
 * not topped up. Rounds are counted for the player and never for an NPC.
 */
final class FightLoadTest extends TestCase
{
    /** A 12-round pistol with 40 standard and 5 hollow points in the stash. */
    private function pistol(?string $loaded = 'fmj', ?int $rounds = 12, int $fmj = 40, int $hollow = 5): Arm
    {
        return new Arm('Pistol', WeaponClass::Pistol, 10.0, 1, [
            'fmj' => new RoundSpec('fmj', '9mm FMJ', 10.0, ['penetrating' => 100], 0, [], $fmj),
            'hollow' => new RoundSpec('hollow', '9mm Hollow point', 12.0, ['penetrating' => 100], -50, [], $hollow),
        ], $loaded, $rounds, 12);
    }

    private function state(Arm $arm, Range $range = Range::Ranged): FightState
    {
        return new FightState(
            new Combatant('You', 40, 10.0, ranged: $arm, si: 30),
            new Combatant('Gang member', 40, 10.0, ranged: new Arm('Gun', WeaponClass::Pistol, 10.0, 1, ['s' => new RoundSpec('s', 'S', 10.0, ['penetrating' => 100])], 's')),
            range: $range,
            first: Side::A,
        );
    }

    private function play(FightState $state, Action $action, array $dice = [0.0, 0.5]): FightState
    {
        return (new FightEngine(CheckRoller::fromConfig()))->round($state, ['a' => $action], new QueueDice($dice), new MapMitigation);
    }

    public function test_an_empty_magazine_is_reloaded_for_a_whole_turn(): void
    {
        $after = $this->play($this->state($this->pistol(rounds: 0)), Action::load('fmj'));

        $arm = $after->a->ranged;
        $this->assertSame(12, $arm->rounds);
        $this->assertSame('fmj', $arm->loaded);
        $this->assertSame(28, $arm->specs['fmj']->stock, 'a magazine of twelve came off the pile');
        $this->assertSame('You loads 9mm FMJ (12).', $after->log[0]['text']);
        $this->assertSame(40, $after->b->hp, 'the turn went on loading: nothing was shot');
    }

    public function test_a_short_stash_loads_what_there_is(): void
    {
        $after = $this->play($this->state($this->pistol(rounds: 0, fmj: 7)), Action::load('fmj'));

        $this->assertSame(7, $after->a->ranged->rounds);
        $this->assertSame(0, $after->a->ranged->specs['fmj']->stock);
    }

    public function test_a_part_full_magazine_of_the_same_round_is_not_topped_up(): void
    {
        $arm = $this->pistol(rounds: 5);
        $this->assertFalse($arm->canLoad('fmj'));

        $after = $this->play($this->state($arm), Action::load('fmj'), [0.0, 0.5]);   // illegal: braces; the enemy then shoots

        $this->assertSame('You cannot load that and braces.', $after->log[0]['text']);
        $this->assertSame(5, $after->a->ranged->rounds);
        $this->assertSame(40, $after->a->ranged->specs['fmj']->stock);
    }

    public function test_a_full_magazine_may_still_be_swapped_for_another_type(): void
    {
        $after = $this->play($this->state($this->pistol(rounds: 12)), Action::load('hollow'));

        $arm = $after->a->ranged;
        $this->assertSame('hollow', $arm->loaded);
        $this->assertSame(5, $arm->rounds, 'only five hollow points to be had');
        $this->assertSame(0, $arm->specs['hollow']->stock);
        $this->assertSame(52, $arm->specs['fmj']->stock, 'the twelve that were in it went back to the stash');
    }

    public function test_a_swap_from_part_full_returns_exactly_what_was_in_it(): void
    {
        $after = $this->play($this->state($this->pistol(rounds: 3)), Action::load('hollow'));

        $this->assertSame(43, $after->a->ranged->specs['fmj']->stock);
    }

    public function test_an_empty_magazine_may_be_loaded_with_the_other_type_or_the_same(): void
    {
        $arm = $this->pistol(rounds: 0);

        $this->assertTrue($arm->canLoad('fmj'));
        $this->assertTrue($arm->canLoad('hollow'));
    }

    public function test_it_will_not_load_what_it_does_not_have(): void
    {
        $this->assertFalse($this->pistol(rounds: 0, hollow: 0)->canLoad('hollow'), 'none in the stash');
        $this->assertFalse($this->pistol(rounds: 0)->canLoad('buckshot'), 'no such round');
    }

    public function test_a_side_that_does_not_count_ammunition_never_loads(): void
    {
        $npc = new Arm('Gun', WeaponClass::Pistol, 10.0, 1, ['s' => new RoundSpec('s', 'S', 10.0, ['penetrating' => 100])], 's');

        $this->assertFalse($npc->canLoad('s'));
    }

    public function test_the_ranged_weapon_can_be_loaded_at_close(): void
    {
        $after = $this->play($this->state($this->pistol(rounds: 0), Range::Close), Action::load('fmj'));

        $this->assertSame(12, $after->a->ranged->rounds);
    }

    public function test_with_nothing_left_to_fire_and_nothing_to_load_a_side_can_only_brace(): void
    {
        $state = $this->state($this->pistol(rounds: 0, fmj: 0, hollow: 0));
        $engine = new FightEngine(CheckRoller::fromConfig());

        $this->assertFalse($engine->canAttack($state, Side::A));
        $this->assertSame('brace', $engine->choose($state, Side::A)->kind->value);
    }

    public function test_the_stash_counts_survive_being_stored(): void
    {
        $after = $this->play($this->state($this->pistol(rounds: 12)), Action::load('hollow'));
        $rebuilt = FightState::fromArray(json_decode((string) json_encode($after->toArray()), true));

        $this->assertEquals($after, $rebuilt);
        $this->assertSame(52, $rebuilt->a->ranged->specs['fmj']->stock);
    }

    public function test_a_loaded_round_is_the_one_that_fires_next(): void
    {
        $loaded = $this->play($this->state($this->pistol(rounds: 0)), Action::load('hollow'));

        // The load round closed the range; open it again so the pistol is the weapon in use.
        $data = $loaded->toArray();
        $data['range'] = 'ranged';
        $loaded = FightState::fromArray($data);

        $after = (new FightEngine(CheckRoller::fromConfig()))->round($loaded, ['a' => Action::attack()], new QueueDice([0.0, 0.5,  0.0, 0.5]), new MapMitigation);

        $this->assertSame(4, $after->a->ranged->rounds);
        $this->assertSame(28, $after->b->hp, 'hollow point: 12, not 10');
    }
}
