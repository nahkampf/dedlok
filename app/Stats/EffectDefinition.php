<?php

declare(strict_types=1);

namespace App\Stats;

use Illuminate\Support\Facades\Config;

/**
 * What a `character_modifiers` row is an instance OF.
 *
 * The row says *this character has neural damage*; the definition says what
 * neural damage IS — which stat it moves, in which direction, how instances of
 * it combine, and whether it can be shown to the player. Mechanics in config,
 * instances in the database, exactly as docs/MECHANICS.md splits everything else.
 *
 * ONLY FOR CAUSES WITH NO ROW OF THEIR OWN. An implant, a program and an
 * archetype perk all carry their modifiers as JSON on the row they already
 * have; those never come through here. What does is the things that merely
 * happen to a person — an injury, a status, a lingering effect — which have
 * nothing to hang from.
 *
 * DEFINITION KEYS ARE PERMANENT. Rows in the database reference them, so a
 * deleted key orphans live data. Retire one with `retired => true`, which stops
 * anything new being written against it while leaving what exists resolvable.
 *
 * A per-instance amount is written as the string ':amount' in a modifier's
 * `amount` field, and filled from the row's `data`. That is how one definition
 * — neural damage — covers every depth of it without a definition per depth.
 */
final readonly class EffectDefinition
{
    /** The placeholder a definition writes where the instance supplies the number. */
    public const FROM_DATA = ':amount';

    /**
     * @param  list<array<string, mixed>>  $modifiers  Raw modifier shapes, as stored.
     */
    public function __construct(
        public string $key,
        public array $modifiers,
        public Stacking $stacking,
        public bool $retired,
        public ?string $label,
    ) {}

    /**
     * Every definition, keyed. Read from config on demand; Laravel already
     * caches config, so this is not a query and does not need memoising.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = Config::get('effects', []);
        $definitions = [];

        foreach ($configured as $key => $definition) {
            $definitions[(string) $key] = self::fromArray((string) $key, (array) $definition);
        }

        return $definitions;
    }

    public static function find(string $key): ?self
    {
        return self::all()[$key] ?? null;
    }

    /** @param  array<string, mixed>  $definition */
    public static function fromArray(string $key, array $definition): self
    {
        return new self(
            key: $key,
            modifiers: array_values((array) ($definition['modifiers'] ?? [])),
            stacking: Stacking::fromName(isset($definition['stacking']) ? (string) $definition['stacking'] : null),
            retired: (bool) ($definition['retired'] ?? false),
            label: isset($definition['label']) ? (string) $definition['label'] : null,
        );
    }

    /**
     * Does this definition read its magnitude off the instance?
     *
     * True for the accumulating injuries, which is what lets one definition
     * describe every depth of neural damage rather than needing one per point.
     */
    public function takesAmountFromData(): bool
    {
        foreach ($this->modifiers as $modifier) {
            if (($modifier['amount'] ?? null) === self::FROM_DATA) {
                return true;
            }
        }

        return false;
    }

    /**
     * This definition's modifiers, as Modifier objects, for one instance.
     *
     * `$amount` fills every ':amount' placeholder. A definition that wants one
     * and is given none contributes nothing rather than guessing at zero —
     * a modifier of unknown size is not a modifier of no size, and silently
     * applying nothing is easier to notice than silently applying zero.
     *
     * @return list<Modifier>
     */
    public function modifiers(?float $amount = null, ?string $source = null): array
    {
        $built = [];

        foreach ($this->modifiers as $shape) {
            if (($shape['amount'] ?? null) === self::FROM_DATA) {
                if ($amount === null) {
                    continue;
                }

                $shape['amount'] = $amount;
            }

            $built[] = Modifier::fromArray($shape + ['source' => $source ?? $this->label ?? $this->key]);
        }

        return $built;
    }
}
