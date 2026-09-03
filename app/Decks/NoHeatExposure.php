<?php

declare(strict_types=1);

namespace App\Decks;

/**
 * No assessment: every socket is safe.
 *
 * What the board uses where there is nobody to assess FOR — the admin's
 * arranging surface and the gallery, which draw a deck definition rather than
 * a deck somebody owns. A null check at every socket would say the same thing
 * in more places, and the board would have to remember to make it.
 */
final class NoHeatExposure
{
    public function verdict(?int $slotId): HeatVerdict
    {
        return HeatVerdict::Safe;
    }

    /** @return list<string> */
    public function atRisk(): array
    {
        return [];
    }

    /** @return list<string> */
    public function overLimit(): array
    {
        return [];
    }

    public function worst(): HeatVerdict
    {
        return HeatVerdict::Safe;
    }

    public function isSafe(): bool
    {
        return true;
    }
}
