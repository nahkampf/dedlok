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

    /** RUN ONLY. Moves the run's trace by `amount` points, never landing it (the simulator does that). */
    case Trace = 'trace';

    /** RUN ONLY. The ICE wakes and the run is detected, with no mark against the owner's books. */
    case WakeIce = 'wake_ice';

    /** RUN ONLY. The run is noticed, as at the core: detected, its ICE awake, and the owner minds. */
    case Detected = 'detected';

    /** GRID DOORS, NOT A RUN. Moves the character to the position in `ref` ("x,y") and looks around, with no rolls. */
    case GridMove = 'grid_move';

    /**
     * Writes the journal: the note `ref` (made with the heading in `text` if it is new), a line under it
     * (`entry_slug`, `entry_text`), or both. Writing what already exists adds nothing.
     */
    case Journal = 'journal';

    /** Marks the journal note or line `ref` done. A note that is done is not shown; a line is struck through. */
    case JournalDone = 'journal_done';

    /**
     * Sends the player an inbox message in the Story category: the sender in `ref`, the title in `text`, the words in
     * `entry_text`, and `amount` seconds to wait (0 or none for at once, at most `MESSAGE_MAX_DELAY`).
     */
    case Message = 'message';

    /** The longest an encounter may hold a message back: 48 hours, in seconds. */
    public const MESSAGE_MAX_DELAY = 172800;

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

    /** Whether `ref` carries the target: a flag slug, an injury key, a resource or a position. */
    public function needsRef(): bool
    {
        return in_array($this, [self::Flag, self::Injury, self::Damage, self::GridMove, self::Journal, self::JournalDone, self::Message], true);
    }

    /** A flag, a dose and the grid effects with no number have no amount; everything else is a signed number. */
    public function needsAmount(): bool
    {
        return ! in_array($this, [self::Flag, self::Dose, self::WakeIce, self::Detected, self::GridMove, self::Journal, self::JournalDone, self::Message], true);
    }

    /** Needs a live run to act on, so only a run encounter may carry it. */
    public function isRunOnly(): bool
    {
        return in_array($this, [self::Trace, self::WakeIce, self::Detected], true);
    }
}
