<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/**
 * One kind of round a weapon can fire, as a fight sees it: everything a shot needs, already worked
 * out. A fight is fought with a snapshot — modifiers that change mid-fight are ignored by design,
 * and nothing here reaches back for a character.
 *
 * `damage` is the resolved `weapon_damage.<class>` with this round's percent in it, unrounded.
 * `profile` is the effective one (the round's own if it has one, else the weapon's). `riders` is
 * the weapon's and the round's together. `stock` is how many are in the stash, or null for a side
 * that does not count ammunition.
 */
final readonly class RoundSpec
{
    /**
     * @param  array<string, int>  $profile  damage type => percent share
     * @param  list<array{kind: string, chance: int, turns: int, amount: int}>  $riders
     */
    public function __construct(
        public string $key,
        public string $name,
        public float $damage,
        public array $profile,
        public int $penetration = 0,
        public array $riders = [],
        public ?int $stock = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key, 'name' => $this->name, 'damage' => $this->damage, 'profile' => $this->profile,
            'penetration' => $this->penetration, 'riders' => $this->riders, 'stock' => $this->stock,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['key'], (string) $data['name'], (float) $data['damage'], array_map('intval', (array) $data['profile']),
            (int) $data['penetration'], array_values((array) $data['riders']), $data['stock'] === null ? null : (int) $data['stock'],
        );
    }

    public function withStock(?int $stock): self
    {
        return new self($this->key, $this->name, $this->damage, $this->profile, $this->penetration, $this->riders, $stock);
    }
}
