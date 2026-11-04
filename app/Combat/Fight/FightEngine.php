<?php

declare(strict_types=1);

namespace App\Combat\Fight;

use App\Checks\CheckRoller;
use App\Combat\ArmourSlot;
use App\Combat\Burst;
use App\Combat\HitDamage;
use App\Combat\HitQuality;
use App\Combat\RiderKind;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Support\Text;

/**
 * The rules of a fight, as pure functions over a snapshot. docs/COMBAT.md.
 *
 * **It never writes anything.** A state goes in and a new state comes out; hit points are numbers
 * in the state, resistance is a `Mitigation` passed in, and the dice are one injected closure. No
 * `takeDamage`, no `WaitingRoom`, no `WeaponLoader`, no `Wallet` — step 4 applies the outcome.
 *
 * **One die source, in a fixed order.** Per shot: the tier die, then the body-part die, then (once
 * riders exist) one per rider that qualifies. `CheckRoller::chance()` is the only part of the
 * roller used — never `roll()`, which has a die of its own and would break that order.
 */
final class FightEngine
{
    public function __construct(private readonly CheckRoller $roller) {}

    /**
     * One whole round: each side's turn in the order the fight was opened with, then the range
     * moves on. A side with no chosen action takes the one `choose()` makes for it — which is how an
     * NPC plays; a player against a player supplies both.
     *
     * The turn is validated when it EXECUTES, not when it was picked: with the enemy first, the
     * player's choice was made against a state that has since moved. A fight that ends mid-round
     * ends there — the second side never acts, and no dice are spent on it.
     *
     * @param  array<string, Action>  $actions  side value => its chosen action
     * @param  callable(): float  $dice
     */
    public function round(FightState $state, array $actions, callable $dice, Mitigation $mitigation): FightState
    {
        if ($state->result !== null) {
            throw new \LogicException('The fight is over.');
        }

        foreach ([$state->first, $state->first->other()] as $side) {
            $state = $this->turn($state, $side, $actions[$side->value] ?? $this->choose($state, $side), $dice, $mitigation);

            if ($state->result !== null) {
                return $state;
            }
        }

        return $state->nextRound();
    }

    /**
     * What a side does when nobody is choosing for it — an opponent's play, and the simulator's player.
     *
     * Hurt past its `fleesAt` it tries to leave, which is only possible from beyond the fleeing distance, so
     * from nearer it backs off first. Otherwise it fights where it is stronger: a side that is better at
     * close combat than at range closes in, and any other tries to stay at range, backing off when it has
     * been closed on. Then it attacks with what the distance allows, loads what it can, or braces.
     */
    public function choose(FightState $state, Side $side): Action
    {
        $self = $state->combatant($side);

        if ($self->wantsOut()) {
            foreach ([Action::disengage(), Action::createDistance()] as $action) {
                if ($this->legal($state, $side, $action)) {
                    return $action;
                }
            }
        }

        if ($this->prefersClose($self)) {
            if ($state->distance > 0 && $this->legal($state, $side, Action::closeIn())) {
                return Action::closeIn();
            }
        } elseif ($state->distance === 0 && $this->legal($state, $side, Action::createDistance())) {
            return Action::createDistance();
        }

        if ($this->canAttack($state, $side)) {
            return Action::attack();
        }

        foreach (array_keys($self->ranged?->specs ?? []) as $key) {
            if ($self->ranged->canLoad((string) $key)) {
                return Action::load((string) $key);
            }
        }

        return Action::brace();
    }

    /**
     * Whether this side is better at close combat than at range: its melee weapon does more per turn
     * (the best figure it can deal times its shots) than its ranged one, or it has no ranged weapon it
     * can fire or load. Ties go to range, and a side with no melee arm at all prefers nothing.
     */
    public function prefersClose(Combatant $side): bool
    {
        // Nothing to close in WITH is nothing to prefer.
        if ($side->melee === null) {
            return false;
        }

        $ranged = $side->ranged;
        $usable = $ranged !== null && ($ranged->canFire() || array_filter(array_keys($ranged->specs), static fn ($key): bool => $ranged->canLoad((string) $key)) !== []);

        if (! $usable) {
            return true;
        }

        $per = static fn (Arm $arm): float => $arm->specs === [] ? 0.0 : max(array_map(static fn (RoundSpec $spec): float => $spec->damage, $arm->specs)) * $arm->rate;

        return $per($side->melee) > $per($ranged);
    }

