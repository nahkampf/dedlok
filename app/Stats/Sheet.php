<?php

declare(strict_types=1);

namespace App\Stats;

use App\Models\Character;
use App\Models\Skill;
use Illuminate\Support\Collection;

/**
 * One character's stats, resolved in dependency order.
 *
 * THE POINT OF THIS CLASS, in one sentence: a derived stat's inputs are the
 * RESOLVED values of the stats it reads, not the numbers in their columns.
 *
 * Before it existed, `hitpoints_max` was computed straight off
 * `attributeScores()` — the raw columns — so an implant granting +10 Focus
 * raised Focus everywhere it was shown and changed maximum HP by nothing at
 * all. Half of Focus, in a formula that could not see the half that had been
 * added. Nothing reported it because nothing could: the arithmetic was right
 * and the input was wrong.
 *
 * So resolution goes in an order:
 *
 *   1. stored stats     attributes off their columns, skill levels off their
 *                       rows. These read nothing, so they resolve first.
 *   2. derived stats    vitals, from resolved attributes.
 *   3. checks           from a resolved attribute pair and a resolved level.
 *
 * App\Stats\StatGraph decides that order and refuses a cycle. Everything is
 * memoised for the life of the sheet, so a screen showing eleven stats resolves
 * each of their shared inputs once.
 *
 * The sheet is per character AND per context: conditional modifiers depend on
 * state that moves, so a sheet built while the runner was at 12% SI is not the
 * sheet to answer with once they are healed. Build a new one — they are cheap,
 * and holding one across a change is the mistake this comment exists to name.
 */
final class Sheet
{
    /** @var array<string, ResolvedStat> */
    private array $resolved = [];

    /** @var list<Modifier>|null */
    private ?array $modifiers = null;

    /** @var array<int, int>|null  skill_id => attained level */
    private ?array $levels = null;

    /**
     * The context key each resource's fullness is published under.
     *
     * Named for the predicate that reads it rather than for the column, so
     * `si_below_pct` reads `si_pct` and nobody has to know the column is
     * called `current_synaptic_integrity`.
     */
    private const RATIO_KEYS = [
        'current_hitpoints' => 'hp_pct',
        'current_synaptic_integrity' => 'si_pct',
    ];

    /** @var Collection<int, Skill>|null */
    private static $skills = null;

    private function __construct(
        private readonly Character $character,
        private readonly Resolver $resolver,
        private readonly StatGraph $graph,
        private readonly Context $context,
        /** @var list<Modifier>|null */
        ?array $modifiers = null,
    ) {
        $this->modifiers = $modifiers;
    }

    /**
     * @param  list<Modifier>|null  $modifiers  what is acting on this character.
     *                                          Null asks the character, which is
     *                                          the ordinary case; passing a bag
     *                                          is the seam that lets a test say
     *                                          "and now an implant" without one
     *                                          existing yet — the same seam
     *                                          DeckTotals::resolvedWith() has.
     */
    public static function for(Character $character, ?Context $context = null, ?array $modifiers = null): self
    {
        if ($context !== null) {
            return new self($character, app(Resolver::class), app(StatGraph::class), $context, $modifiers);
        }

        // THE CONTEXT IS WORKED OUT BY A BARE SHEET, and that sheet has
        // already asked all six modifier sources and read the skill levels to
        // do it. What acts on the character does not depend on the context —
        // only what a CONDITIONAL modifier makes of it does — so the real
        // sheet inherits both rather than asking a second time. It halved the
        // queries behind every sheet built without a context.
        $bare = new self($character, app(Resolver::class), app(StatGraph::class), Context::empty(), $modifiers);

        $sheet = new self($character, app(Resolver::class), app(StatGraph::class), self::contextFrom($bare), $bare->modifiers());
        $sheet->levels = $bare->levels();

        return $sheet;
    }

