<?php

declare(strict_types=1);

namespace App\Characters;

use App\Models\Character;

/**
 * Heat comes off on its own, slowly.
 *
 * DOING NOTHING IS A VALID WAY TO COOL OFF, and a slow one. Half a point a
 * night by default — two nights to shake a completed trace, four to shake a
 * burn — which is the other half of heat being a number to MANAGE rather than
 * a punishment to endure: it goes up when you are loud and comes down when you
 * are not, and neither half is fast.
 *
 * ## The rate is a stat, which is where the skills go
 *
 * `heat_decay`, based at 0.5 in `stats.bases`. Nothing in the catalogue raises
 * it today — a fixer's contacts, a clean identity, a scrubbed record are all
 * the same shape when they arrive: a modifier on that id, and nothing here
 * learns they exist. It is resolved per character for exactly that reason,
 * even though every character currently resolves to the same number.
 *
 * ## One pass is one night, and a missed night is never made up
 *
 * `market:seed`'s stance, which every housekeeping job in the game shares. No
 * stored clock: there is nothing to reconcile, because the thing being reduced
 * has no schedule of its own. A night the container was down is a night nobody
 * cooled off, which is a smaller lie than crediting a week of decay to
 * somebody who spent it burning machines.
 *
 * ## It floors at nothing
 *
 * Heat has no ceiling and a hard floor. A character at 0.3 cools to 0 rather
 * than to -0.2, and a character at 0 is not touched at all — which is also
 * what keeps the nightly pass off every row in the table.
 */
final class HeatDecay
{
    /**
     * What this character sheds in a night.
     *
     * THROUGH THE SHEET, not `Resolver::resolve()` with a base handed in — the
     * base lives in `stats.bases` and `Sheet::baseFor()` is the only thing that
     * walks that map. Asking the resolver directly would resolve half a point
     * of decay to nothing at all plus whatever modifiers said.
     */
    public function rateFor(Character $character): float
    {
        return $character->stat('heat_decay')->value;
    }

    /**
     * Cool everybody who has anything to cool.
     *
     * @return list<array{character: Character, from: float, to: float}>
     */
    public function tonight(): array
    {
        $cooled = [];

        foreach (Character::query()->where('heat', '>', 0)->orderBy('id')->cursor() as $character) {
            $from = (float) $character->heat;
            $to = max(0.0, round($from - $this->rateFor($character), 1));

            if ($to === $from) {
                continue;
            }

            $character->forceFill(['heat' => $to])->save();

            $cooled[] = ['character' => $character, 'from' => $from, 'to' => $to];
        }

        return $cooled;
    }
}
