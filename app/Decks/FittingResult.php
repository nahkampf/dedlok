<?php

declare(strict_types=1);

namespace App\Decks;

use App\Models\DeckFitting;

/**
 * What came of asking to fit or unfit a module.
 *
 * The same shape as InjectionResult and PlacementResult: a refusal is a value,
 * not an exception, because every one of them is something a player can
 * reasonably try.
 */
final class FittingResult
{
    private function __construct(
        public readonly ?DeckFitting $fitting,
        public readonly ?FittingRefusal $refusal,
        /** How many modules came out. One for a socket, more for a strip. */
        public readonly int $removed = 0,
    ) {}

    public static function fitted(DeckFitting $fitting): self
    {
        return new self($fitting, null);
    }

    /**
     * Taking modules out leaves nothing behind, so there is no fitting to
     * hand back — only how many came out, which is what the screen says.
     */
    public static function removed(int $count = 1): self
    {
        return new self(null, null, $count);
    }

    public static function refused(FittingRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }
}
