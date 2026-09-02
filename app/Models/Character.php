<?php

declare(strict_types=1);

namespace App\Models;

use App\Skills\TrainingRate;
use App\Stats\Modifier;
use App\Stats\ResolvedStat;
use App\Stats\Resolver;
use App\Stats\Sources\ArchetypePerks;
use Database\Factories\CharacterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A player's character. One per user.
 *
 * The ten attributes all run 0-100. That ceiling is enforced in three places
 * on purpose: CHECK constraints in MySQL, clamping here, and validation rules
 * for anything arriving from a request. The database is the only one that
 * cannot be bypassed, the model is the one that catches ordinary code.
 */
#[Fillable([
    'user_id', 'handle', 'archetype',
    'current_hitpoints', 'current_synaptic_integrity',
    'intellect', 'memory', 'perception', 'reflex', 'focus',
    'social', 'meat', 'motor', 'senses', 'muscle',
])]
class Character extends Model
{
    /** @use HasFactory<CharacterFactory> */
    use HasFactory;

    /**
     * The attribute columns, in their canonical order.
     *
     * Single source of truth for anything that needs to iterate them:
     * validation, factories, character creation, display.
     *
     * @var list<string>
     */
    public const ATTRIBUTES = [
        'intellect', 'memory', 'perception', 'reflex', 'focus',
        'social', 'meat', 'motor', 'senses', 'muscle',
    ];

    /**
     * The two groups the creation screen presents. Their union must equal
     * ATTRIBUTES — CharacterTest asserts it, so adding an attribute without
     * filing it under a group fails loudly rather than silently vanishing
     * from the UI. Each group also keeps ATTRIBUTES' own order, because
     * StatConfigTest compares them against the tagged stats in that order;
     * that is why Reflex leads the physical group rather than trailing it.
     *
     * @var list<string>
     */
    public const PHYSICAL_ATTRIBUTES = ['reflex', 'meat', 'motor', 'senses', 'muscle'];

    /** @var list<string> */
    public const MENTAL_ATTRIBUTES = ['intellect', 'memory', 'perception', 'focus', 'social'];

    /**
     * Stored resources, mapped to the derived stat that bounds each one.
     *
     * These are the only mutable saved numbers on the model. Their maxima are
     * derived and never stored — see MECHANICS.md, "Derived stats vs
     * resources".
     *
     * @var array<string, string>
     */
    public const RESOURCES = [
        'current_hitpoints' => 'hitpoints_max',
        'current_synaptic_integrity' => 'synaptic_integrity_max',
    ];

    /**
     * Lifetime floor for a single attribute.
     *
     * Zero, because a debuff must be able to drive a resolved attribute to
     * nothing — negative-then-clamp lands here. Distinct from the creation
     * floor below, the same way the two ceilings are distinct.
     */
    public const ATTRIBUTE_MIN = 0;

    /**
     * Floor for a single attribute AT CHARACTER CREATION.
     *
     * A character may never be created with 0 in anything: a skill governed by
     * a zeroed attribute would train at a rate of 0 SP/minute and could never
     * complete. Play may still reduce an attribute to 0 later; starting there
     * is what is forbidden.
     */
    public const CREATION_ATTRIBUTE_MIN = 1;

    /**
     * Lifetime ceiling for a single attribute.
     *
     * Matches the CHECK constraints on the characters table, so this is the
     * bound the storage layer also enforces. Raising it needs a migration.
     */
    public const ATTRIBUTE_MAX = 100;

    /**
     * Ceiling for a single attribute AT CHARACTER CREATION.
     *
     * Deliberately tighter than ATTRIBUTE_MAX: a character starts capped at 50
     * and grows toward 100 in play. Sliders, creation validation and the
     * starting spread all use this; the clamp on save uses ATTRIBUTE_MAX, or
     * growth past 50 would be silently truncated.
     */
    public const CREATION_ATTRIBUTE_MAX = 50;

