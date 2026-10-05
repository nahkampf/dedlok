<?php

declare(strict_types=1);

namespace App\City;

use App\Support\Text;

/**
 * What an encounter can do to somebody. A fixed vocabulary like `Drugs\Family`: a
 * new kind is a case, a label and a branch in `EncounterOutcomes` — and every
 * kind goes through the writer that already owns the thing it changes, so nothing
 * here writes a column. If a story needs more than this, the vocabulary has
 * leaked; fix it here rather than reaching for PHP in the story.
 */
enum OutcomeKind: string
{
    case Creds = 'creds';
    case Item = 'item';
    case Standing = 'standing';
    case Heat = 'heat';
    case Flag = 'flag';
    case Injury = 'injury';
    case Damage = 'damage';

    /** The character takes a drug, at once. The item must be one with a `drugs` row; the amount is ignored. */
    case Dose = 'dose';

    /**
     * The injuries an encounter may deepen or ease: the accumulating ones `InjuryLedger` owns.
     * The drug withdrawals in `config/effects.php` are deliberately absent — `DrugLedger` is the
     * only writer of those.
     */
    public const INJURIES = ['neural_damage', 'physical_trauma'];

    /** What `damage` may hit. */
    public const DAMAGE = ['hitpoints', 'synaptic_integrity'];

    public function label(): string
    {
        return Text::get("city.encounter.kinds.{$this->value}");
    }

    public function needsItem(): bool
    {
        return in_array($this, [self::Item, self::Dose], true);
    }

    public function needsEntity(): bool
    {
        return $this === self::Standing;
    }

    /** Whether `ref` carries the target: a flag slug, an injury key or a resource. */
    public function needsRef(): bool
    {
        return in_array($this, [self::Flag, self::Injury, self::Damage], true);
    }

    /** A flag and a dose have no amount; everything else is a signed number. */
    public function needsAmount(): bool
    {
        return ! in_array($this, [self::Flag, self::Dose], true);
    }
}
