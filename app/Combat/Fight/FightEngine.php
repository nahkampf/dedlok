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
use App\Support\Points;
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
    /**
     * `$extraDie` is the engine's OWN die (`fn (int $min, int $max): int`), kept apart from the pinned fight dice so that adding
     * a roll never shifts the order of the others. It rolls whether a wound opens and whether a headbutt hurts its giver. Null is
     * a pure engine with neither: no wounds, no recoil (a test pins this die when it wants them).
     *
     * @param  ?\Closure(int, int): int  $extraDie
     */
    public function __construct(private readonly CheckRoller $roller, private readonly ?\Closure $extraDie = null) {}

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
            // Backing off moves YOU away from the meeting point, so it needs room on your side of it.
            ActionKind::CreateDistance => $state->offset($side) < FightState::reach(),
            ActionKind::CloseIn => $state->distance > 0,
            // Nobody gets away from arm's reach: a side must first be far enough out from the meeting point, by its own measure.
            ActionKind::Disengage => $state->offset($side) > $state->combatant($side)->escapeBeyond(),
            // The ranged weapon, whatever the range: at Close it is the one you are not firing.
            ActionKind::Load => $action->round !== null && $state->combatant($side)->ranged?->canLoad($action->round) === true,
            ActionKind::Brace => true,
            // Whether the drug can be used is `Fights`' question (it owns the belt); the turn itself always can.
            ActionKind::Use => true,
            // Only at arm's reach, and a headbutt needs hands to throw (everybody has them).
            ActionKind::Headbutt => $state->range === Range::Close && $state->combatant($side)->fists() !== null,
            ActionKind::Takedown => $state->range === Range::Close,
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

    /** A multiplier on the chance of a side's attacks: double while it has the upper hand from a takedown. */
    private function edge(FightState $state, Side $side): float
    {
        return $state->combatant($side)->status(RiderKind::Edge) === null ? 1.0 : 2.0;
    }

    /** The chance a headbutt lands: the same as a bare-hands attack. */
    public function quoteHeadbutt(FightState $state, Side $side): float
    {
        $fists = $state->combatant($side)->fists();

        return $fists === null ? 0.0 : $this->roller->chance($fists->aim, $state->combatant($side->other())->defence, null, $this->edge($state, $side));
    }

    /** The chance a takedown works: the taker's takedown value against the target's defence, never above the cap. */
    public function quoteTakedown(FightState $state, Side $side): float
    {
        return min((float) config('combat.takedown.cap'), $this->roller->chance($state->combatant($side)->takedown, $state->combatant($side->other())->defence));
    }

    /**
     * The chance getting something out of the belt works while somebody is on you: the actor's defence against the
     * opponent's pursuit, the same figure as Create distance. It takes your focus off the fight, so it is not certain.
     */
    public function quoteUse(FightState $state, Side $side): float
    {
        return $this->quoteFlight($state, $side);
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
            // Backing off: this side steps away from the meeting point; the other stays where it is.
            default => $side === Side::A
                ? $state->withOffsets($state->offset(Side::A) + $gain, $state->offset(Side::B))
                : $state->withOffsets($state->offset(Side::A), $state->offset(Side::B) + $gain),
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

        return $worked ? $this->stepIn($state, $side) : $state;
    }

    /**
     * One step toward the meeting point. A side already standing at it cannot go further, so the other is pulled in:
     * the gap shrinks by one either way, and a step in and a step out cancel back to where they were.
     */
    private function stepIn(FightState $state, Side $side): FightState
    {
        [$mine, $theirs] = [$state->offset($side), $state->offset($side->other())];

        [$mine, $theirs] = $mine > 0 ? [$mine - 1, $theirs] : [$mine, $theirs - 1];

        return $side === Side::A ? $state->withOffsets($mine, $theirs) : $state->withOffsets($theirs, $mine);
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
            // The turn is spent either way. A pass marks the state, and the item is `Fights`' to apply and log; a fumble
            // is said here and keeps the item.
            ActionKind::Use => $dice() < $this->quoteUse($state, $side)
                ? $state->withUsed()
                : $state->withLog([$this->line($state, Text::get('combat.fight.log.use_fumbled', ['who' => $name]))]),
            ActionKind::Headbutt => $this->headbutt($state, $side, $dice, $mitigation),
            ActionKind::Takedown => $this->takedown($state, $side, $dice),
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
            fn (int $shot): float => $this->roller->chance($arm->aim, $defence, null, Burst::multiplier($shot) * $this->edge($state, $attacker)),
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
            $before = $state->combatant($target)->hp;
            $state = $this->land($state, $attacker, $spec, $tier, $slot, $mitigation, $critical);
            $state = $this->riders($state, $attacker, $spec, $tier, $dice, $state->combatant($target)->hp < $before);
        }

        $arm = $arm->fired($fired);

        return $state->with($attacker, $state->combatant($attacker)->with(
            ranged: $state->range === Range::Ranged ? $arm : null,
            melee: $state->range === Range::Close ? $arm : null,
        ));
    }

    /**
     * A blow with bare hands, thrown whatever is in the hand: the chance and the dice of an ordinary attack, a little more damage,
     * and on a hit that LANDS a chance (its own die) that the giver takes the same blow to the head.
     *
     * @param  callable(): float  $dice
     */
    private function headbutt(FightState $state, Side $side, callable $dice, Mitigation $mitigation): FightState
    {
        $self = $state->combatant($side);
        $fists = $self->fists() ?? throw new \LogicException('Nothing to headbutt with.');
        $base = $fists->spec() ?? throw new \LogicException('Bare hands carry no spec.');
        $spec = new RoundSpec($base->key, 'Headbutt', $base->damage + (float) config('combat.headbutt.damage_bonus'), $base->profile, $base->penetration, $base->riders, $base->stock);
        $target = $side->other();
        $tier = HitQuality::fromRoll($dice(), $this->quoteHeadbutt($state, $side));
        $slot = ArmourSlot::roll($dice());
        $critical = $self->critChance > 0 && $tier !== HitQuality::NearMiss && $dice() * 100 < $self->critChance;
        $before = $state->combatant($target)->hp;

        $state = $this->land($state, $side, $spec, $tier, $slot, $mitigation, $critical);
        $state = $this->riders($state, $side, $spec, $tier, $dice, $state->combatant($target)->hp < $before);

        // It hurts to hit somebody with your head. Only a blow that landed, and only if the fight is still on.
        if ($tier !== HitQuality::NearMiss && $this->extraDie !== null && ! $state->combatant($target)->down()
            && ($this->extraDie)(1, 100) <= (int) config('combat.headbutt.recoil')) {
            $state = $this->land($state, $target, $spec, $tier, ArmourSlot::Head, $mitigation, false, recoil: true);
        }

        return $state;
    }

    /**
     * Try to put somebody on the ground. One die. A success daze them for a turn and gives the taker the upper hand (double
     * the chance of their next attack, on a status that lasts through the other side's turn and then the taker's one); a
     * failure leaves the taker off balance for their next turn.
     *
     * @param  callable(): float  $dice
     */
    private function takedown(FightState $state, Side $side, callable $dice): FightState
    {
        $self = $state->combatant($side);
        $target = $side->other();
        $worked = $dice() < $this->quoteTakedown($state, $side);

        if (! $worked) {
            return $state->with($side, $self->afflicted(RiderKind::OffBalance, 1, 0))
                ->withLog([$this->line($state, Text::get('combat.fight.log.takedown_failed', ['who' => $self->name]), $side === Side::A ? 'crit' : 'ok')]);
        }

        return $state->with($target, $state->combatant($target)->afflicted(RiderKind::Dazed, 1, 0))
            ->with($side, $self->afflicted(RiderKind::Edge, 2, 0))
            ->withLog([$this->line($state, Text::get('combat.fight.log.takedown_ok', ['who' => $self->name, 'target' => $state->combatant($target)->name]), $side === Side::A ? 'ok' : 'crit')]);
    }

    /** The chance a landed hit opens a wound: the base, plus each damage type's bonus by its share of the hit. */
    private function bleedChance(RoundSpec $spec): int
    {
        $chance = (float) config('combat.bleed.base');

        foreach ($spec->profile as $type => $share) {
            $chance += (float) (config('combat.bleed.bonus')[$type] ?? 0) * $share / 100;
        }

        return (int) round(min(100, $chance));
    }

    /**
     * What the hit leaves behind. Only a Clean hit or Dead-on rolls for riders at all — a weaker blow
     * sets nobody alight — and each rider is one die, rolled after the tier and the place. A target
     * already down is not rolled for.
     *
     * @param  callable(): float  $dice
     */
    private function riders(FightState $state, Side $attacker, RoundSpec $spec, HitQuality $tier, callable $dice, bool $hurt = false): FightState
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

        // A wound: only a hit that took hit points, and rolled on the engine's own die so the pinned ones keep their order.
        $victim = $state->combatant($target);

        if ($hurt && $this->extraDie !== null && ! $victim->down() && ($this->extraDie)(1, 100) <= $this->bleedChance($spec)) {
            $victim = $victim->afflicted(RiderKind::Bleeding, 0, 1);
            $state = $state->with($target, $victim)->withLog([$this->line($state, Text::get('combat.fight.log.wound', ['who' => $victim->name, 'level' => $victim->status(RiderKind::Bleeding)['amount']]), $target === Side::A ? 'crit' : 'ok')]);
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

            if ($kind->skipsTurn()) {
                $stunned = true;
                $lines[] = $this->line($state, Text::get('combat.fight.log.skip_'.$kind->value, ['who' => $victim->name]), $side === Side::A ? 'crit' : 'ok');

                continue;
            }

            // Bleeding is flat: what is lost is the level times what a level costs, and nothing worn or carried resists it.
            if ($kind === RiderKind::Bleeding) {
                $lost = Points::of($status['amount'] * $victim->bleedPerLevel());
                $victim = $victim->hurt('current_hitpoints', $lost);
                $lines[] = $this->line($state, Text::get('combat.fight.log.bleed', ['who' => $victim->name, 'amount' => $lost, 'level' => $status['amount']]), $side === Side::A ? 'crit' : 'ok');

                continue;
            }

            if ($origin === null) {
                continue;
            }

            $taken = $mitigation->resolve($side, Damage::hitpoints($status['amount'], $origin));
            $victim = $victim->hurt('current_hitpoints', $taken);
            $lines[] = $this->line($state, Text::get('combat.fight.log.tick', ['who' => $victim->name, 'amount' => $taken, 'status' => $kind->label()]), $side === Side::A ? 'crit' : 'ok');
        }

        return [$state->with($side, $victim->aged())->withLog($lines), $stunned];
    }

    /** One hit that has found its tier and its place: split, resolved part by part, and applied. */
    private function land(FightState $state, Side $attacker, RoundSpec $spec, HitQuality $tier, ArmourSlot $slot, Mitigation $mitigation, bool $critical = false, bool $recoil = false): FightState
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
            'text' => $recoil ? Text::get('combat.fight.log.headbutt_recoil', ['who' => $victim->name, 'parts' => implode(', ', $dealt) ?: '0']) : Text::get($dealt === [] ? 'combat.fight.log.shot_none' : ($critical ? 'combat.fight.log.shot_critical' : 'combat.fight.log.shot'), [
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
