<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * The named tests a conditional modifier may reference.
 *
 * MECHANICS.md is specific about this: a condition names a PREDICATE, never an
 * inline expression. An expression in a config file is code in a place nothing
 * type-checks, reviews or tests; a name is a thing that either exists or fails
 * loudly at the moment the modifier is built.
 *
 * Adding one is a case here and an entry in the table. Predicates read a
 * Context — plain values — so they stay pure and testable, and so the same
 * predicate works for a character, for a hypothetical, and for a run the
 * simulator is part-way through.
 */
final class Predicates
{
    /**
     * name => [required context keys, the test itself]
     *
     * @var array<string, callable(Context, array<string, mixed>): bool>|null
     */
    private static ?array $registry = null;

    /** @return array<string, callable(Context, array<string, mixed>): bool> */
    private static function registry(): array
    {
        return self::$registry ??= [

            /*
             * The vitals, as a percentage of their resolved maximum. A ratio
             * rather than an absolute, so "under 30%" means the same thing to
             * a fresh character and to one who has doubled their ceiling.
             */
            'hp_below_pct' => static fn (Context $c, array $args): bool => self::below($c, 'hp_pct', $args),
            'si_below_pct' => static fn (Context $c, array $args): bool => self::below($c, 'si_pct', $args),
            'hp_above_pct' => static fn (Context $c, array $args): bool => self::above($c, 'hp_pct', $args),
            'si_above_pct' => static fn (Context $c, array $args): bool => self::above($c, 'si_pct', $args),

            /*
             * TRAINED TO AT LEAST THIS LEVEL.
             *
             * What lets a piece of software be worth more in trained hands
             * without the software knowing anything about skills: five copies
             * of the same modifier, each conditional on one more level, sum to
             * a bonus that scales with training. That is the only shape the
             * resolver supports for "per level of something else", because a
             * modifier's amount is a number and never an expression — and it is
             * the same shape a skill's own per-level grants already use.
             *
             * An absent key and a level of zero are the same answer, so a
             * character who has never injected the skill simply fails it.
             */
            'skill_at_least' => static fn (Context $c, array $args): bool => ($c->number('skill.'.((string) ($args['skill'] ?? ''))) ?? 0.0)
                    >= (float) ($args['level'] ?? 1),

            /*
             * Where the character is. Jacked in is the cyberspace session, not
             * signing in and not carrying a deck — see CYBERSPACE.md,
             * "Vocabulary". Nothing sets these yet; the predicate exists so the
             * first modifier that wants one does not have to invent it.
             */
            'jacked_in' => static fn (Context $c): bool => $c->flag('jacked_in'),
            'jacked_out' => static fn (Context $c): bool => ! $c->flag('jacked_in'),

            /*
             * Heat: what the world knows about you. A number with no ceiling,
             * so this one is an absolute rather than a ratio.
             */
            'heat_above' => static function (Context $c, array $args): bool {
                $heat = $c->number('heat');

                return $heat !== null && $heat > self::arg($args, 'value');
            },
        ];
    }

    public static function knows(string $name): bool
    {
        return isset(self::registry()[$name]);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::registry());
    }

    /**
     * Does this condition hold?
     *
     * An UNKNOWN key in the context means the predicate cannot answer, and the
     * answer is then FALSE — the modifier does not apply. That is the safe
     * direction: a conditional bonus that silently applies everywhere is a
     * balance bug nobody sees, and a conditional penalty that silently applies
     * everywhere is worse.
     *
     * @param  array<string, mixed>  $args
     */
    public static function test(string $name, Context $context, array $args = []): bool
    {
        $predicate = self::registry()[$name] ?? throw new \InvalidArgumentException(
            "Unknown condition predicate [{$name}]. Known predicates: ".implode(', ', self::names()).'.'
        );

        return $predicate($context, $args);
    }

    /** @param  array<string, mixed>  $args */
    private static function below(Context $context, string $key, array $args): bool
    {
        $value = $context->number($key);

        return $value !== null && $value * 100 < self::arg($args, 'pct');
    }

    /** @param  array<string, mixed>  $args */
    private static function above(Context $context, string $key, array $args): bool
    {
        $value = $context->number($key);

        return $value !== null && $value * 100 > self::arg($args, 'pct');
    }

    /** @param  array<string, mixed>  $args */
    private static function arg(array $args, string $name): float
    {
        if (! array_key_exists($name, $args) || ! is_numeric($args[$name])) {
            throw new \InvalidArgumentException("Condition is missing a numeric [{$name}] argument.");
        }

        return (float) $args[$name];
    }
}