    /** Whether `$action` can be taken by `$side` now. Brace always can. */
    public function legal(FightState $state, Side $side, Action $action): bool
    {
        return match ($action->kind) {
            ActionKind::Attack => $this->canAttack($state, $side),
            ActionKind::CreateDistance => $state->distance < (int) config('combat.distance.max'),
            ActionKind::CloseIn => $state->distance > 0,
            // Nobody gets away from arm's reach: first back off past the fleeing distance.
            ActionKind::Disengage => $state->distance > (int) config('combat.distance.flee_beyond'),
            // The ranged weapon, whatever the range: at Close it is the one you are not firing.
            ActionKind::Load => $action->round !== null && $state->combatant($side)->ranged?->canLoad($action->round) === true,
            ActionKind::Brace => true,
            // Whether the drug can be used is `Fights`' question (it owns the belt); the turn itself always can.
            ActionKind::Use => true,
        };
    }

    /**
     * The chance Create distance and Disengage succeed: the actor's defence against the opponent's
     * pursuit. One number for both, quoted on the button and used by the roll.
     */
    public function quoteFlight(FightState $state, Side $side): float
    {
        return $this->roller->chance($state->combatant($side)->defence, $state->combatant($side->other())->pursuit);
    }

    /** The chance Close in succeeds: the actor's pursuit against the opponent's defence, the pursuer's half. */
    public function quoteClosing(FightState $state, Side $side): float
    {
        return $this->roller->chance($state->combatant($side)->pursuit, $state->combatant($side->other())->defence);
    }

    /** Spend the turn putting rounds in the ranged weapon. */
    private function load(FightState $state, Side $side, string $key): FightState
    {
        $self = $state->combatant($side);
        $arm = ($self->ranged ?? throw new \LogicException('Nothing to load.'))->loaded($key);

        return $state->with($side, $self->with(ranged: $arm))->withLog([$this->line($state, Text::get('combat.fight.log.load', [
            'who' => $self->name,
            'round' => $arm->spec()?->name ?? '',
            'rounds' => $arm->rounds,
        ]))]);
    }

    /**
     * Try to get away (`$getAway`, ending the fight) or to win distance (one or two steps, at once and never
     * past the fight's maximum). One die; a failure has cost the turn. The die's best share of the
     * successes (`combat.distance.far_share`) wins two.
     *
     * @param  callable(): float  $dice
     */
    private function flight(FightState $state, Side $side, callable $dice, bool $getAway): FightState
    {
        $who = $state->combatant($side)->name;
        $chance = $this->quoteFlight($state, $side);
        $roll = $dice();
        $worked = $roll < $chance;
        $gain = $worked && ! $getAway ? ($roll < $chance * (float) config('combat.distance.far_share') ? 2 : 1) : 0;
        $key = $getAway ? 'disengage'.($worked ? '_ok' : '_failed') : 'distance'.(match ($gain) {
            2 => '_far', 1 => '_ok', default => '_failed'
        });
        $state = $state->withLog([$this->line($state, Text::get("combat.fight.log.{$key}", ['who' => $who]))]);

        return match (true) {
            ! $worked => $state,
            $getAway => $state->ended($side === Side::A ? Result::AEscaped : Result::BEscaped),
            default => $state->withDistance($state->distance + $gain),
        };
    }

    /**
     * Try to close the distance by one step. One die; a failure has cost the turn.
     *
     * @param  callable(): float  $dice
     */
    private function closeIn(FightState $state, Side $side, callable $dice): FightState
    {
        $worked = $dice() < $this->quoteClosing($state, $side);
        $state = $state->withLog([$this->line($state, Text::get('combat.fight.log.closing'.($worked ? '_ok' : '_failed'), ['who' => $state->combatant($side)->name]))]);

        return $worked ? $state->withDistance($state->distance - 1) : $state;
    }

    /** Whether this side has a weapon it can fire at this range. */
    public function canAttack(FightState $state, Side $side): bool
    {
        return $state->combatant($side)->arm($state->range)?->canFire() === true;
    }