    /**
     * What conditional modifiers are judged against right now.
     *
     * Ratios rather than absolutes for the vitals, so "under 30%" means the
     * same thing to a fresh character and to one who has doubled their ceiling.
     *
     * THE RATIOS ARE COMPUTED CONTEXT-FREE, and that is a rule rather than an
     * implementation detail. A ratio needs a maximum; a maximum is a resolved
     * stat; resolving reads the context — so a context built from fully
     * resolved vitals would be defined in terms of itself, and the first
     * version of this method recursed until it ran out of memory rather than
     * saying so.
     *
     * The rule that falls out: **a conditional modifier may not change the
     * stat its own condition is measured against.** "+20 max HP while under
     * 30% HP" is not a modifier, it is a question with no answer — the ceiling
     * moves the ratio, which moves whether the ceiling moved. So the maxima
     * that define the context come from unconditional modifiers alone, and a
     * conditional modifier on a vital ceiling still applies everywhere the
     * ceiling is READ; it simply cannot vote on its own condition.
     *
     * Two keys are deliberately absent until the things behind them exist:
     * `jacked_in` needs a cyberspace session, and `heat` needs the column
     * CYBERSPACE.md specifies. A predicate whose key is missing answers no, so
     * a modifier conditioned on either is inert rather than wrong.
     */
    public static function contextFor(Character $character): Context
    {
        return self::contextFrom(new self($character, app(Resolver::class), app(StatGraph::class), Context::empty()));
    }

    /** contextFor(), from a bare sheet the caller may want to keep. */
    private static function contextFrom(self $bare): Context
    {
        $character = $bare->character;

        $values = [];

        foreach (Character::RESOURCES as $resource => $statId) {
            $max = $bare->value($statId);
            $current = (int) $character->getAttribute($resource);

            $values[self::RATIO_KEYS[$resource]] = $max > 0
                ? max(0.0, min(1.0, $current / $max))
                : 0.0;
        }

        /*
         * SKILL LEVELS, so a modifier can be conditional on training.
         *
         * Keyed `skill.<slug>` and only for skills this character actually has
         * — an absent key and a level of zero are the same answer to
         * `skill_at_least`, and writing every skill in the game into every
         * context would be a row per skill per resolution for the sake of the
         * ones nobody has.
         *
         * One query, through the same cached roster the sheet already uses for
         * a skill's own stat.
         */
        foreach ($bare->levels() as $skillId => $level) {
            $slug = self::skills()->firstWhere('id', $skillId)?->slug;

            if ($slug !== null) {
                $values['skill.'.$slug] = (float) $level;
            }
        }

        return Context::of($values);
    }

    public function context(): Context
    {
        return $this->context;
    }

    /**
     * One stat, and everything it reads, resolved.
     *
     * Resolving `check.intrusion` pulls in its attribute pair and its skill
     * level and stops — it does not resolve every other check in the game.
     */
    public function get(string $statId): ResolvedStat
    {
        if (isset($this->resolved[$statId])) {
            return $this->resolved[$statId];
        }

        foreach ($this->graph->orderFor($statId) as $needed) {
            $this->resolved[$needed] ??= $this->resolveOne($needed);
        }

        return $this->resolved[$statId] ??= $this->resolveOne($statId);
    }

    public function value(string $statId): float
    {
        return $this->get($statId)->value;
    }

    /**
     * @param  list<string>  $statIds
     * @return array<string, ResolvedStat>
     */
    public function only(array $statIds): array
    {
        $out = [];

        foreach ($statIds as $statId) {
            $out[$statId] = $this->get($statId);
        }

        return $out;
    }

    private function resolveOne(string $statId): ResolvedStat
    {
        return $this->resolver->resolve($statId, $this->baseFor($statId), $this->modifiers(), $this->context);
    }

