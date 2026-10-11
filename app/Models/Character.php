<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\Position;
use App\Programs\Lifetime;
use App\Skills\TrainingRate;
use App\Stats\Context;
use App\Stats\Modifier;
use App\Stats\ResolvedStat;
use App\Stats\Sheet;
use App\Stats\Sources\ActiveDoses;
use App\Stats\Sources\ArchetypePerks;
use App\Stats\Sources\CharacterModifiers;
use App\Stats\Sources\InstalledImplants;
use App\Stats\Sources\RunningPrograms;
use App\Stats\Sources\SkillEffects;
use App\Stats\Sources\WornArmour;
use App\Support\Qb;
use Carbon\CarbonImmutable;
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
 *
 * `creds` is money, and the one saved number here that is neither an attribute
 * nor a resource: stored and mutable like the two resources, but with no
 * derived maximum to clamp against, which is why it is not in RESOURCES. It is
 * not a stat either — nothing targets it, so nothing resolves it. Nothing
 * spends it yet either: the market has a supply side and no storefront, so the
 * only writer today is App\Archetypes\LoadoutGranter at character creation.
 *
 * The three below are spelled out because casts() is composed with array_merge()
 * from ATTRIBUTES and RESOURCES rather than written as a literal, so static
 * analysis cannot read it and would take both of these for the raw column.
 *
 * @property CarbonImmutable|null $waiting_until
 * @property array<string, mixed>|null $waiting_cause
 * @property CarbonImmutable|null $bounty_word_at
 * @property int $bounty
 * @property int $bounty_grade
 * @property int $district_id
 */
