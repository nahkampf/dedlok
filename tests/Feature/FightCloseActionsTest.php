<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Combat\Fight\Action;
use App\Combat\Fight\Arm;
use App\Combat\Fight\Combatant;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\FightState;
use App\Combat\Fight\RoundSpec;
use App\Combat\Fight\Side;
use App\Combat\RiderKind;
use App\Combat\WeaponClass;
use Tests\Support\MapMitigation;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * Headbutt, takedown and bleeding. docs/COMBAT.md, *Headbutt and takedown* and *Bleeding*. Everybody has defence 10 unless
 * a test says otherwise, so a roll of 0.5 against an equal aim is an even chance.
 */
final class FightCloseActionsTest extends TestCase
{
    private function fists(float $damage = 2.0): Arm
    {
        return new Arm('Bare hands', WeaponClass::Unarmed, 10.0, 1, ['own' => new RoundSpec('own', 'Bare hands', $damage, ['blunt' => 100])], 'own');
    }

    private function sword(): Arm
    {
        return new Arm('Sword', WeaponClass::Blade, 10.0, 1, ['own' => new RoundSpec('own', 'Sword', 8.0, ['slashing' => 100])], 'own');
    }

    /** Both at Close, the enemy first; the player holds a sword and has bare hands as well. */
    private function close(float $defence = 10.0, float $takedown = 10.0, float $hp = 40): FightState
    {
        return new FightState(
            new Combatant('You', $hp, 10.0, 10.0, null, $this->sword(), 30, fists: $this->fists(), takedown: $takedown),
            new Combatant('Gang member', 40, $defence, 10.0, null, $this->fists(3.0), fists: $this->fists(3.0)),
            distance: 0,
        );
    }

    private function engine(?\Closure $extra = null): FightEngine
    {
        return new FightEngine(CheckRoller::fromConfig(), $extra);
    }

    private function round(FightState $state, array $actions, array $dice, ?\Closure $extra = null): FightState
    {
        return $this->engine($extra)->round($state, $actions, new QueueDice($dice), new MapMitigation);
    }

    // ---- headbutt ---------------------------------------------------------------------------

    public function test_a_headbutt_is_bare_hands_even_with_a_sword_in_hand_and_hits_a_little_harder(): void
    {
        $state = $this->close();
        $this->assertTrue($this->engine()->legal($state, Side::A, Action::headbutt()));

        // The enemy braces; then the player's tier die and place die. Dead-on at chance 0.5 is the top rung.
        $after = $this->round($state, ['a' => Action::headbutt(), 'b' => Action::brace()], [0.0, 0.5]);

        // Bare hands do 2, a headbutt 3: whatever the tier, it is a blunt hit and not the sword's 8 slashing.
        $this->assertLessThan(40, $after->b->hp);
        $this->assertGreaterThanOrEqual(40 - 3, $after->b->hp);
        $this->assertSame(0.5, round($this->engine()->quoteHeadbutt($state, Side::A), 2));
    }

    public function test_a_headbutt_needs_arms_reach(): void
    {
        $far = new FightState($this->close()->a, $this->close()->b, distance: 2);

        $this->assertFalse($this->engine()->legal($far, Side::A, Action::headbutt()));
        $this->assertFalse($this->engine()->legal($far, Side::A, Action::takedown()));
    }

    public function test_a_blow_that_lands_may_hurt_its_giver_on_the_engines_own_die(): void
    {
        $dice = [0.0, 0.5];
        $hurt = $this->round($this->close(), ['a' => Action::headbutt(), 'b' => Action::brace()], $dice, static fn (int $min, int $max): int => 1);
        $spared = $this->round($this->close(), ['a' => Action::headbutt(), 'b' => Action::brace()], $dice, static fn (int $min, int $max): int => 100);

        $this->assertLessThan(40, $hurt->a->hp, 'one in two, and this die said so');
        $this->assertSame(40.0, (float) $spared->a->hp);
        $this->assertTrue(collect(array_column($hurt->log, 'text'))->contains(fn (string $t): bool => str_contains($t, 'same blow to the head')));
    }

    public function test_a_miss_costs_the_giver_nothing(): void
    {
        // A defence of 40 against an aim of 10 is hopeless: a die at the top of the range is a near miss.
        $after = $this->round($this->close(defence: 40.0), ['a' => Action::headbutt(), 'b' => Action::brace()], [1.0, 0.5], static fn (int $min, int $max): int => 1);

        $this->assertSame(40, $after->b->hp);
        $this->assertSame(40.0, (float) $after->a->hp);
    }

    // ---- takedown ---------------------------------------------------------------------------

