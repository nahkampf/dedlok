<?php

declare(strict_types=1);

namespace App\City;

/** What one choice did: the lines to tell the player, whether it closed the encounter, and whether it opened a fight. */
final readonly class EncounterStep
{
    /** @param  list<string>  $lines */
    public function __construct(
        public bool $closed,
        public array $lines = [],
        /** A fight was opened: the encounter waits, and the player is sent to the fight. */
        public bool $fight = false,
    ) {}
}