    /**
     * @param  callable(): float  $dice
     */
    private function turn(FightState $state, Side $side, Action $action, callable $dice, Mitigation $mitigation): FightState
    {
        [$state, $stunned] = $this->startOfTurn($state, $side, $mitigation);

        // A tick can down a side as surely as a bullet, and a stunned one does nothing at all.
        if ($state->combatant($side)->down() || $stunned) {
            return $this->checkFloor($state);
        }

        $name = $state->combatant($side)->name;

        if (! $this->legal($state, $side, $action)) {
            // The choice was made against a state that has moved on, or never was legal.
            $state = $state->withLog([$this->line($state, Text::get("combat.fight.log.cannot_{$action->kind->value}", ['who' => $name]))]);
            $action = Action::brace();
        }

        $state = match ($action->kind) {
            ActionKind::Attack => $this->attack($state, $side, $dice, $mitigation),
            ActionKind::Brace => $state->withLog([$this->line($state, Text::get('combat.fight.log.brace', ['who' => $name]))]),
            ActionKind::Load => $this->load($state, $side, (string) $action->round),
            // The turn is spent and nothing else happens in the engine: the dose is `Fights`' to write, and it logs it.
            ActionKind::Use => $state,
            ActionKind::Disengage => $this->flight($state, $side, $dice, getAway: true),
            ActionKind::CreateDistance => $this->flight($state, $side, $dice, getAway: false),
            ActionKind::CloseIn => $this->closeIn($state, $side, $dice),
        };

        // A fight that ended by one side getting away has nothing left to check.
        return $state->result !== null ? $state : $this->checkFloor($state);
    }

    /** If either side is down, the fight is over. Asked after every turn. */
    private function checkFloor(FightState $state): FightState
    {
        if ($state->a->down()) {
            return $state->withLog([$this->line($state, Text::get('combat.fight.log.down', ['who' => $state->a->name]), 'crit')])->ended(Result::ADown);
        }

        if ($state->b->down()) {
            return $state->withLog([$this->line($state, Text::get('combat.fight.log.down', ['who' => $state->b->name]), 'ok')])->ended(Result::BDown);
        }

        return $state;
    }

    /** @return array{round: int, text: string, tone: string} */
    private function line(FightState $state, string $text, string $tone = 'default'): array
    {
        return ['round' => $state->round, 'text' => $text, 'tone' => $tone];
    }

    /**
     * The chance of each shot of this side's next Attack, for a button to quote. The roll uses
     * exactly these numbers, so a quote and a roll cannot disagree.
     *
     * @return list<float>
     */
    public function quote(FightState $state, Side $attacker): array
    {
        $arm = $state->combatant($attacker)->arm($state->range);

        if ($arm === null || ! $arm->canFire()) {
            return [];
        }

        $defence = $state->combatant($attacker->other())->defence;

        return array_map(
            fn (int $shot): float => $this->roller->chance($arm->aim, $defence, null, Burst::multiplier($shot)),
            range(0, $arm->shots() - 1),
        );
    }

    /**
     * One Attack: every shot the weapon fires, each its own roll, body part and damage, stopping
     * early if the target goes down. The rounds fired come off the magazine.
     *
     * A lower-level step: it assumes the attacker has a weapon that can fire at this range. Whether
     * an action is legal at all is the engine's `act()` (a later sub-slice) and not this method's.
     *
     * @param  callable(): float  $dice  uniform in [0, 1)
     */
    public function attack(FightState $state, Side $attacker, callable $dice, Mitigation $mitigation): FightState
    {
        $self = $state->combatant($attacker);
        $arm = $self->arm($state->range) ?? throw new \LogicException('Nothing to attack with at this range.');
        $spec = $arm->spec() ?? throw new \LogicException('Nothing is loaded.');
        $target = $attacker->other();
        $shots = $arm->shots();
        $chances = $this->quote($state, $attacker);
        $fired = 0;

        for ($k = 0; $k < $shots; $k++) {
            if ($state->combatant($target)->down()) {
                break;
            }

            $fired++;
            $tier = HitQuality::fromRoll($dice(), $chances[$k]);
            $slot = ArmourSlot::roll($dice());
            // One die for a hit that actually lands, and only for a side that has any chance at all
            // (an NPC has none), so the older dice orders are untouched.
            $critical = $self->critChance > 0 && $tier !== HitQuality::NearMiss && $dice() * 100 < $self->critChance;
            $state = $this->land($state, $attacker, $spec, $tier, $slot, $mitigation, $critical);
            $state = $this->riders($state, $attacker, $spec, $tier, $dice);
        }

        $arm = $arm->fired($fired);

        return $state->with($attacker, $state->combatant($attacker)->with(
            ranged: $state->range === Range::Ranged ? $arm : null,
            melee: $state->range === Range::Close ? $arm : null,
        ));
    }

