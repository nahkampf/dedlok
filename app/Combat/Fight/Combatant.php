<?php

declare(strict_types=1);

namespace App\Combat\Fight;

use App\Combat\RiderKind;
use App\Combat\WeaponClass;
use App\Support\Points;

/**
 * One side of a fight, as a snapshot. Plain data throughout: it has to be stored between requests
 * and rebuilt, so nothing in it is a closure, a model or a resolver.
 *
 * `si` is NULL for a side with no Synaptic Integrity (an NPC): a `neural` hit then does nothing to
 * it at all. `defence` is what an attack against it rolls against, `pursuit` how hard it is to get
 * away from. `fleesAt` is the share of `hpMax` below which it tries to leave (NULL never does).
 */
final readonly class Combatant
{
    public function __construct(
        public string $name,
        public int|float $hp,
        public float $defence,
        public float $pursuit = 0.0,
        public ?Arm $ranged = null,
        public ?Arm $melee = null,
        public int|float|null $si = null,
        /** What is on it now: at most one per kind. @var list<array{kind: string, turns: int, amount: int}> */
        public array $statuses = [],
        public int|float|null $hpMax = null,
        public ?float $fleesAt = null,
        /** Percent of its hits that are critical. Zero for an NPC: only a player's side rolls it. */
        public float $critChance = 0.0,
        /** How far from the meeting point it must be before it can Disengage; null is the fight's default (`combat.distance.flee_beyond`). */
        public ?int $fleeBeyond = null,
        /** What a level of bleeding costs it each turn; null is `combat.bleed.per_level`. Resolved from the body for a player. */
        public ?float $bleedPerLevel = null,
        /** Bare hands, whatever is wielded: what a headbutt is thrown with. Null for a snapshot stored before there were any. */
        public ?Arm $fists = null,
        /** The value a takedown rolls against the opponent's defence. Zero for one that cannot. */
        public float $takedown = 0.0,
    ) {}

    /** Bare hands: the stored ones, or the melee arm when that already is. */
    public function fists(): ?Arm
    {
        return $this->fists ?? ($this->melee?->class === WeaponClass::Unarmed ? $this->melee : null);
    }

    /** Hit points lost each of its turns for each level of bleeding. */
    public function bleedPerLevel(): float
    {
        return $this->bleedPerLevel ?? (float) config('combat.bleed.per_level');
    }

    /** It may Disengage only from MORE than this many steps out from the meeting point. */
    public function escapeBeyond(): int
    {
        return $this->fleeBeyond ?? (int) config('combat.distance.flee_beyond');
    }

    /** The weapon this side can use at this range, if it has one. */
    public function arm(Range $range): ?Arm
    {
        return $range === Range::Ranged ? $this->ranged : $this->melee;
    }

    public function down(): bool
    {
        return $this->hp <= 0 || ($this->si !== null && $this->si <= 0);
    }

    /** Hurt enough that it would rather be somewhere else. Never for a side with no `fleesAt`. */
    public function wantsOut(): bool
    {
        return $this->fleesAt !== null && $this->hpMax !== null && $this->hpMax > 0 && $this->hp / $this->hpMax < $this->fleesAt;
    }

    /** The status of this kind on it, or null. @return array{kind: string, turns: int, amount: int}|null */
    public function status(RiderKind $kind): ?array
    {
        foreach ($this->statuses as $status) {
            if ($status['kind'] === $kind->value) {
                return $status;
            }
        }

        return null;
    }

    /**
     * Put a status on it. Re-applying REFRESHES: the longer duration and the larger amount, never
     * a shorter or weaker one. Nothing stops a status being applied while it is already on, or the
     * same one the round it ran out — a stunlock is allowed.
     */
    public function afflicted(RiderKind $kind, int $turns, int $amount): self
    {
        $current = $this->status($kind);
        // Bleeding STACKS: each new wound is one more level, up to the cap. Everything else refreshes.
        $level = $kind === RiderKind::Bleeding
            ? min((int) config('combat.bleed.max_level'), ($current['amount'] ?? 0) + max(1, $amount))
            : max($amount, $current['amount'] ?? 0);
        $new = ['kind' => $kind->value, 'turns' => max($turns, $current['turns'] ?? 0), 'amount' => $level];

        return $this->withStatuses([...array_filter($this->statuses, static fn (array $s): bool => $s['kind'] !== $kind->value), $new]);
    }

    /** Bleeding at this level, or nought. */
    public function bleeding(): int
    {
        return (int) ($this->status(RiderKind::Bleeding)['amount'] ?? 0);
    }

    /**
     * The same side with `$levels` fewer levels of bleeding (gone at nought), and how many came off.
     *
     * @return array{0: self, 1: int}
     */
    public function eased(int $levels): array
    {
        $eased = min($levels, $this->bleeding());
        $left = $this->bleeding() - $eased;
        $rest = array_values(array_filter($this->statuses, static fn (array $s): bool => $s['kind'] !== RiderKind::Bleeding->value));

        return [$this->withStatuses($left > 0 ? [...$rest, ['kind' => RiderKind::Bleeding->value, 'turns' => 0, 'amount' => $left]] : $rest), $eased];
    }

    /** One of its own turns has passed: every status has one fewer, and a spent one is gone. */
    public function aged(): self
    {
        $persistent = static fn (array $s): bool => RiderKind::from($s['kind'])->persistent();

        return $this->withStatuses(array_values(array_filter(
            array_map(static fn (array $s): array => $persistent($s) ? $s : ['turns' => $s['turns'] - 1] + $s, $this->statuses),
            static fn (array $s): bool => $persistent($s) || $s['turns'] > 0,
        )));
    }

    /** @param  list<array{kind: string, turns: int, amount: int}>  $statuses */
    public function withStatuses(array $statuses): self
    {
        return $this->but(statuses: $statuses);
    }

    /** The same side minus `$amount` from a resource, floored at nothing. */
    public function hurt(string $resource, int|float $amount): self
    {
        return $resource === 'current_synaptic_integrity'
            ? $this->but(si: Points::of(max(0, $this->si - $amount)))
            : $this->but(hp: Points::of(max(0, $this->hp - $amount)));
    }

    public function with(int|float|null $hp = null, int|float|null $si = null, ?Arm $ranged = null, ?Arm $melee = null): self
    {
        return $this->but(hp: $hp, si: $si, ranged: $ranged, melee: $melee);
    }

    private function but(int|float|null $hp = null, int|float|null $si = null, ?Arm $ranged = null, ?Arm $melee = null, ?array $statuses = null): self
    {
        return new self(
            $this->name, $hp ?? $this->hp, $this->defence, $this->pursuit, $ranged ?? $this->ranged, $melee ?? $this->melee,
            $si ?? $this->si, $statuses ?? $this->statuses, $this->hpMax, $this->fleesAt, $this->critChance, $this->fleeBeyond, $this->bleedPerLevel, $this->fists, $this->takedown,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'hp' => $this->hp, 'si' => $this->si, 'defence' => $this->defence, 'pursuit' => $this->pursuit,
            'ranged' => $this->ranged?->toArray(), 'melee' => $this->melee?->toArray(), 'statuses' => $this->statuses,
            'hp_max' => $this->hpMax, 'flees_at' => $this->fleesAt, 'crit_chance' => $this->critChance, 'flee_beyond' => $this->fleeBeyond, 'bleed_per_level' => $this->bleedPerLevel, 'fists' => $this->fists?->toArray(), 'takedown' => $this->takedown,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['name'], Points::of($data['hp']), (float) $data['defence'], (float) $data['pursuit'],
            $data['ranged'] === null ? null : Arm::fromArray($data['ranged']),
            $data['melee'] === null ? null : Arm::fromArray($data['melee']),
            $data['si'] === null ? null : Points::of($data['si']), array_values((array) ($data['statuses'] ?? [])),
            ($data['hp_max'] ?? null) === null ? null : Points::of($data['hp_max']), ($data['flees_at'] ?? null) === null ? null : (float) $data['flees_at'],
            (float) ($data['crit_chance'] ?? 0), isset($data['flee_beyond']) ? (int) $data['flee_beyond'] : null,
            isset($data['bleed_per_level']) ? (float) $data['bleed_per_level'] : null,
            ($data['fists'] ?? null) === null ? null : Arm::fromArray($data['fists']), (float) ($data['takedown'] ?? 0),
        );
    }
}
