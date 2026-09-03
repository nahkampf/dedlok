<?php

declare(strict_types=1);

namespace App\Decks;

use App\Support\Text;

/**
 * What a deck needs before it will run at all.
 *
 * Five things, and each one is a case here plus an arm in the match below —
 * so a sixth is two lines and a string, not a condition threaded through a
 * screen. The rule lives with the requirement rather than in whatever happens
 * to be rendering it.
 *
 * These are about being USABLE, not about being good. A deck meeting every one
 * of them may still be a bad deck, and a deck failing one is not an invalid
 * row: it is a machine with a part missing, which is an ordinary thing to be
 * halfway through building. Nothing refuses a FITTING because of this, and
 * nothing refuses an EQUIP either — you build a deck while carrying it.
 *
 * THE ONE THING THIS MUST GATE IS JACKING IN. A deck that fails any of these
 * cannot enter cyberspace, and `DeckTotals::isReady()` is the whole of that
 * check — there is no second list to keep in step. Jacking in is not built;
 * when it is, it calls that method or this comment is a lie.
 *
 * It reads the RESOLVED totals, which is what lets a skill that cuts draw
 * bring an over-committed deck into spec without readiness knowing that skills
 * exist.
 */
enum DeckRequirement: string
{
    /** Something to think with. A CPU or an NPU — either will do. */
    case Compute = 'compute';

    /** Somewhere to hold what it is thinking about. */
    case Memory = 'memory';

    /** A way out. Without one it is a box that computes for nobody. */
    case Link = 'link';

    /** Something to run it all. */
    case Power = 'power';

    /** And enough of it. */
    case Budget = 'budget';

    public function metBy(DeckTotals $totals): bool
    {
        return match ($this) {
            // Either seats the thinking, which is why the board wires them to
            // the same hub and why one of each is not required.
            self::Compute => $totals->countOf(Hardware::Cpu) + $totals->countOf(Hardware::Npu) > 0,
            self::Memory => $totals->countOf(Hardware::Ram) > 0,
            self::Link => $totals->countOf(Hardware::Nic) > 0,
            self::Power => $totals->countOf(Hardware::Psu) > 0,
            self::Budget => ! $totals->isOver(),
        };
    }

    /**
     * Everything this deck is still missing, in the order listed above.
     *
     * @return list<self>
     */
    public static function unmetBy(DeckTotals $totals): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $requirement): bool => ! $requirement->metBy($totals),
        ));
    }

    /** What to tell a player is missing. */
    public function label(): string
    {
        return Text::get("decks.ready.missing.{$this->value}");
    }
}
