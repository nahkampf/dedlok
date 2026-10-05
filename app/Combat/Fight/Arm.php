<?php

declare(strict_types=1);

namespace App\Combat\Fight;

use App\Combat\Burst;
use App\Combat\WeaponClass;

/**
 * A weapon in a hand, and what is in it.
 *
 * `aim` is the shooter's resolved `aim.<class>` plus the weapon's accuracy, worked out once.
 * `specs` is every round it can fire, by key — a firearm has one per compatible round the owner
 * carries, so Load can switch mid-fight without reaching for a database; anything that takes no
 * ammunition (a blade, bare hands, an NPC's gun) has the one. `loaded` is which, and `rounds` how
 * many are left, NULL meaning it never runs dry (a blade, or a side that does not count ammunition).
 */
final readonly class Arm
{
    /** @param  array<string, RoundSpec>  $specs */
    public function __construct(
        public string $name,
        public WeaponClass $class,
        public float $aim,
        public int $rate,
        public array $specs,
        public ?string $loaded = null,
        public ?int $rounds = null,
        public int $magazine = 0,
    ) {}

    /** The round that is in it, or null when nothing is loaded. */
    public function spec(): ?RoundSpec
    {
        return $this->loaded === null ? null : ($this->specs[$this->loaded] ?? null);
    }

    /** How many shots an Attack would fire now: its rate, or what is left. Zero cannot fire. */
    public function shots(): int
    {
        if ($this->spec() === null) {
            return 0;
        }

        return $this->rounds === null ? $this->rate : Burst::shots($this->rate, $this->rounds);
    }

    public function canFire(): bool
    {
        return $this->shots() > 0;
    }

    /**
     * Can this weapon take `$key` now? You may reload an EMPTY magazine with anything you hold, and
     * switch to a DIFFERENT round at any time; topping up a part-full magazine of the same round is
     * not offered in a fight. It needs some of that round in the stash (`stock`), and a side that does
     * not count ammunition (stock NULL) never loads.
     */
    public function canLoad(string $key): bool
    {
        $spec = $this->specs[$key] ?? null;

        if ($spec === null || $spec->stock === null || $spec->stock < 1 || $this->magazine < 1) {
            return false;
        }

        return $this->rounds === 0 || $this->loaded === null || $key !== $this->loaded;
    }

    /**
     * The weapon after loading `$key`: what was in it goes back to its own stock first (a switch),
     * then the magazine is filled from the new round's stock, as far as it goes. The same arithmetic
     * as `WeaponLoader`, which step 4 calls to make it so.
     */
    public function loaded(string $key): self
    {
        $specs = $this->specs;
        $old = $this->loaded;

        if ($old !== null && $key !== $old && $this->rounds > 0 && isset($specs[$old])) {
            $specs[$old] = $specs[$old]->withStock((int) $specs[$old]->stock + $this->rounds);
        }

        $take = min($this->magazine, (int) $specs[$key]->stock);
        $specs[$key] = $specs[$key]->withStock((int) $specs[$key]->stock - $take);

        return new self($this->name, $this->class, $this->aim, $this->rate, $specs, $key, $take, $this->magazine);
    }

    /** The same weapon with `$n` fewer rounds in it. A weapon that never runs dry is unchanged. */
    public function fired(int $n): self
    {
        return $this->rounds === null ? $this : new self($this->name, $this->class, $this->aim, $this->rate, $this->specs, $this->loaded, max(0, $this->rounds - $n), $this->magazine);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'class' => $this->class->value, 'aim' => $this->aim, 'rate' => $this->rate,
            'specs' => array_map(static fn (RoundSpec $s): array => $s->toArray(), $this->specs),
            'loaded' => $this->loaded, 'rounds' => $this->rounds, 'magazine' => $this->magazine,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['name'], WeaponClass::from((string) $data['class']), (float) $data['aim'], (int) $data['rate'],
            array_map(static fn (array $s): RoundSpec => RoundSpec::fromArray($s), (array) $data['specs']),
            $data['loaded'] === null ? null : (string) $data['loaded'], $data['rounds'] === null ? null : (int) $data['rounds'], (int) $data['magazine'],
        );
    }
}
