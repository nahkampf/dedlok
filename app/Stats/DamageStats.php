<?php

declare(strict_types=1);

namespace App\Stats;

use App\Models\Character;

/**
 * A stat per (resource, origin), so damage can be resolved like anything else.
 *
 * `damage.synaptic_integrity.ice` is the number a point of ICE damage becomes
 * after everything the character carries has had its say. It is generated
 * rather than declared, for the reason `check.*` is: the list is a product of
 * two vocabularies that already exist, and a hand-written entry per pair in
 * `config/stats.php` would be a second place to remember an origin.
 *
 * THE BASE ARRIVES WITH THE EVENT, and that is what makes these different from
 * every other stat in the registry. A character does not HAVE a value for
 * `damage.synaptic_integrity.ice` — eight points of it is a thing that happens
 * to them, and `Resolver::resolve()` has always taken the base as an argument.
 * So these are resolved directly rather than read off a Sheet; a sheet asked
 * for one answers 0, which is true and useless.
 *
 * EACH ONE CARRIES BOTH AXES AS TAGS, plus `damage`, which is the whole point:
 *
 *   all_of: ['damage']                            everything that hurts
 *   all_of: ['damage', 'synaptic_integrity']      everything that hurts the mind
 *   all_of: ['damage', 'ice']                     everything ICE does
 *   all_of: ['damage', 'synaptic_integrity', 'ice']   that pair exactly
 *   id: 'damage.synaptic_integrity.ice'           the same pair, said louder
 *
 * A resistance is therefore a modifier and never code: `percentage_of_base`
 * -15 against a tag set. Percentages sum and apply once, so two of them are
 * -30% rather than compounding, which is the same rule the rest of the system
 * follows and the reason stacking resistances cannot approach immunity by
 * accident. Immunity is `multiplier` 0, said on purpose.
 *
 * Floored at 0 — mitigation cannot heal — and rounded once at the end, like
 * every other stat. There is no ceiling: a vulnerability is allowed to take a
 * number past where it started.
 */
final class DamageStats
{
    /** The prefix every generated id carries. Also the tag they all share. */
    public const PREFIX = 'damage';

    /**
     * The whole cross product, ready for the registry.
     *
     * Pure and cheap — two enums and no database, unlike the skill stats,
     * which is why there is nothing to cache and nothing to forget.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $definitions = [];

        foreach (array_keys(Character::RESOURCES) as $resource) {
            foreach (DamageOrigin::cases() as $origin) {
                $definitions[self::id($resource, $origin)] = [
                    'tags' => [self::PREFIX, self::resourceTag($resource), $origin->tag()],
                    'min' => 0,
                    'rounding' => 'round',
                ];
            }
        }

        return $definitions;
    }

    /** What a hit of this kind resolves as. */
    public static function id(string $resource, DamageOrigin $origin): string
    {
        return self::PREFIX.'.'.self::resourceTag($resource).'.'.$origin->tag();
    }

    /**
     * The tag for a resource column.
     *
     * `current_synaptic_integrity` is where the number is STORED; the damage
     * is to synaptic integrity, and a modifier should say so rather than
     * naming a column. Derived rather than mapped, so a third resource brings
     * its tag with it.
     */
    public static function resourceTag(string $resource): string
    {
        return str_starts_with($resource, 'current_') ? substr($resource, 8) : $resource;
    }
}
