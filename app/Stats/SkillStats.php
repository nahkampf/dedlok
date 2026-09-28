<?php

declare(strict_types=1);

namespace App\Stats;

use App\Models\Character;
use App\Models\Skill;
use App\Skills\SkillProgression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Every skill is a stat, and every skill implies a check.
 *
 * Two stats per skill row, generated rather than declared:
 *
 *   skill.<slug>   the trained level, 0..max. A modifier can target it, so
 *                  "counts as one level higher in every Systems skill" is one
 *                  modifier rather than a special case in training code.
 *   check.<slug>   the number a roll is made against, derived from the skill's
 *                  OWN governing attributes and its level.
 *
 * They are generated for the same reason imprints are: there are hundreds of
 * skills, they live in the database, and a per-skill entry in config/stats.php
 * would be a second place to remember. Adding a skill adds its stat and its
 * check for free, exactly as it adds its imprint.
 *
 * WHAT A CHECK IS NOT: it is not permission. A `check.*` value exists for every
 * skill in the game whether or not the character has ever injected it, and it
 * resolves to whatever the formula gives for level 0. The gate is
 * `Character::hasInjected()` and it always was — a level test has never been
 * the same question, and reading a check value as "may they attempt this" would
 * quietly let anyone attempt anything.
 */
final class SkillStats
{
    /** @var Collection<string, Skill>|null  see skills() */
    private static ?Collection $roster = null;

    public const SKILL_PREFIX = 'skill.';

    public const CHECK_PREFIX = 'check.';

    /** Cleared by the Skill model, the way the archetype roster is. */
    public const CACHE_KEY = 'stats.skills.definitions';

    public static function skillId(string $slug): string
    {
        return self::SKILL_PREFIX.$slug;
    }

    public static function checkId(string $slug): string
    {
        return self::CHECK_PREFIX.$slug;
    }

    /** The slug behind a generated stat id, or null if it is not one. */
    public static function slugOf(string $statId): ?string
    {
        foreach ([self::SKILL_PREFIX, self::CHECK_PREFIX] as $prefix) {
            if (str_starts_with($statId, $prefix)) {
                return substr($statId, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * Stat declarations for every skill, in the shape config/stats.php uses.
     *
     * Read on nearly every request once anything resolves a check, and the
     * answer is the same handful of rows for every character in the game — so
     * it is cached like Archetype::roster(), and invalidated by the Skill model
     * rather than by whoever remembers.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        /** @var array<string, array<string, mixed>> */
        return Cache::rememberForever(self::CACHE_KEY, static fn (): array => self::build());
    }

    /**
     * Drop the generated stats, and the two singletons built from them.
     *
     * Forgetting the cache alone is not enough. `StatRegistry` and `StatGraph`
     * are container singletons composed from these definitions, so anything
     * still holding one would answer with the skill list as it was — a new
     * skill's `check.*` would come back "Unknown stat" from the very request
     * that created it. Harmless in a normal request, which builds a fresh
     * container; wrong in a queue worker, a long-lived process, or a test.
     *
     * The Skill model calls this on save and delete, so a seeder, a tinker
     * session and the admin editor all stay in step without knowing any of it.
     * Sheet's own memo of the skill list goes with them, for the same reason.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::$roster = null;

        // Resolver is in the list because it HOLDS a StatRegistry rather than
        // resolving one per call — forgetting the registry alone would leave
        // the resolver answering from the copy it was constructed with.
        foreach ([StatRegistry::class, StatGraph::class, Resolver::class] as $singleton) {
            if (app()->bound($singleton)) {
                app()->forgetInstance($singleton);
            }
        }

        Sheet::forgetSkills();
    }

    /**
     * How each check reads its inputs. Same shape as `stats.formulas`, but
     * weights rather than divisors — an attribute runs 0..100 and a skill level
     * runs 0..5, so a check that summed them straight would be an attribute
     * with a rounding error attached.
     *
     * @return array<string, list<string>> check stat id => the stat ids it reads
     */
    public static function dependencies(): array
    {
        $graph = [];

        foreach (self::skills() as $skill) {
            $inputs = [self::skillId($skill->slug), $skill->primary_attribute];

            if ($skill->secondary_attribute !== null && $skill->secondary_attribute !== $skill->primary_attribute) {
                $inputs[] = $skill->secondary_attribute;
            }

            $graph[self::checkId($skill->slug)] = $inputs;
        }

        return $graph;
    }

    /**
     * A check's value from its already-resolved inputs.
     *
     * Weights are config, not code: this is the number every roll in cyberspace
     * is made against, and it is the first thing anyone will want to tune.
     *
     * @param  array<string, float>  $resolved  stat id => resolved value
     */
    public static function checkValue(Skill $skill, array $resolved): float
    {
        $weights = (array) config('stats.checks', []);

        $value = ($resolved[self::skillId($skill->slug)] ?? 0.0) * (float) ($weights['level_weight'] ?? 0);
        $value += ($resolved[$skill->primary_attribute] ?? 0.0) * (float) ($weights['primary_weight'] ?? 0);

        if ($skill->secondary_attribute !== null) {
            $value += ($resolved[$skill->secondary_attribute] ?? 0.0) * (float) ($weights['secondary_weight'] ?? 0);
        }

        return $value;
    }

    /**
     * The skill roster the stat graph is built from, read once per process.
     *
     * The graph and every Sheet both need it, and asked separately it was two
     * identical queries on every page. `forget()` drops it with everything
     * else built from the list, so a saved skill is seen at once.
     *
     * @return Collection<string, Skill> keyed by slug
     */
    public static function skills()
    {
        return self::$roster ??= Skill::query()
            ->get(['id', 'slug', 'title', 'category', 'rank', 'primary_attribute', 'secondary_attribute'])
            ->keyBy('slug');
    }

    /** @return array<string, array<string, mixed>> */
    private static function build(): array
    {
        $maxLevel = SkillProgression::fromConfig()->maxLevel();
        $definitions = [];

        foreach (self::skills() as $skill) {
            $category = Str::slug((string) $skill->category);

            /*
             * A skill's level is bounded by the progression's own ceiling, so a
             * modifier can raise one but never past what training could reach.
             * Floored at 0: untrained is the bottom, and "less than untrained"
             * is not a state the game has.
             */
            $definitions[self::skillId($skill->slug)] = [
                'tags' => array_values(array_unique(['skill', $category])),
                'min' => 0,
                'max' => $maxLevel,
                'rounding' => 'floor',
            ];

            /*
             * A check is unbounded above on purpose. It is not a percentage and
             * nothing reads it as one — what turns a check into a probability
             * is whatever rolls it, against an opposing number, under the floor
             * in config/checks.php. Capping it here would silently flatten
             * every advantage past the cap.
             */
            $definitions[self::checkId($skill->slug)] = [
                'tags' => array_values(array_unique([
                    'check',
                    $category,
                    in_array($skill->primary_attribute, Character::PHYSICAL_ATTRIBUTES, true)
                        ? 'physical'
                        : 'mental',
                ])),
                'min' => 0,
                'max' => null,
                'rounding' => 'floor',
            ];
        }

        return $definitions;
    }
}
