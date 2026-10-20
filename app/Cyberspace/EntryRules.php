<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Characters\Flags;
use App\Models\Character;
use App\Models\Run;
use App\Models\System;
use Illuminate\Support\Facades\Config;

/**
 * Who a main-grid gateway lets through (`systems.entry_rules`, CYBERSPACE.md, *Subnets*).
 *
 * A list of rules, ANY ONE of which passes; none listed is an open door. A rule is `{"kind": "flag", "flag": "subnet_mil_access"}`
 * today and `infiltrate` (a completed Infiltrate run against THIS gateway, ended within `cyberspace.infiltrate.window_minutes`: the
 * run row is the receipt, nothing else is stored). A new kind is a `match` arm here and a line in the list, never a column.
 */
final class EntryRules
{
    public static function allow(Character $character, System $gateway): bool
    {
        $rules = $gateway->entry_rules ?? [];

        if ($rules === []) {
            return true;
        }

        foreach ($rules as $rule) {
            $passes = match ($rule['kind'] ?? null) {
                'flag' => Flags::has($character, (string) ($rule['flag'] ?? '')),
                'infiltrate' => self::infiltrated($character, $gateway),
                default => false,
            };

            if ($passes) {
                return true;
            }
        }

        return false;
    }

    private static function infiltrated(Character $character, System $gateway): bool
    {
        return Run::query()
            ->where('character_id', $character->getKey())
            ->where('address', $gateway->address)
            ->where('purpose', RunPurpose::Infiltrate->value)
            ->where('ending', RunEnding::Completed->value)
            ->where('ended_at', '>=', now()->subMinutes((int) Config::get('cyberspace.infiltrate.window_minutes', 10)))
            ->exists();
    }
}
