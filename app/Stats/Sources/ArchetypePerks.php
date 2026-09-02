<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Models\Archetype;
use App\Models\ArchetypePerk;
use App\Stats\Modifier;

/**
 * The modifiers granted by a character's archetype.
 *
 * Only live perks count: the older display-only pairs render on the card and
 * are skipped here. That split is deliberate, so perks can be migrated one at
 * a time rather than in a single change, and so a half-migrated perk cannot
 * silently do nothing.
 *
 * Resolution runs this on every stat read, so it goes through the cached
 * roster rather than querying — the answer is the same handful of rows for
 * every character in the game.
 */
final readonly class ArchetypePerks implements ModifierSource
{
    public function __construct(private ?int $archetypeId) {}

    /** @return list<Modifier> */
    public function modifiers(): array
    {
        $archetype = Archetype::fromRoster($this->archetypeId);

        if ($archetype === null) {
            return [];
        }

        $modifiers = [];

        foreach ($archetype->perks as $perk) {
            if (! $perk->isLive()) {
                continue;   // display-only, not yet migrated
            }

            $label = $perk->displayLabel();

            foreach ($perk->modifiers as $definition) {
                $modifiers[] = Modifier::fromArray($definition + ['source' => $label]);
            }
        }

        return $modifiers;
    }

    /** Is this perk wired to the resolver, or still display-only? */
    public static function perkIsLive(ArchetypePerk $perk): bool
    {
        return $perk->isLive();
    }
}
