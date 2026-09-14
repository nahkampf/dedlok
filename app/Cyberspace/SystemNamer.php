<?php

declare(strict_types=1);

namespace App\Cyberspace;

use Closure;
use Illuminate\Support\Facades\Config;

/**
 * Names for machines, out of a bag of words.
 *
 * `A3F91C08` is not something a player will ever say out loud, so every system
 * carries a name as well as an address. Most are generated: a prefix and a noun
 * drawn per type, and a numeric designation on some of them, which is the
 * cheapest way to get four thousand names out of a hundred words.
 *
 * The name is written ONTO THE ROW at instantiation and read from there
 * afterwards. Nothing looks a name up from config at display time — a system
 * whose name changed when somebody edited a word list would be a system nobody
 * could bookmark.
 *
 * Event, mission and player-owned systems will carry authored names instead.
 * They do not come through here; they arrive with a name already.
 */
final class SystemNamer
{
    /**
     * @param  array<string, array{prefix: list<string>, noun: list<string>}>  $words
     * @param  Closure(int, int): int  $roll  Inclusive both ends, like random_int.
     */
    public function __construct(
        private readonly array $words,
        private readonly int $designationChance,
        private readonly Closure $roll,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        /** @var array<string, array{prefix: list<string>, noun: list<string>}> $words */
        $words = Config::get('cyberspace.names', []);

        return new self(
            $words,
            (int) Config::get('cyberspace.designation_chance', 60),
            $roll ?? static fn (int $min, int $max): int => random_int($min, $max),
        );
    }

    /**
     * A name for a system of this type.
     *
     * A type with no word list gets its own label and a designation rather than
     * an exception: a missing list is a content gap, and a system called
     * "Relay 041" is a worse name than the ones beside it but still a name.
     * Falling over here would take the whole world generation down.
     */
    public function for(SystemType $type): string
    {
        $words = $this->words[$type->value] ?? null;

        if ($words === null || $words['prefix'] === [] || $words['noun'] === []) {
            return $type->label().' '.$this->designation();
        }

        $name = $this->pick($words['prefix']).' '.$this->pick($words['noun']);

        if (($this->roll)(1, 100) <= $this->designationChance) {
            $name .= ' '.$this->designation();
        }

        return $name;
    }

    /**
     * Three digits, zero-padded. Padded because 041 reads as a serial number
     * and 41 reads as a count.
     */
    private function designation(): string
    {
        return str_pad((string) ($this->roll)(1, 999), 3, '0', STR_PAD_LEFT);
    }

    /** @param  list<string>  $words */
    private function pick(array $words): string
    {
        return $words[($this->roll)(0, count($words) - 1)];
    }
}
