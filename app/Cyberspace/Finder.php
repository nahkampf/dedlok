<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\SystemContact;
use App\Models\SystemScan;
use App\Programs\ProgramKind;

/**
 * The Locator program's search: the nearest system a character has MET that fits a description.
 *
 * It reads the map's memory (`system_contacts`) and never `systems`, for the reason `MapView` gives: a search over the live table
 * would hand out machines nobody has met. A grade is known only from a live scan, exactly as the map shows it, so a grade term
 * matches nothing that has not been scanned. Distance is in steps (`Position::distanceTo()`), measured from where the body is on
 * the grid, or from the portal when it is not jacked in.
 */
final class Finder
{
    /** Does the character have a Locator running on the equipped deck? */
    public function available(Character $character): bool
    {
        return $character->runningProcesses()->contains(
            static fn ($row): bool => $row->item?->program?->kind === ProgramKind::Locator,
        );
    }

    /**
     * "exchange, lockjaw, grade >2" into a description. A term that is a system type is the type; one that reads as a grade
     * comparison (`grade >2`, `>=3`, `5`) is the grade; anything else is part of the owner's name. Whatever is not understood is
     * handed back rather than guessed at.
     */
    public function parse(string $query): FindQuery
    {
        $type = null;
        $owner = null;
        $op = null;
        $grade = null;

        foreach (array_filter(array_map('trim', explode(',', $query)), static fn (string $t): bool => $t !== '') as $term) {
            $lower = mb_strtolower($term);

            if (preg_match('/^(?:grade\s*)?(>=|<=|>|<|=)?\s*([1-9])$/', $lower, $m) === 1) {
                $op = $m[1] === '' ? '=' : $m[1];
                $grade = (int) $m[2];

                continue;
            }

            $matched = collect(SystemType::cases())->first(
                static fn (SystemType $t): bool => $t !== SystemType::Portal && ($t->value === $lower || mb_strtolower($t->label()) === $lower),
            );

            if ($matched !== null) {
                $type = $matched;

                continue;
            }

            $owner = $owner === null ? $term : $owner.' '.$term;
        }

        return new FindQuery($type, $owner, $op, $grade);
    }

    public function nearest(Character $character, FindQuery $query, ?Grid $grid = null): ?FindResult
    {
        $grid ??= Grid::fromConfig();
        $from = $character->gridPosition() ?? $grid->portal();

        $grades = SystemScan::query()
            ->where('character_id', $character->getKey())
            ->live()
            ->whereNotNull('grade')
            ->pluck('grade', 'address');

        $contacts = SystemContact::query()
            ->where('character_id', $character->getKey())
            ->where('type', '!=', SystemType::Portal->value)
            ->when($query->type, static fn ($q, SystemType $t) => $q->where('type', $t->value))
            ->when($query->owner, static fn ($q, string $o) => $q->where('owner', 'like', '%'.addcslashes($o, '%_\\').'%'))
            ->get();

        $best = null;
        $bestKey = null;

        foreach ($contacts as $contact) {
            $known = $grades->get($contact->address);

            if ($query->grade !== null && ($known === null || ! $query->gradeFits((int) $known))) {
                continue;
            }

            $position = $contact->position();
            $steps = $from->distanceTo($position);

            if ($steps === 0) {
                continue;
            }

            // Nearest in steps, then in a straight line, then the one seen first.
            $key = [$steps, ($position->x - $from->x) ** 2 + ($position->y - $from->y) ** 2, $contact->getKey()];

            if ($bestKey === null || $key < $bestKey) {
                $best = $contact;
                $bestKey = $key;
            }
        }

        if ($best === null) {
            return null;
        }

        return new FindResult(
            contact: $best,
            steps: $bestKey[0],
            direction: self::direction($from, $best->position()),
            grade: ($g = $grades->get($best->address)) === null ? null : (int) $g,
            fromPortal: $character->gridPosition() === null,
        );
    }

    /**
     * One of eight compass points, as a key. The grid's y grows downward, so north is a smaller y. Each point owns a 45 degree
     * sector centred on itself.
     */
    public static function direction(Position $from, Position $to): string
    {
        $angle = rad2deg(atan2($to->x - $from->x, $from->y - $to->y));
        $sector = (int) (round($angle / 45) + 8) % 8;

        return ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw'][$sector];
    }

    /** The arrow for a compass key. */
    public static function arrow(string $direction): string
    {
        return ['n' => '↑', 'ne' => '↗', 'e' => '→', 'se' => '↘', 's' => '↓', 'sw' => '↙', 'w' => '←', 'nw' => '↖'][$direction];
    }
}
