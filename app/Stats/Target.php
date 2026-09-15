<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * What a modifier applies to: one named stat, or every stat carrying a set of
 * tags.
 *
 * Tag targeting is the point — "all mental attributes" written as tags keeps
 * working when an eleventh attribute is added, where an explicit list of six
 * silently does not.
 */
final readonly class Target
{
    /** @param  list<string>  $allOf */
    private function __construct(
        public ?string $id,
        public array $allOf,
    ) {}

    public static function id(string $id): self
    {
        return new self($id, []);
    }

    /** @param  list<string>  $tags */
    public static function allOf(array $tags): self
    {
        if ($tags === []) {
            throw new \InvalidArgumentException('A tag target needs at least one tag.');
        }

        return new self(null, array_values($tags));
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $unknown = array_diff(array_keys($data), ['id', 'all_of']);

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown target keys: '.implode(', ', $unknown));
        }

        if (isset($data['id']) && isset($data['all_of'])) {
            throw new \InvalidArgumentException('A target is either an id or a tag set, not both.');
        }

        if (isset($data['id'])) {
            return self::id($data['id']);
        }

        if (isset($data['all_of'])) {
            return self::allOf($data['all_of']);
        }

        throw new \InvalidArgumentException('A target needs an id or all_of.');
    }

    public function matches(Stat $stat): bool
    {
        if ($this->id !== null) {
            return $stat->id === $this->id;
        }

        foreach ($this->allOf as $tag) {
            if (! $stat->hasTag($tag)) {
                return false;
            }
        }

        return true;
    }

    public function describe(): string
    {
        return $this->id ?? implode('+', $this->allOf);
    }

    /**
     * The key a player-facing NAME for this target is looked up under.
     *
     * Sorted, unlike describe(), because a tag set is a set: a perk written
     * `['hitpoints', 'damage']` and one written `['damage', 'hitpoints']` mean
     * the same thing and must not have to be named twice. describe() keeps the
     * author's order because it exists to be read in a breakdown, where what
     * was written is the useful thing to see.
     *
     * A TAG SET IS NAMED AS A WHOLE and never assembled from its parts:
     * "damage" and "hitpoints" do not compose into English, and the pair is
     * called physical damage by people rather than "damage hitpoints".
     */
    public function nameKey(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }

        $tags = $this->allOf;
        sort($tags);

        return implode('+', $tags);
    }
}
