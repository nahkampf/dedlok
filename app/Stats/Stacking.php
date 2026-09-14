<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * What happens when a character holds more than one of the same definition.
 *
 * Four answers, and the reason there are four rather than one is that the
 * right answer depends on the fiction. Two doses of the same stimulant add up;
 * two implants doing the same job do not, because you only have one head.
 *
 * The rule belongs to the DEFINITION and never to the row: it is a fact about
 * what the thing is, and two rows of one definition must not be able to
 * disagree about how they combine.
 *
 * The collapsing happens where the rows are read — see
 * App\Stats\Sources\CharacterModifiers — rather than at write time, because
 * "the strongest one wins" stops being true the moment a stronger one expires,
 * and a value computed at write time would not notice.
 */
enum Stacking: string
{
    /**
     * Every instance applies. Two doses are twice the effect.
     *
     * The right default for anything cumulative: injuries deepen, stimulants
     * pile up, poison gets worse.
     */
    case Sum = 'sum';

    /**
     * Only the strongest applies, whatever it came from.
     *
     * For effects where the fiction is a level rather than a quantity — you
     * are as blinded as the worst thing blinding you, not the sum of them.
     */
    case Highest = 'highest';

    /**
     * One instance, full stop. A second is the same effect refreshed, not a
     * second effect.
     *
     * The right rule for anything you can only have one of: an implant in a
     * slot, a permanent injury that accumulates inside its own single row.
     */
    case Unique = 'unique';

    /**
     * One instance per source.
     *
     * Two different implants that both steady your hands each count; wearing
     * the same implant twice does not, because you cannot. This is why
     * `source_type`/`source_id` are on the row rather than only being
     * bookkeeping.
     */
    case UniqueBySource = 'unique_by_source';

    /**
     * Cumulative in the plain sense — several instances contribute.
     *
     * Used to decide whether a `stacks` count means anything, which is the one
     * question a caller ever has that is not "which rows survive".
     */
    public function accumulates(): bool
    {
        return $this === self::Sum;
    }

    public static function fromName(?string $name): self
    {
        return self::tryFrom($name ?? '') ?? self::Sum;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
