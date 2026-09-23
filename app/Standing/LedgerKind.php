<?php

declare(strict_types=1);

namespace App\Standing;

/**
 * Which of an entity's two numbers a ledger entry moved.
 *
 * Both are running totals about one relationship, which is why they share a
 * row in `entity_books` and a table in `entity_ledger`. They behave nothing
 * alike otherwise: standing is clamped and drifts, loyalty floors at zero and
 * never decays, because it is a currency rather than an opinion.
 */
enum LedgerKind: string
{
    case Standing = 'standing';
    case Loyalty = 'loyalty';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
