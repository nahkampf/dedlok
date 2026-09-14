<?php

declare(strict_types=1);

namespace App\Implants;

use App\Support\Text;

/**
 * Where on a body an implant goes, and how much room there is there.
 *
 * A FIXED VOCABULARY, not rows. Every character has the same body: five
 * regions and eleven slots between them, the same for everybody. That is the
 * opposite of a cyberdeck, whose sockets are rows because different decks
 * present different ones — and it is why there is no `implant_slots` table to
 * go beside `deck_slots`.
 *
 * The capacity belongs to the REGION and not to the implant. Four abdominal
 * slots means four altogether, however they are filled, which is the rule a
 * deck's expansion slots already follow: a limit counted per-thing is a limit
 * nobody can reason about.
 *
 * An implant declares its region on the item row — `items.implant_region` — so
 * a fitted row can never disagree with its definition about where it goes. The
 * same lesson `deck_modules` learned by carrying the numbers and not the word.
 */
enum BodyRegion: string
{
    /** Optics, audio, the interface a deck reaches you through. */
    case Head = 'head';

    /** The abdomen, and the most room in the body: organs come out. */
    case Body = 'body';

    /** Both arms together. */
    case Arms = 'arms';

    /** Both legs together. */
    case Legs = 'legs';

    /**
     * One slot, and the most consequential in the body.
     *
     * Deliberately singular: what goes down the spine is a decision rather
     * than a loadout.
     */
    case Spine = 'spine';

    /** How many implants this region holds. */
    public function capacity(): int
    {
        return match ($this) {
            self::Head => 2,
            self::Body => 4,
            self::Arms => 2,
            self::Legs => 2,
            self::Spine => 1,
        };
    }

    public function label(): string
    {
        return Text::get("implants.regions.{$this->value}");
    }

    /** Every slot in the body. Eleven, at the moment. */
    public static function totalCapacity(): int
    {
        return array_sum(array_map(static fn (self $region): int => $region->capacity(), self::cases()));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