    /**
     * What the hit leaves behind. Only a Clean hit or Dead-on rolls for riders at all — a weaker blow
     * sets nobody alight — and each rider is one die, rolled after the tier and the place. A target
     * already down is not rolled for.
     *
     * @param  callable(): float  $dice
     */
    private function riders(FightState $state, Side $attacker, RoundSpec $spec, HitQuality $tier, callable $dice): FightState
    {
        if ($tier !== HitQuality::CleanHit && $tier !== HitQuality::DeadOn) {
            return $state;
        }

        $target = $attacker->other();

        foreach ($spec->riders as $rider) {
            $victim = $state->combatant($target);

            if ($victim->down()) {
                break;
            }

            if ($dice() * 100 >= $rider['chance']) {
                continue;
            }

            $kind = RiderKind::from($rider['kind']);
            $state = $state->with($target, $victim->afflicted($kind, $rider['turns'], $rider['amount']))
                ->withLog([$this->line($state, Text::get('combat.fight.log.afflicted', ['who' => $victim->name, 'status' => $kind->label(), 'turns' => $rider['turns']]), $target === Side::A ? 'crit' : 'ok')]);
        }

        return $state;
    }

    /**
     * The start of a side's turn: burning and poison hit it, a stun takes the turn away, and every
     * status has one fewer turn. Each tick is a hit with no location (a burn is not a blow) and no
     * penetration, so what a character carries everywhere resists it and worn armour does not.
     *
     * @return array{0: FightState, 1: bool} the state, and whether the side loses its action
     */
    private function startOfTurn(FightState $state, Side $side, Mitigation $mitigation): array
    {
        $victim = $state->combatant($side);
        $lines = [];
        $stunned = false;

        foreach ($victim->statuses as $status) {
            $kind = RiderKind::from($status['kind']);
            $origin = $kind->damageType();

            if ($origin === null) {
                $stunned = true;

                continue;
            }

            $taken = $mitigation->resolve($side, Damage::hitpoints($status['amount'], $origin));
            $victim = $victim->hurt('current_hitpoints', $taken);
            $lines[] = $this->line($state, Text::get('combat.fight.log.tick', ['who' => $victim->name, 'amount' => $taken, 'status' => $kind->label()]), $side === Side::A ? 'crit' : 'ok');
        }

        if ($stunned) {
            $lines[] = $this->line($state, Text::get('combat.fight.log.stunned', ['who' => $victim->name]), $side === Side::A ? 'crit' : 'ok');
        }

        return [$state->with($side, $victim->aged())->withLog($lines), $stunned];
    }

    /** One hit that has found its tier and its place: split, resolved part by part, and applied. */
    private function land(FightState $state, Side $attacker, RoundSpec $spec, HitQuality $tier, ArmourSlot $slot, Mitigation $mitigation, bool $critical = false): FightState
    {
        $target = $attacker->other();
        $victim = $state->combatant($target);
        $dealt = [];

        foreach (HitDamage::parts($spec->damage * ($critical ? (float) config('combat.critical.multiplier') : 1.0), $tier, $spec->profile) as $type => $amount) {
            $origin = DamageOrigin::from($type);
            $resource = (string) $origin->resource();

            // A neural part has nothing to hurt in a side with no Synaptic Integrity (an NPC).
            if ($resource === 'current_synaptic_integrity' && $victim->si === null) {
                continue;
            }

            $damage = new Damage($amount, $resource, $origin, $slot, $spec->penetration);

            $taken = $mitigation->resolve($target, $damage);
            $victim = $victim->hurt($resource, $taken);
            $dealt[] = Text::get('combat.fight.log.part', ['amount' => $taken, 'type' => $origin->tag()]);
        }

        $line = [
            'round' => $state->round,
            'text' => Text::get($dealt === [] ? 'combat.fight.log.shot_none' : ($critical ? 'combat.fight.log.shot_critical' : 'combat.fight.log.shot'), [
                'attacker' => $state->combatant($attacker)->name,
                'tier' => $tier->label(),
                'slot' => $slot->label(),
                'parts' => implode(', ', $dealt),
            ]),
            'tone' => $target === Side::A ? 'crit' : 'ok',
        ];

        return $state->with($target, $victim)->withLog([$line]);
    }
}
