<?php

declare(strict_types=1);

namespace App\Characters;

/**
 * What put somebody on the floor, in the words the screen will use.
 *
 * A VALUE OBJECT OVER A JSON BLOB, so the blob has exactly one author and one
 * reader. The column is JSON because none of what it describes is built —
 * there is no simulator, no defeat, no resolved tag — and columns modelled
 * against an unwritten thing get modelled wrong. This is the shape the day it
 * settles into columns.
 *
 * Every field is optional because the causes are not alike. A readiness loss
 * knows nothing about ICE; a defeat in a run knows everything. What a cause
 * cannot say, it leaves out, and the screen shows less rather than showing
 * "unknown".
 */
final readonly class DownedCause
{
    /**
     * @param  list<string>  $tags  Defeat tags that fired, by value.
     * @param  list<string>  $effects  What each tag actually DID, already
     *                                 rendered — "lost 2 points of your ceiling" rather than "flatline".
     *                                 `App\Cyberspace\DefeatResolver` is the only writer of these; `$tags`
     *                                 is names, `$effects` is consequences, and a cause built before the
     *                                 resolver existed has the first and not the second.
     */
    public function __construct(
        public string $reason,
        public ?string $address = null,
        public ?string $system = null,
        public ?string $ice = null,
        public ?int $damage = null,
        public ?string $resource = null,
        public array $tags = [],
        public array $effects = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'reason' => $this->reason,
            'address' => $this->address,
            'system' => $this->system,
            'ice' => $this->ice,
            'damage' => $this->damage,
            'resource' => $this->resource,
            'tags' => $this->tags === [] ? null : $this->tags,
            'effects' => $this->effects === [] ? null : $this->effects,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param  array<string, mixed>|null  $stored */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null || ! isset($stored['reason'])) {
            return null;
        }

        return new self(
            reason: (string) $stored['reason'],
            address: isset($stored['address']) ? (string) $stored['address'] : null,
            system: isset($stored['system']) ? (string) $stored['system'] : null,
            ice: isset($stored['ice']) ? (string) $stored['ice'] : null,
            damage: isset($stored['damage']) ? (int) $stored['damage'] : null,
            resource: isset($stored['resource']) ? (string) $stored['resource'] : null,
            tags: array_values((array) ($stored['tags'] ?? [])),
            effects: array_values((array) ($stored['effects'] ?? [])),
        );
    }
}
