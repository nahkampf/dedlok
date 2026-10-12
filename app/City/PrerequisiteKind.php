<?php

declare(strict_types=1);

namespace App\City;

use App\Support\Text;

/**
 * What an encounter may ask a character to hold. A new kind is a case, a label and an arm of `PrerequisiteJudge`.
 * Every one of them is a READ through the writer that owns the thing: nothing here spends anything.
 */
enum PrerequisiteKind: string
{
    /** A flag the character holds (`subject`). */
    case Flag = 'flag';

    /** At least `amount` of one item, loose in the stash. */
    case Item = 'item';

    /** At least `amount` whole, unexpired, loose scan files: the same count a survey or a supply job uses. */
    case ScanFiles = 'scan_files';

    /** At least `amount` creds. */
    case Creds = 'creds';

    /** The skill (`subject`, a slug) at `amount` or more, on the RESOLVED level. */
    case Skill = 'skill';

    /** Standing with an entity of `amount` or more. */
    case Standing = 'standing';

    /** Standing in a district (`subject`, a slug). Only meaningful in the City; a body is always somewhere. */
    case District = 'district';

    public function label(): string
    {
        return Text::get("admin.encounters.prerequisite.kinds.{$this->value}");
    }

    public function needsSubject(): bool
    {
        return in_array($this, [self::Flag, self::Skill, self::District], true);
    }

    public function needsAmount(): bool
    {
        return in_array($this, [self::Item, self::ScanFiles, self::Creds, self::Skill, self::Standing], true);
    }

    public function needsItem(): bool
    {
        return $this === self::Item;
    }

    public function needsEntity(): bool
    {
        return $this === self::Standing;
    }
}
