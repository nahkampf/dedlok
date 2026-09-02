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
}