    /**
     * The unmodified starting value.
     *
     * Four kinds of stat, and the order of the tests is the order of the graph:
     * stored things first, then things computed from them.
     */
    private function baseFor(string $statId): float
    {
        if (in_array($statId, Character::ATTRIBUTES, true)) {
            return (float) $this->character->getAttribute($statId);
        }

        if (str_starts_with($statId, SkillStats::SKILL_PREFIX)) {
            return $this->skillLevel((string) SkillStats::slugOf($statId));
        }

        if (str_starts_with($statId, SkillStats::CHECK_PREFIX)) {
            return $this->checkBase((string) SkillStats::slugOf($statId));
        }

        /*
         * A POOL sums its attributes and divides ONCE, where a formula divides
         * each and rounds each up before adding. Four attributes of 20 over 8.5
         * is 12 as a formula and 9.4 as a pool, and the second is what a
         * capability should read like. See config/stats.php.
         */
        $pool = config("stats.pools.{$statId}");

        if ($pool !== null) {
            $values = $this->inputValues((array) $pool['attributes']);

            return array_sum(array_map(
                static fn (string $attribute): float => (float) ($values[$attribute] ?? 0),
                (array) $pool['attributes'],
            )) / (float) $pool['divisor'];
        }

        /*
         * A DECLARED BASE, for a stat that is neither computed from attributes
         * nor stored on the character — it starts at a number and everything
         * it is worth comes from modifiers. `routing_hops` is the first:
         * nobody routes a run through anything until a skill says otherwise.
         *
         * Opt-in by an entry in `stats.bases`, so the stats that
         * already hand a base in at their call site (`icebreaker_damage` from
         * zero, `scan_duration` from config) keep resolving exactly as they do.
         * Without this a declared stat with no formula and no pool throws when
         * asked through a Sheet, which is a loud failure but a failure all the
         * same — `Character::stat()` is the obvious way to ask and it should
         * answer.
         */
        $declared = config("stats.bases.{$statId}");

        if ($declared !== null) {
            return (float) $declared;
        }

        $formula = config("stats.formulas.{$statId}");

        if ($formula === null) {
            throw new \InvalidArgumentException("No base value known for stat [{$statId}].");
        }

        // Resolved inputs, not columns. This line is the whole class.
        return Character::applyFormula((array) $formula, $this->inputValues(array_keys((array) $formula)));
    }

    /**
     * A skill's level, or 0.
     *
     * Zero for a skill the character has never injected, which is the same
     * number as a skill injected and untrained — and that is FINE, because the
     * gate is `Character::hasInjected()` and never a level. The two states are
     * different facts and only one of them is a stat.
     */
    private function skillLevel(string $slug): float
    {
        $skill = self::skills()->get($slug);

        if ($skill === null) {
            return 0.0;
        }

        return (float) ($this->levels()[$skill->id] ?? 0);
    }

    /**
     * Every level this character has, in one query.
     *
     * A row per skill would be a query per skill, and a screen showing a dozen
     * checks would make a dozen of them.
     *
     * @return array<int, int>
     */
    private function levels(): array
    {
        return $this->levels ??= $this->character
            ->characterSkills()
            ->pluck('attained_level', 'skill_id')
            ->map(static fn ($level): int => (int) $level)
            ->all();
    }

    private function checkBase(string $slug): float
    {
        $skill = self::skills()->get($slug);

        if ($skill === null) {
            return 0.0;
        }

        return SkillStats::checkValue($skill, $this->inputValues(
            $this->graph->inputsFor(SkillStats::checkId($slug))
        ));
    }

    /**
     * @param  list<string>  $statIds
     * @return array<string, float>
     */
    private function inputValues(array $statIds): array
    {
        $values = [];

        foreach ($statIds as $statId) {
            $values[$statId] = $this->get($statId)->value;
        }

        return $values;
    }

    /** @return list<Modifier> */
    private function modifiers(): array
    {
        return $this->modifiers ??= $this->character->modifiers();
    }

    /** @return Collection<int, Skill> */
    private static function skills()
    {
        return self::$skills ??= SkillStats::skills();
    }

    /** Test seam: the skill list is memoised for the process. */
    public static function forgetSkills(): void
    {
        self::$skills = null;
    }
}
