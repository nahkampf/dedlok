<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * What it cost to be thrown out of cyberspace by a deck that stopped running.
 *
 * A value rather than a bare int because the amount is the only thing a caller
 * has to say, and every caller says it the same way — the message is here so
 * two screens cannot word one event differently.
 *
 * NOT a TravelResult. Leaving cyberspace on purpose is one act with one
 * refusal set; being dumped out is a consequence, and the two are deliberately
 * not the same type: nothing should be able to hand this back from jackOut()
 * as though the player had asked.
 */
final readonly class Ejection
{
    public function __construct(public int $damage) {}

    public function message(): string
    {
        return Text::plural('cyberspace.dumped', $this->damage, ['count' => $this->damage]);
    }
}
