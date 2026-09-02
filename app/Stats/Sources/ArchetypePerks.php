<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Stats\Modifier;

/**
 * The modifiers granted by a character's archetype.
 *
 * Only perks carrying a 'modifiers' array are live; the older
 * 'modifier'/'unit' pairs are display-only and are skipped here. That split is
 * deliberate, so perks can be migrated one at a time rather than in a single
 * change, and so a half-migrated perk cannot silently do nothing.
 */
final readonly class ArchetypePerks implements ModifierSource
{
    public function __construct(private ?int $archetypeId) {}

    /** @return list<Modifier> */
    public function modifiers(): array
    {
        if ($this->archetypeId === null) {
            return [];
        }

        $archetype = config("archetypes.list.{$this->archetypeId}");

        if ($archetype === null) {
            return [];
        }

        $modifiers = [];

        foreach ($archetype['perks'] ?? [] as $perk) {
            if (! isset($perk['modifiers'])) {
                continue;   // display-only, not yet migrated
            }

            $label = config("archetypes.perk_labels.{$perk['key']}");

            foreach ($perk['modifiers'] as $definition) {
                $modifiers[] = Modifier::fromArray($definition + ['source' => $label]);
            }
        }

        return $modifiers;
    }

    /** Is this perk wired to the resolver, or still display-only? */
    public static function perkIsLive(array $perk): bool
    {
        return isset($perk['modifiers']);
    }
}