    protected function casts(): array
    {
        return array_merge(
            array_fill_keys(self::ATTRIBUTES, 'integer'),
            array_fill_keys(array_keys(self::RESOURCES), 'integer'),
            ['archetype' => 'integer'],
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Starting value for every attribute, from a pool spread evenly.
     *
     * Integer division on purpose: the remainder is left in the pool for the
     * player to place, rather than being scattered across arbitrary attributes.
     * With 75 points over ten attributes that is 7 each and 5 to spend.
     *
     * Capped at CREATION_ATTRIBUTE_MAX so an oversized pool cannot pre-fill
     * past the creation ceiling; see poolIsSatisfiable() for the case where it
     * cannot fit at all.
     *
     * @return array<string, int>
     */
    public static function startingAttributes(int $pool): array
    {
        $base = intdiv(max(0, $pool), count(self::ATTRIBUTES));
        $base = max(self::CREATION_ATTRIBUTE_MIN, min(self::CREATION_ATTRIBUTE_MAX, $base));

        return array_fill_keys(self::ATTRIBUTES, $base);
    }

    /** Can the pool be spent at all without breaching the per-attribute cap? */
    /**
     * Can the pool be spent with every attribute inside its creation bounds?
     *
     * The floor matters as much as the ceiling now: with a minimum of 1 per
     * attribute, a pool below ten cannot be spent at all, and the starting
     * spread would open already over budget.
     */
    public static function poolIsSatisfiable(int $pool): bool
    {
        $count = count(self::ATTRIBUTES);

        return $pool >= $count * self::CREATION_ATTRIBUTE_MIN
            && $pool <= $count * self::CREATION_ATTRIBUTE_MAX;
    }

    /**
     * @return array<string, int>
     */
    public function attributeScores(): array
    {
        return array_map(
            fn (string $name): int => (int) $this->getAttribute($name),
            array_combine(self::ATTRIBUTES, self::ATTRIBUTES),
        );
    }

    /**
     * Validation rules for the attribute columns.
     *
     * Kept beside the constants so a new attribute cannot be added to the
     * table without the rules following it.
     *
     * @return array<string, array<int, string>>
     */
    public static function attributeRules(bool $required = true, ?int $max = null, ?int $min = null): array
    {
        $max ??= self::ATTRIBUTE_MAX;
        $min ??= self::ATTRIBUTE_MIN;
        $rules = [];

        foreach (self::ATTRIBUTES as $attribute) {
            $rules[$attribute] = [
                $required ? 'required' : 'sometimes',
                'integer',
                'min:'.$min,
                'max:'.$max,
            ];
        }

        return $rules;
    }

    /** Validation rules for the creation screen, capped at the tighter bound. */
    public static function creationAttributeRules(): array
    {
        return self::attributeRules(
            max: self::CREATION_ATTRIBUTE_MAX,
            min: self::CREATION_ATTRIBUTE_MIN,
        );
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'character_skills')
            ->using(CharacterSkill::class)
            ->withPivot(['skill_points', 'attained_level'])
            ->withTimestamps();
    }

    /** The rows themselves, for training state rather than skill definitions. */
    public function characterSkills(): HasMany
    {
        return $this->hasMany(CharacterSkill::class);
    }

    /** Everything this character is carrying, both inventories. */
    public function inventory(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * One inventory's worth of rows, ready to render.
     *
     * Which container a row belongs to is a fact about the item, not about the
     * row, so this filters through the definition rather than a column here.
     *
     * Loose rows only. What is inside a memory card belongs to the card and
     * comes back on it, not beside it — a skillbook written to a card is not
     * also sitting on the deck.
     *
     * @return Collection<int, InventoryItem>
     */
    public function inventoryIn(string $container): Collection
    {
        return $this->inventory()
            ->with(['item.useSkill', 'children.item.useSkill'])
            ->loose()
            ->inContainer($container)
            ->get()
            ->sortBy([
                fn (InventoryItem $row) => -$row->item->tier,
                fn (InventoryItem $row) => $row->item->displayName(),
            ])
            ->values();
    }

    /**
     * Every container this character carries that could hold $item.
     *
     * Ordered by what is emptiest, so the obvious place to put something is
     * the first one offered.
     *
     * @return Collection<int, InventoryItem>
     */
    public function containersFor(Item $item): Collection
    {
        return $this->inventory()
            ->with(['item', 'children.item'])
            ->loose()
            ->get()
            ->filter(fn (InventoryItem $row) => $row->item->canHold($item)
                && $row->remainingCapacity() >= $item->size)
            ->sortByDesc(fn (InventoryItem $row) => $row->remainingCapacity())
            ->values();
    }

    /**
     * Has this skill been injected?
     *
     * Distinct from hasSkillAtLevel(): a freshly injected skill sits at level
     * 0, so "knows it at all" and "has any level in it" are different
     * questions. Training needs the first one.
     */
    public function hasInjected(Skill $skill): bool
    {
        return $this->characterSkills()->where('skill_id', $skill->id)->exists();
    }

    /** The ordered training queue: (skill, target level) pairs. */
    public function trainingQueue(): HasMany
    {
        return $this->hasMany(TrainingQueueEntry::class)->orderBy('position');
    }

    /** The queue entry currently training, if any. Position 1, by definition. */
    public function training(): ?TrainingQueueEntry
    {
        return $this->trainingQueue()->with('skill')->active()->first();
    }

    /**
     * Does this character know a skill to at least a level?
     *
     * Reads the stored attained_level rather than deriving it — that is the
     * whole point of storing it, and it makes prerequisite checks a column
     * comparison instead of a computation per row.
     */
    public function hasSkillAtLevel(string $slug, int $level): bool
    {
        return $this->characterSkills()
            ->whereHas('skill', fn ($query) => $query->where('slug', $slug))
            ->where('attained_level', '>=', $level)
            ->exists();
    }

    /**
     * Every prerequisite of a skill that this character does not yet meet.
     *
     * @return list<array{skill: Skill, required_level: int, current_level: int}>
     */
    public function unmetPrerequisites(Skill $skill): array
    {
        $levels = $this->characterSkills()->pluck('attained_level', 'skill_id');
        $unmet = [];

        foreach ($skill->prerequisites()->with('requiredSkill')->get() as $prerequisite) {
            $current = (int) ($levels[$prerequisite->required_skill_id] ?? 0);

            if ($current < $prerequisite->required_level) {
                $unmet[] = [
                    'skill' => $prerequisite->requiredSkill,
                    'required_level' => $prerequisite->required_level,
                    'current_level' => $current,
                ];
            }
        }

        return $unmet;
    }

    public function canLearn(Skill $skill): bool
    {
        return $this->unmetPrerequisites($skill) === [];
    }

    /**
     * Skill points per minute this character would earn training a skill.
     *
     * Uses RESOLVED attributes, so implants and drugs that move the governing
     * attributes change training speed with no special handling.
     */
    public function trainingRateFor(Skill $skill): float
    {
        return TrainingRate::fromConfig()->perMinute(
            $this->stat($skill->primary_attribute)->value,
            $this->stat($skill->secondary_attribute)->value,
        );
    }

    /**
     * Every modifier currently acting on this character.
     *
     * Archetype perks only, for now. Implants, drugs, injuries and gear join
     * this list as they are built, each as its own ModifierSource — nothing
     * downstream changes when they do.
     *
     * @return list<Modifier>
     */
    public function modifiers(): array
    {
        return (new ArchetypePerks($this->archetype))->modifiers();
    }

    /**
     * The unmodified starting value for a stat.
     *
     * Attributes read their column. hitpoints_max is derived here rather than
     * from a formula in config, because the derived-stat graph does not exist
     * yet (MECHANICS.md, step 4).
     */
    public function baseStat(string $statId): float
    {
        if (in_array($statId, self::ATTRIBUTES, true)) {
            return (float) $this->getAttribute($statId);
        }

        $formula = config("stats.formulas.{$statId}");

        if ($formula === null) {
            throw new \InvalidArgumentException("No base value known for stat [{$statId}].");
        }

        return self::applyFormula($formula, $this->attributeScores());
    }

    /**
     * Sum a derived formula: each term is ceil(attribute / divisor).
     *
     * Static and dependency-free so the same call works for a saved character
     * and for a set of slider values that has not been saved yet.
     *
     * @param  array<string, int>  $formula  attribute => divisor
     * @param  array<string, int>  $attributes
     */
    public static function applyFormula(array $formula, array $attributes): float
    {
        $total = 0.0;

        foreach ($formula as $attribute => $divisor) {
            $total += ceil(($attributes[$attribute] ?? 0) / $divisor);
        }

        return $total;
    }

    /** @return list<string> */
    public static function derivedStats(): array
    {
        return array_keys((array) config('stats.formulas', []));
    }

    /** Resolve a stat for this character, with every active modifier applied. */
    public function stat(string $statId): ResolvedStat
    {
        return app(Resolver::class)->resolve($statId, $this->baseStat($statId), $this->modifiers());
    }

    /** The resolved ceiling for a resource column. */
    public function maximumFor(string $resource): int
    {
        $statId = self::RESOURCES[$resource] ?? throw new \InvalidArgumentException(
            "[{$resource}] is not a resource. Known: ".implode(', ', array_keys(self::RESOURCES)).'.'
        );

        return (int) $this->stat($statId)->value;
    }

    /**
     * Bring every resource inside its current maximum.
     *
     * Clamps DOWN and never scales: losing the implant that granted +20 max HP
     * must not heal you, and it must not leave you above your own ceiling
     * either. Returns true if anything moved.
     */
    public function clampResources(): bool
    {
        $changed = false;

        foreach (array_keys(self::RESOURCES) as $resource) {
            $current = $this->getAttribute($resource);

            if ($current === null) {
                continue;
            }

            $bounded = max(0, min($this->maximumFor($resource), (int) $current));

            if ($bounded !== (int) $current) {
                $this->setAttribute($resource, $bounded);
                $changed = true;
            }
        }

        return $changed;
    }

    protected static function booted(): void
    {
        // Ordinary code paths cannot store an out-of-range score. A raw
        // query-builder write still can, which is what the CHECK constraints
        // are for.
        //
        // This is the LIFETIME bound, not the creation one — clamping to 50
        // here would silently truncate any attribute grown past 50 in play.
        // A new character starts at full. null means "not yet initialised",
        // which is why the columns are nullable — it keeps a brand new
        // character distinguishable from one on 0 hit points.
        static::creating(function (self $character): void {
            foreach (self::RESOURCES as $resource => $statId) {
                if ($character->getAttribute($resource) === null) {
                    $character->setAttribute($resource, (int) $character->stat($statId)->value);
                }
            }
        });

        static::saving(function (self $character): void {
            foreach (self::ATTRIBUTES as $attribute) {
                if ($character->getAttribute($attribute) === null) {
                    continue;
                }

                $character->setAttribute($attribute, max(
                    self::ATTRIBUTE_MIN,
                    min(self::ATTRIBUTE_MAX, (int) $character->getAttribute($attribute))
                ));
            }

            // Attributes may just have changed, which moves the maxima, so the
            // resources are clamped after them rather than before.
            $character->clampResources();
        });
    }
}
