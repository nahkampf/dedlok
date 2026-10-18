<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Models\CharacterModifier;
use App\Stats\EffectDefinition;
use App\Stats\Modifier;
use App\Stats\Stacking;
use Illuminate\Support\Collection;

/**
 * The modifiers a character is carrying in their own right.
 *
 * The third source, and the first one that is not derived from something else.
 * An archetype perk exists because of the archetype and a program's effect
 * exists because the program is running; these exist because something happened
 * to the character and stayed.
 *
 * TWO THINGS HAPPEN HERE AND BOTH ARE ON PURPOSE:
 *
 *  - **Rows are filtered to the moment asked about**, which is now by default
 *    and a past instant when a training catch-up is reconstructing a window.
 *    Expired rows are never trusted to have been swept:
 *    A sweep is housekeeping and housekeeping can be late; a resolver applying
 *    a drug because nobody had deleted it yet would be wrong in a way nobody
 *    could reproduce.
 *  - **Stacking is collapsed at READ time**, never at write. "The strongest one
 *    wins" stops being true the moment the strongest expires, and a winner
 *    picked when the row was written would not notice.
 *
 * A row whose definition has gone contributes nothing and does not throw.
 * Definition keys are supposed to be permanent and retired rather than deleted;
 * if one is lost anyway, a character with a dangling injury should still be
 * able to open their sheet.
 */
final readonly class CharacterModifiers implements ModifierSource
{
    /**
     * @param  ?\DateTimeInterface  $asAt  The moment to resolve as of; null is now.
     * @param  Collection<int, CharacterModifier>|null  $rows
     * @param  list<string>  $without  definition keys to leave out, for a stat that must not read its own consequences
     */
    public function __construct(
        private ?int $characterId,
        private ?\DateTimeInterface $asAt = null,
        private ?Collection $rows = null,
        private array $without = [],
    ) {}

    /** @return list<Modifier> */
    public function modifiers(): array
    {
        if ($this->characterId === null) {
            return [];
        }

        $rows = $this->rows ?? CharacterModifier::query()
            ->where('character_id', $this->characterId)
            ->liveAt($this->asAt ?? now())
            ->get();

        if ($this->without !== []) {
            $rows = $rows->reject(fn (CharacterModifier $row): bool => in_array($row->definition_key, $this->without, true));
        }

        $modifiers = [];

        foreach ($this->collapse($rows) as [$definition, $row]) {
            foreach ($definition->modifiers($row->amount()) as $modifier) {
                $modifiers[] = $modifier;
            }
        }

        return $modifiers;
    }

    /**
     * Apply each definition's stacking rule, and hand back the rows that
     * survive it.
     *
     * Grouped by definition first, because stacking is a question about
     * instances of ONE definition. Two different injuries never compete.
     *
     * @param  Collection<int, CharacterModifier>  $rows
     * @return list<array{EffectDefinition, CharacterModifier}>
     */
    private function collapse(Collection $rows): array
    {
        $surviving = [];

        foreach ($rows->groupBy('definition_key') as $key => $group) {
            $definition = EffectDefinition::find((string) $key);

            // A definition that has gone takes its rows with it, quietly. See
            // the class docblock: a dangling injury must not break a sheet.
            if ($definition === null) {
                continue;
            }

            foreach ($this->winners($definition, $group) as $row) {
                $surviving[] = [$definition, $row];
            }
        }

        return $surviving;
    }

    /**
     * Which instances of one definition actually apply.
     *
     * @param  Collection<int, CharacterModifier>  $group
     * @return list<CharacterModifier>
     */
    private function winners(EffectDefinition $definition, Collection $group): array
    {
        return match ($definition->stacking) {
            // Everything counts. Two doses are twice the effect.
            Stacking::Sum => $group->values()->all(),

            // A level rather than a quantity: you are as blinded as the worst
            // thing blinding you. Magnitude decides, and a definition with no
            // per-instance magnitude has nothing to compare, so the first is
            // as good an answer as any.
            Stacking::Highest => $this->strongest($definition, $group),

            // One instance, full stop. A second is the same effect refreshed.
            Stacking::Unique => [$group->first()],

            // One per source: two different implants both count, the same one
            // twice does not. Rows with no source at all are one group, which
            // is right — an unsourced effect is a single fact about the person.
            Stacking::UniqueBySource => $group
                ->groupBy(static fn (CharacterModifier $row): string => $row->source_type.':'.$row->source_id)
                ->map(static fn (Collection $bySource): CharacterModifier => $bySource->first())
                ->values()
                ->all(),
        };
    }

    /**
     * @param  Collection<int, CharacterModifier>  $group
     * @return list<CharacterModifier>
     */
    private function strongest(EffectDefinition $definition, Collection $group): array
    {
        if (! $definition->takesAmountFromData()) {
            return [$group->first()];
        }

        // The largest EFFECT, not the largest number: an injury is negative,
        // so the strongest neural damage is the most negative one. Magnitude
        // is what "highest" has always meant here.
        return [$group->sortByDesc(
            static fn (CharacterModifier $row): float => abs($row->amount() ?? 0.0)
        )->first()];
    }
}
