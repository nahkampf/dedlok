<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\System;
use App\Support\Text;
use Closure;
use Illuminate\Support\Facades\Config;

/**
 * What a machine calls itself: its type, its owner, and a serial.
 *
 * *Data silo - Anansi Data Weaving 041*. A name that says what the thing is
 * and who answers for it, because those are the two questions a player has
 * about a node they have just swept — and the old word-bag names, *Fast
 * Forge*, *Cold Vault 041*, answered neither. They read well and identified
 * nothing.
 *
 * THE NAME IS COMPOSED, NOT STORED, and that is the whole design. Both halves
 * of it are already columns on the row: `type` and `owner_id`. A stored
 * sentence spelling them out would be the same two facts written a second
 * time, free to drift the first time a corp is renamed — which is exactly the
 * drift `system_contacts` exists to have on purpose and a live row must never
 * have by accident.
 *
 * What IS stored is the designation, because it is nowhere else: a serial per
 * machine, rolled once at instantiation. Every generated system carries one
 * now, where the word bag rolled one on 60% of names. It has to: 121 corps by
 * three types is 363 combinations against nearly three thousand machines, so
 * an unnumbered name identifies about eight systems and a numbered one
 * identifies about one.
 *
 * AN AUTHORED NAME WINS. `systems.name` is no longer where generated names
 * live; it is the override, NULL on nearly every row, the same shape as
 * `items.board_layout`. The portal carries one, and event, mission and
 * player-owned systems will arrive carrying theirs — those never come through
 * the composition below.
 *
 * The punctuation is in `config/strings.php`, like every other player-visible
 * string. A name is read out of two columns and one config line, and nothing
 * here is a word list: the words are the corps' own names, and they live in
 * the `corps` table.
 */
final class SystemNamer
{
    /** @param  Closure(int, int): int  $roll  Inclusive both ends, like random_int. */
    public function __construct(
        private readonly int $minDesignation,
        private readonly int $maxDesignation,
        private readonly Closure $roll,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        return new self(
            (int) Config::get('cyberspace.designation.min', 1),
            (int) Config::get('cyberspace.designation.max', 999),
            $roll ?? static fn (int $min, int $max): int => random_int($min, $max),
        );
    }

    /**
     * A serial for a machine the world is about to instantiate.
     *
     * Rolled rather than counted, because a counter would tell a player how
     * many machines a corp runs and that is not a thing they are supposed to
     * be able to read off a name.
     */
    public function designate(): int
    {
        return ($this->roll)($this->minDesignation, $this->maxDesignation);
    }

    /**
     * The name a player reads for this machine.
     *
     * An authored name is returned as it is. Anything else is composed, and
     * the owner is read through the relation — eager-load it wherever this is
     * called for more than one system at a time.
     */
    public function for(System $system): string
    {
        if ($system->name !== null && $system->name !== '') {
            return $system->name;
        }

        return $this->compose($system->type, $system->owner?->name, $system->designation);
    }

    /**
     * The parts, spelled out.
     *
     * Pure, and public because the contact rows on a player's map hold the
     * same three facts and must be able to say the same sentence about them
     * without a `systems` row to look at.
     *
     * A missing owner is a system nobody runs — the portal, if it ever lost
     * its authored name — and it falls back to the bare type rather than to
     * an empty slot, for the reason the old namer fell back to one: a worse
     * name is still a name, and failing here would take the whole map down.
     */
    public function compose(SystemType $type, ?string $owner, ?int $designation): string
    {
        $name = $owner === null || $owner === ''
            ? $type->label()
            : Text::get('cyberspace.name.owned', ['type' => $type->label(), 'owner' => $owner]);

        if ($designation === null) {
            return $name;
        }

        return Text::get('cyberspace.name.designated', [
            'name' => $name,
            'designation' => self::designation($designation),
        ]);
    }

    /**
     * Three digits, zero-padded. Padded because 041 reads as a serial number
     * and 41 reads as a count.
     */
    public static function designation(int $designation): string
    {
        return str_pad((string) $designation, 3, '0', STR_PAD_LEFT);
    }
}
