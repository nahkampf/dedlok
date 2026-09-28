<?php

declare(strict_types=1);

namespace App\Drugs;

use App\Support\Text;

/**
 * Which group a drug belongs to.
 *
 * A FIXED VOCABULARY AND NOT ROWS, like the body's regions and the deck's
 * socket types. The books are kept per family — a character can be deep into
 * opiates and clean of stimulants at the same time — so this is the key half
 * of every figure the mechanic keeps, and a family is a thing the game knows
 * about rather than content somebody authors.
 *
 * A FIFTH FAMILY IS A CASE AND A WITHDRAWAL. Each one needs its own entry in
 * `config/effects.php` saying what going without feels like, because withdrawal
 * from a stimulant is not withdrawal from an opiate, and there is nothing
 * generic to say about either.
 */
enum Family: string
{
    /** Up. Focus and reflex, and a hole where the sleep was. */
    case Stimulant = 'stimulant';

    /** Down. Pain that stops mattering, and everything else with it. */
    case Opiate = 'opiate';

    /** Still. Steadier hands, slower everything. */
    case Sedative = 'sedative';

    /** Sideways. Sees more, holds less. */
    case Psychedelic = 'psychedelic';

    case Nootropic = 'nootropic';

    public function label(): string
    {
        return Text::get("drugs.families.{$this->value}");
    }

    /**
     * The `config/effects.php` key for going without this.
     *
     * COMPOSED RATHER THAN STORED, so a family cannot exist without the
     * definition that says what wanting it feels like — a missing key is a
     * loud failure at the moment somebody withdraws rather than a silent
     * nothing.
     */
    public function withdrawalKey(): string
    {
        return "withdrawal_{$this->value}";
    }
}