#[Fillable([
    'user_id', 'handle', 'bio', 'archetype', 'creds', 'heat', 'bounty', 'bounty_grade',
    'egress_trace_at', 'egress_si_at',
    'current_hitpoints', 'current_synaptic_integrity', 'waiting_until', 'waiting_cause', 'current_run_id', 'current_hunt_id', 'current_fight_id', 'current_encounter_id', 'equipped_ranged_id', 'equipped_melee_id',
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
     * Ten, so nothing can be dumped. A zero would make a skill governed by it
     * untrainable, but the floor is set higher than that for a second reason:
     * attributes with no mechanic yet (the physical half, mostly) will matter
     * later, and a character built by starving them must not exist when they
     * do. Play may still reduce an attribute below this; starting there is
     * what is forbidden.
     */
    public const CREATION_ATTRIBUTE_MIN = 10;

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
            ['archetype' => 'integer', 'creds' => 'integer', 'heat' => 'float', 'bounty' => 'integer', 'bounty_grade' => 'integer'],
            ['egress_trace_at' => 'integer', 'egress_si_at' => 'integer'],
            ['grid_x' => 'integer', 'grid_y' => 'integer'],
            // On the floor, and what put them there. The cause is an array
            // because none of what it describes is built — see the migration.
            ['waiting_until' => 'immutable_datetime', 'waiting_cause' => 'array'],
            ['bounty_word_at' => 'immutable_datetime'],
        );
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<User, $this> */
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

    /**
     * Can the pool be spent with every attribute inside its creation bounds?
     *
     * The floor matters as much as the ceiling: with a minimum of 10 per
     * attribute, a pool below a hundred cannot be spent at all, and the
     * starting spread would open already over budget.
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

    /** @return BelongsToMany<Skill, $this, CharacterSkill, 'pivot'> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'character_skills')
            ->using(CharacterSkill::class)
            ->withPivot(['skill_points', 'attained_level'])
            ->withTimestamps();
    }

    /**
     * The rows themselves, for training state rather than skill definitions.
     *
     * @return HasMany<CharacterSkill, $this>
     */
    public function characterSkills(): HasMany
    {
        return $this->hasMany(CharacterSkill::class);
    }

    /** Everything this character is carrying, both inventories. */
    /**
     * The deck this character is carrying, if any.
     *
     * An equipped deck is NOT in the stash — scopeLoose() leaves it out, and
     * unequipping is what puts it back. Nothing to do with jacking in, which
     * is a session in cyberspace that an equipped deck sits through.
     *
     * NULL is a real state and the ordinary one — see the migration. Written
     * only by App\Decks\DeckEquipper, and outside #[Fillable] deliberately:
     * there is no request that should be able to set it by name.
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function equippedDeck(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'equipped_deck_id');
    }

    public function hasEquipped(InventoryItem $deck): bool
    {
        return $this->equipped_deck_id === $deck->getKey();
    }

    /**
     * Is this character in cyberspace right now?
     *
     * Having a position IS being jacked in — there is no separate flag that
     * could disagree with the coordinates, and the schema's CHECK is what
     * keeps half a position from existing. Written only by
     * App\Cyberspace\Navigator, and outside #[Fillable] for the reason
     * `equipped_deck_id` is.
     */
    public function isJackedIn(): bool
    {
        return $this->grid_x !== null && $this->grid_y !== null;
    }

    /**
     * Is this inventory row locked by the character being in cyberspace?
     *
     * THE DECK IS LOCKED WHILE YOU ARE JACKED IN. You cannot fit a module,
     * take one out, strip it, unequip it, or destroy it or anything fitted in
     * it — the machine is running and you are inside it. Three writers ask
     * this one question, which is what keeps it one rule rather than three
     * that can drift.
     *
     * Only the EQUIPPED deck and what is fitted into it. A spare deck in the
     * stash is not in play and stays yours to tinker with, which is what
     * CYBERSPACE.md means by everything else being unaffected.
     *
     * This guards what the PLAYER does. It must never be asked by the game's
     * own consequences: a Surge or a thermal burnout destroys fitted hardware
     * precisely while somebody is jacked in, and those go nowhere near these
     * writers.
     */
    public function isLockedInCyberspace(InventoryItem $row): bool
    {
        if (! $this->isJackedIn() || $this->equipped_deck_id === null) {
            return false;
        }

        if ($this->equipped_deck_id === $row->getKey()) {
            return true;
        }

        return DeckFitting::query()
            ->where('deck_id', $this->equipped_deck_id)
            ->where('module_id', $row->getKey())
            ->exists();
    }

    /**
     * Where they are standing, or null if they are not in cyberspace at all.
     *
     * Null is the ordinary answer and every caller has to mean something by
     * it, which is the point: "where are you" and "are you in" are one
     * question here rather than two that can drift apart.
     */
    public function gridPosition(): ?Position
    {
        return $this->isJackedIn() ? new Position($this->grid_x, $this->grid_y) : null;
    }

    /** @return HasMany<InventoryItem, $this> */
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
     * comes back on it, not beside it — an imprint written to a card is not
     * also sitting on the deck.
     *
     * @return Collection<int, InventoryItem>
     */
    public function inventoryIn(string $container): Collection
    {
        return $this->inventory()
            // The deck hardware comes along because the row renders its spec
            // line: without it, a stash of modules is a query per module. The
            // one-to-one rows are what Item::glyph() asks, which is a query
            // per item for each kind it tries before it finds its own.
            ->with([
                'item.useSkill', 'item.deckSlots', 'item.deckModule',
                'item.program', 'item.drug', 'item.consumable', 'item.armour', 'item.weapon', 'item.ammunition',
                'children.item.useSkill', 'children.item.deckSlots', 'children.item.deckModule',
                'children.item.program', 'children.item.drug', 'children.item.consumable',
                'children.item.armour', 'children.item.weapon', 'children.item.ammunition',
            ])
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
                && Qb::tenths($row->remainingCapacity()) >= Qb::tenths($item->size))
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

    /**
     * What is running on the deck being carried.
     *
     * Empty when nothing is equipped, because software runs on the machine you
     * have with you — putting a deck down stops everything on it. One query
     * with the item and its program alongside, since every caller wants all
     * three.
     *
     * @return Collection<int, InventoryItem>
     */
    public function runningProcesses(): Collection
    {
        if ($this->equipped_deck_id === null) {
            return new Collection;
        }

        return Lifetime::reap(InventoryItem::query()
            ->where('character_id', $this->id)
            ->where('running_on_deck_id', $this->equipped_deck_id)
            ->with('item.program')
            ->orderBy('started_at')
            ->get());
    }

    /**
     * The run this character is inside, if any.
     *
     * A COLUMN AND NOT A FLAG on the runs table, so "one at a time" is not a
     * rule anybody enforces — one column holds one value, the same call
     * `equipped_deck_id` makes. Written only by App\Cyberspace\RunStarter.
     *
     * @return BelongsTo<Run, $this>
     */
    public function currentRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'current_run_id');
    }

    /**
     * The hunter this character is fighting, if any.
     *
     * A COLUMN AND NOT A FLAG on the hunts table, the same call `current_run_id`
     * makes. Written only by App\Cyberspace\Hunters (spawning) and
     * App\Cyberspace\HunterCombat (resolving).
     *
     * @return BelongsTo<Hunt, $this>
     */
    public function currentHunt(): BelongsTo
    {
        return $this->belongsTo(Hunt::class, 'current_hunt_id');
    }

    /**
     * The fight this character is in, if any. Written only by App\Combat\Fights.
     *
     * @return BelongsTo<Fight, $this>
     */
    public function currentFight(): BelongsTo
    {
        return $this->belongsTo(Fight::class, 'current_fight_id');
    }

    public function isInFight(): bool
    {
        return $this->current_fight_id !== null;
    }

    /**
     * The Ranged weapon being held, if any. Written only by App\Combat\WeaponWielder.
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function equippedRanged(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'equipped_ranged_id');
    }

    /**
     * The Close weapon being held, if any; null is bare hands.
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function equippedMelee(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'equipped_melee_id');
    }

    /**
     * What this character is wearing, one row per slot. Written only by App\Combat\ArmourWearer.
     *
     * @return HasMany<WornSlot, $this>
     */
    public function wornArmour(): HasMany
    {
        return $this->hasMany(WornSlot::class);
    }

    /**
     * The encounter this character is standing in, or null. Written only by
     * App\City\Encounters (opening) and App\City\EncounterRunner (closing).
     *
     * @return BelongsTo<CharacterEncounter, $this>
     */
    public function currentEncounter(): BelongsTo
    {
        return $this->belongsTo(CharacterEncounter::class, 'current_encounter_id');
    }

    /**
     * The ordered training queue: (skill, target level) pairs.
     *
     * @return HasMany<TrainingQueueEntry, $this>
     */
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
    public function trainingRateFor(Skill $skill, ?\DateTimeInterface $asAt = null): float
    {
        // Resolved AS OF a moment when one is given, which is what makes a
        // catch-up honest: a deposit covering three hours during which a drug
        // wore off credits the bonus for the part of the window that had it,
        // and not for the rest. See TrainingDeposit::rateChangeBoundaries().
        $sheet = $asAt === null
            ? $this->sheet()
            : Sheet::for($this, null, $this->modifiers($asAt));

        return TrainingRate::fromConfig()->perMinute(
            $sheet->get($skill->primary_attribute)->value,
            $sheet->get($skill->secondary_attribute)->value,
        );
    }

    /**
     * Every modifier currently acting on this character.
     *
     * Five sources: the archetype's live perks, the software running on the
     * equipped deck, what their skills grant them for being known, what is
     * installed in their body, and whatever they are carrying in their own
     * right — injuries and statuses, out of `character_modifiers`. The first
     * four are DERIVED from something else the character has and are recomputed
     * on every read; the last is the one that had to be stored, because nothing
     * else implies it.
     *
     * Drugs join as they are built, and so does the fitted hardware MECHANICS
     * names, each as its own ModifierSource — the resolver learns nothing new
     * when they do, which is the whole point of the interface.
     *
     * Four of the five query, so this is not free — only ArchetypePerks reads
     * the cached roster. A Sheet is bound to the context it was built in and is
     * cheap to rebuild rather than held, so build one and read it rather than
     * calling this per stat.
     *
     * Order does not matter. The resolver sorts modifiers into layers by op
     * and sums within each, so two sources cannot fight over which went first.
     *
     * @return list<Modifier>
     */
    public function modifiers(?\DateTimeInterface $asAt = null): array
    {
        $sources = [
            // Permanent by construction, so a past moment cannot change it.
            new ArchetypePerks($this->archetype),
            new RunningPrograms($this, $asAt),
            // What their skills grant them for being known — not the checks
            // those skills feed, which are generated stats of their own.
            new SkillEffects($this),
            // What is in their body. The first source that comes from an item.
            new InstalledImplants($this, $asAt),
            // The first source that is not derived from something else the
            // character has: injuries and statuses that happened TO them.
            new CharacterModifiers($this->getKey(), $asAt),
            // What they have in them. The sixth, and the resolver learned
            // nothing new for it — a dose is a list of modifiers with a clock.
            new ActiveDoses($this, $asAt),
            // What they are wearing. The seventh: a damage resistance counts only for the body
            // part the piece covers, via a condition the source adds. Nothing records when a
            // piece came off, so a past moment cannot be asked.
            new WornArmour($this),
        ];

        $modifiers = [];

        foreach ($sources as $source) {
            foreach ($source->modifiers() as $modifier) {
                $modifiers[] = $modifier;
            }
        }

        return $modifiers;
    }

    /**
     * The unmodified starting value for a stat, from stored numbers alone.
     *
     * DEPRECATED as the way to compute a derived stat, and kept because the
     * character-creation screen has a legitimate use for it: a spread of
     * slider values that is not a character yet has no modifiers to resolve,
     * so raw columns are the right and only input there.
     *
     * Everywhere else, go through App\Stats\Sheet. A derived stat's inputs
     * are the RESOLVED values of what it reads, and this method cannot see
     * them — which is exactly the bug the graph was built to close.
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
     * @param  array<string, float|int>  $attributes
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

    /**
     * Resolve a stat for this character, with every active modifier applied.
     *
     * Goes through a Sheet, so a derived stat reads the RESOLVED values of its
     * inputs rather than their columns. Each call builds one; hold the sheet
     * yourself with `Character::sheet()` when reading several, or every stat on
     * a screen re-resolves its own attributes.
     */
    public function stat(string $statId): ResolvedStat
    {
        return $this->sheet()->get($statId);
    }

    /**
     * This character's stats, resolved together and memoised.
     *
     * Deliberately NOT cached on the model: a sheet is bound to the context it
     * was built in — conditional modifiers are judged against Synaptic
     * Integrity and the like, and those move. A stale sheet answers with the
     * state the character used to be in.
     */
    public function sheet(?Context $context = null): Sheet
    {
        return Sheet::for($this, $context);
    }

    /**
     * The resolved ceiling for a resource column.
     *
     * Pass a sheet when asking more than once — the top bar asks for both
     * resources on every page, and a sheet per question is six modifier
     * sources queried per question.
     */
    public function maximumFor(string $resource, ?Sheet $sheet = null): int
    {
        $statId = self::RESOURCES[$resource] ?? throw new \InvalidArgumentException(
            "[{$resource}] is not a resource. Known: ".implode(', ', array_keys(self::RESOURCES)).'.'
        );

        return (int) ($sheet ?? $this->sheet())->get($statId)->value;
    }

    /**
     * How full a resource is, as a 0-1 ratio for a meter's --v.
     *
     * Zero when the maximum is zero rather than a division by it: a character
     * whose ceiling has been driven to nothing reads as empty, which is what
     * an empty bar already means.
     */
    public function resourceRatio(string $resource, ?Sheet $sheet = null): float
    {
        $max = $this->maximumFor($resource, $sheet);

        if ($max <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, (int) $this->getAttribute($resource) / $max));
    }

    /**
     * Take points off a resource, flooring at zero.
     *
     * THE DULL HALF, deliberately: a subtraction and a floor, knowing nothing
     * about why. **`App\Stats\DamageResolver` is the door**, because how much
     * a hit is worth against this particular character is a resolved number —
     * resistances, vulnerabilities and immunities are modifiers like anything
     * else. Call this directly only where there is genuinely nothing to
     * resolve, and expect to be wrong about that.
     *
     * What the damage MEANS — whether it flatlines, whether it ends a run,
     * what the player is told — belongs to whatever caused it, and nothing
     * about that is decided here.
     *
     * Zero is a real place to be and this will take somebody there. It does
     * not act on it: reaching zero is a defeat, defeats have consequences that
     * depend on their cause, and there is no waiting screen to put anybody on
     * yet.
     *
     * Returns the points actually taken, which is not always what was asked
     * for — a character on 1 loses 1 to a hit of 5.
     */
    public function takeDamage(string $resource, int $amount): int
    {
        if (! array_key_exists($resource, self::RESOURCES)) {
            throw new \InvalidArgumentException(
                "[{$resource}] is not a resource. Known: ".implode(', ', array_keys(self::RESOURCES)).'.'
            );
        }

        if ($amount < 0) {
            throw new \InvalidArgumentException('Damage is never negative. Healing is its own act.');
        }

        $current = (int) $this->getAttribute($resource);
        $taken = min($current, $amount);

        if ($taken === 0) {
            return 0;
        }

        $this->forceFill([$resource => $current - $taken])->save();

        return $taken;
    }

    /**
     * Put points back on a resource, capped at its current maximum.
     *
     * THE MIRROR OF takeDamage AND JUST AS DULL: an addition and a ceiling,
     * knowing nothing about why. What healed somebody — a clinic, a drug, a
     * trained Neuroplasticity ticking over — is that thing's business, and none
     * of it is decided here.
     *
     * **Capped at the maximum AS IT IS NOW**, which is the whole reason this
     * cannot be a subtraction with a minus sign. A character whose ceiling has
     * been cut by flatlines heals to the smaller number, and healing must never
     * be a way to climb above a ceiling that injury lowered.
     *
     * Zero is a real place to heal FROM. Nothing here refuses to bring somebody
     * back from it — whether they may be brought back, and by what, belongs to
     * whatever is doing the healing.
     *
     * Returns the points actually restored, which is not always what was asked
     * for: a character one under their ceiling gains 1 from a dose of 5.
     */
    public function recover(string $resource, int $amount): int
    {
        if (! array_key_exists($resource, self::RESOURCES)) {
            throw new \InvalidArgumentException(
                "[{$resource}] is not a resource. Known: ".implode(', ', array_keys(self::RESOURCES)).'.'
            );
        }

        if ($amount < 0) {
            throw new \InvalidArgumentException('Healing is never negative. Damage is its own act.');
        }

        $current = (int) $this->getAttribute($resource);
        $restored = min($amount, max(0, $this->maximumFor($resource) - $current));

        if ($restored === 0) {
            return 0;
        }

        $this->forceFill([$resource => $current + $restored])->save();

        return $restored;
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
            // Every body is somewhere. One hook, so the factory, creation and
            // anything else that makes a character agree on the answer.
            if ($character->getAttribute('district_id') === null) {
                $character->setAttribute('district_id', District::startId());
            }

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