    public function test_a_takedown_dazes_them_for_their_next_turn_and_doubles_your_next_attack(): void
    {
        $state = $this->close();
        $base = $this->engine()->quote($state, Side::A) ?: [$this->engine()->quoteHeadbutt($state, Side::A)];

        $after = $this->round($state, ['a' => Action::takedown(), 'b' => Action::brace()], [0.0]);

        $this->assertNotNull($after->b->status(RiderKind::Dazed));
        $this->assertNotNull($after->a->status(RiderKind::Edge));

        // Doubled, and still never certain.
        $this->assertGreaterThan($base[0], $this->engine()->quoteHeadbutt($after, Side::A));
        $this->assertEqualsWithDelta(min(1 - 0.0085, $base[0] * 2), $this->engine()->quoteHeadbutt($after, Side::A), 1e-9);

        // Next round the enemy loses its turn (no dice for it); then the doubled attack; the upper hand is spent after it.
        $next = $this->round($after, ['a' => Action::headbutt()], [0.0, 0.5]);
        $this->assertSame(40.0, (float) $next->a->hp, 'a dazed enemy did not strike');
        $this->assertTrue(collect(array_column($next->log, 'text'))->contains(fn (string $t): bool => str_contains($t, 'dazed and loses the turn')));
        $this->assertNull($next->b->status(RiderKind::Dazed));

        $third = $this->round($next, ['a' => Action::brace(), 'b' => Action::brace()], []);
        $this->assertNull($third->a->status(RiderKind::Edge), 'one doubled attack, and it is gone');
    }

    public function test_a_failed_takedown_loses_the_next_turn(): void
    {
        $state = $this->close();

        $failed = $this->round($state, ['a' => Action::takedown(), 'b' => Action::brace()], [0.99]);
        $this->assertNotNull($failed->a->status(RiderKind::OffBalance));
        $this->assertTrue(collect(array_column($failed->log, 'text'))->contains(fn (string $t): bool => str_contains($t, 'stumbles and nearly falls')));

        // The next round it has no action: the headbutt it chose never happens.
        $next = $this->round($failed, ['a' => Action::headbutt(), 'b' => Action::brace()], []);
        $this->assertSame(40, $next->b->hp);
        $this->assertNull($next->a->status(RiderKind::OffBalance));
    }

    public function test_a_takedown_never_passes_its_cap(): void
    {
        $strong = $this->close(defence: 1.0, takedown: 99.0);

        $this->assertEqualsWithDelta(0.6, $this->engine()->quoteTakedown($strong, Side::A), 1e-9);
        $this->assertLessThan(0.6, $this->engine()->quoteTakedown($this->close(defence: 30.0), Side::A));
    }

    // ---- bleeding ---------------------------------------------------------------------------

    public function test_a_clean_hit_can_open_a_wound_that_stacks_and_costs_hit_points_every_turn(): void
    {
        $always = static fn (int $min, int $max): int => 1;
        $state = $this->close();

        // The player's sword lands twice (two rounds): bleeding 1, then 2.
        $once = $this->round($state, ['a' => Action::attack(), 'b' => Action::brace()], [0.0, 0.5], $always);
        $this->assertSame(1, $once->b->bleeding());

        $twice = $this->round($once, ['a' => Action::attack(), 'b' => Action::brace()], [0.0, 0.5], $always);
        $this->assertSame(2, $twice->b->bleeding());

        // It never counts down, and each of its owner's turns costs half a hit point a level.
        $hp = $twice->b->hp;
        $later = $this->round($twice, ['a' => Action::brace(), 'b' => Action::brace()], []);
        $this->assertSame(2, $later->b->bleeding());
        $this->assertEqualsWithDelta($hp - 1.0, $later->b->hp, 1e-9);
    }

    public function test_a_wound_is_never_opened_without_the_engines_die(): void
    {
        $after = $this->round($this->close(), ['a' => Action::attack(), 'b' => Action::brace()], [0.0, 0.5]);

        $this->assertSame(0, $after->b->bleeding());
    }

    public function test_a_blade_bleeds_more_than_a_fist(): void
    {
        $chance = new \ReflectionMethod(FightEngine::class, 'bleedChance');
        $of = fn (array $profile): int => $chance->invoke($this->engine(), new RoundSpec('x', 'X', 5.0, $profile));

        $this->assertSame(20, $of(['blunt' => 100]), 'the base');
        $this->assertSame(40, $of(['slashing' => 100]));
        $this->assertSame(35, $of(['penetrating' => 100]));
        $this->assertSame(25, $of(['penetrating' => 30, 'fragmentation' => 70]), 'a shotgun blast is mostly something else: the base and thirty per cent of the bonus');
    }

    public function test_bleeding_survives_the_players_side_being_rebuilt_every_round(): void
    {
        $state = $this->close();
        $state = $state->with(Side::A, $state->a->afflicted(RiderKind::Bleeding, 0, 1)->afflicted(RiderKind::Edge, 2, 0));

        $round = FightState::fromArray(json_decode((string) json_encode($state->toArray()), true));

        $this->assertSame(1, $round->a->bleeding());
        $this->assertNotNull($round->a->status(RiderKind::Edge));
        $this->assertNotNull($round->a->fists());
        $this->assertSame(10.0, $round->a->takedown);
    }

    public function test_the_levels_are_capped(): void
    {
        $side = $this->close()->b;

        for ($i = 0; $i < 12; $i++) {
            $side = $side->afflicted(RiderKind::Bleeding, 0, 1);
        }

        $this->assertSame((int) config('combat.bleed.max_level'), $side->bleeding());
        [$eased, $by] = $side->eased(2);
        $this->assertSame([(int) config('combat.bleed.max_level') - 2, 2], [$eased->bleeding(), $by]);
    }
}
