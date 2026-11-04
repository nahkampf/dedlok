<?php

declare(strict_types=1);

namespace App\Admin;

use App\Stats\Modifier;
use App\Stats\Op;
use App\Stats\StatRegistry;
use App\Support\Text;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Validator;

/**
 * Editing a list of modifiers, wherever one is stored.
 *
 * Four tables carry the same JSON — `items.modifiers`, `programs.modifiers`,
 * `skills.modifiers` and `archetype_perks.modifiers` — and until now every one
 * of them was authored by hand: a textarea in the item editor, and a migration
 * for everything else. That is a content pipeline that only a programmer can
 * use, for the half of the game docs/MECHANICS.md insists must be DATA.
 *
 * So the shape is here once, and the screens hand it a field name. What it
 * knows:
 *
 *  - **What can be targeted.** Every declared stat, grouped by its leading tag,
 *    plus the tag SETS somebody has bothered to name — see `targets()`.
 *  - **How a posted row becomes a definition**, including the extra keys a
 *    particular table wants: `skills.modifiers` carries a `level` that says
 *    from which level the grant applies, and `App\Stats\Sources\SkillEffects`
 *    strips it before building.
 *  - **How to judge one**, which is by BUILDING it. `Modifier::fromArray()`
 *    refuses unknown ops, malformed targets and misspelled predicates already;
 *    anything weaker here would be a second, laxer copy of the rules.
 *
 * WHAT IT DELIBERATELY DOES NOT DO is edit a condition, a priority or a source.
 * Those are real keys on a modifier and rare in content; a row that carries one
 * is shown but not taken apart, and travels back through a hidden field
 * untouched. **The alternative was silently dropping them on save**, which is
 * the kind of data loss nobody notices until a drug stops wearing off.
 */
final class ModifierField
{
    /** Keys this editor renders. Everything else on a row is carried, not shown. */
    public const EDITED = ['op', 'amount', 'target'];

    /**
     * The targets a modifier may name, grouped for a picker.
     *
     * Two kinds in one list, because a person choosing does not care which is
     * which: a STAT is one thing by id, and a GROUP is a set of tags — "all
     * damage", "physical damage taken". The groups are exactly the ones with a
     * player-facing name in `character.names`, which is a self-limiting rule
     * worth having: a set nobody has named cannot be rendered on a card, so
     * offering it here would be offering a modifier whose effect is
     * undescribable.
     *
     * @return array<string, array<string, string>> group => [value => label]
     */
    public static function targets(): array
    {
        $registry = app(StatRegistry::class);
        $names = (array) Config::get('strings.character.names', []);

        $groups = [];

        foreach ($registry->ids() as $id) {
            $tag = $registry->get($id)->tags[0] ?? 'other';
            $groups[Text::get("admin.modifiers.groups.{$tag}")][$id] = $names[$id] ?? $id;
        }

        foreach ($names as $key => $label) {
            if (! $registry->has((string) $key)) {
                $groups[Text::get('admin.modifiers.groups.sets')][$key] = $label;
            }
        }

        foreach ($groups as &$options) {
            asort($options);
        }

        return $groups;
    }

    /** The ops, labelled. */
    public static function ops(): array
    {
        $ops = [];

        foreach (Op::cases() as $op) {
            $ops[$op->value] = Text::get("admin.modifiers.ops.{$op->value}");
        }

        return $ops;
    }

    /**
     * Posted rows to modifier definitions.
     *
     * A row with nothing chosen is dropped rather than refused: an editor that
     * makes you tidy up after yourself before it will save is an editor people
     * stop opening.
     *
     * @param  list<string>  $extras  Extra keys this table carries, e.g. ['level'].
     * @return list<array<string, mixed>>
     */
    public static function normalise(mixed $rows, array $extras = []): array
    {
        if (is_string($rows)) {
            // The old shape: a textarea of raw JSON. Still accepted, because a
            // payload written before this editor existed is not wrong.
            $decoded = json_decode($rows, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        if (! is_array($rows)) {
            return [];
        }

        $definitions = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $op = trim((string) ($row['op'] ?? ''));
            $target = trim((string) ($row['target'] ?? ''));

            if ($op === '' && $target === '' && trim((string) ($row['amount'] ?? '')) === '') {
                continue;
            }

            $definition = [
                'op' => $op,
                'amount' => self::number($row['amount'] ?? null),
                'target' => self::target($target),
            ];

            foreach ($extras as $extra) {
                $value = $row[$extra] ?? null;

                if ($value !== null && $value !== '') {
                    $definition[$extra] = self::number($value);
                }
            }

            // Whatever this editor does not render travels in a hidden field
            // and comes back untouched. Appended rather than merged over, so a
            // row reads in the order it was written the first time.
            $carried = json_decode((string) ($row['carried'] ?? ''), true);

            $definitions[] = is_array($carried) ? [...$definition, ...$carried] : $definition;
        }

        return $definitions;
    }

    /**
     * Judge each definition by building it, and say which row is wrong.
     *
     * The index is one-based in the message, because the person reading it is
     * counting rows on a screen rather than offsets in an array.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @param  list<string>  $extras
     */
    public static function validate(Validator $validator, string $field, array $definitions, array $extras = []): void
    {
        foreach ($definitions as $index => $definition) {
            if (! is_array($definition)) {
                $validator->errors()->add($field, Text::get('admin.validation.modifier_shape'));

                continue;
            }

            foreach ($extras as $extra) {
                if (isset($definition[$extra]) && ! is_numeric($definition[$extra])) {
                    $validator->errors()->add($field, Text::get('admin.validation.modifier_invalid', [
                        'index' => (int) $index + 1,
                        'reason' => "[{$extra}] has to be a number.",
                    ]));
                }
            }

            try {
                $modifier = Modifier::fromArray(array_diff_key($definition, array_flip($extras)));
            } catch (\InvalidArgumentException $e) {
                $validator->errors()->add($field, Text::get('admin.validation.modifier_invalid', [
                    'index' => (int) $index + 1,
                    'reason' => $e->getMessage(),
                ]));

                continue;
            }

            /*
             * AND IT HAS TO REACH SOMETHING. Modifier::fromArray() judges the
             * shape; nothing in it knows which stats exist, so a mistyped id
             * and a tag nothing wears both build perfectly and then modify
             * nothing for ever. This is the half that would otherwise only be
             * noticed as a perk that does not work.
             */
            if (app(StatRegistry::class)->matching($modifier->target) === []) {
                $validator->errors()->add($field, Text::get('admin.validation.modifier_invalid', [
                    'index' => (int) $index + 1,
                    'reason' => Text::get('admin.validation.modifier_target', [
                        'target' => $modifier->target->describe(),
                    ]),
                ]));
            }
        }
    }

    /**
     * Stored definitions back into rows a form can render.
     *
     * @param  array<int, array<string, mixed>>|null  $definitions
     * @param  list<string>  $extras
     * @return list<array<string, mixed>>
     */
    public static function rows(?array $definitions, array $extras = []): array
    {
        $rows = [];

        foreach ($definitions ?? [] as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $row = [
                'op' => (string) ($definition['op'] ?? ''),
                'amount' => (string) ($definition['amount'] ?? ''),
                'target' => self::targetKey($definition['target'] ?? null),
                'carried' => [],
            ];

            foreach ($extras as $extra) {
                $row[$extra] = (string) ($definition[$extra] ?? '');
            }

            $carried = array_diff_key($definition, array_flip([...self::EDITED, ...$extras]));

            if ($carried !== []) {
                $row['carried'] = $carried;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * A target as one string, which is what a select can hold.
     *
     * A stat is its id; a set is its tags joined, in the order Target::nameKey()
     * sorts them, so the value a picker offers is the value a name is looked up
     * under.
     */
    public static function targetKey(mixed $target): string
    {
        if (! is_array($target)) {
            return '';
        }

        if (isset($target['id'])) {
            return (string) $target['id'];
        }

        $tags = (array) ($target['all_of'] ?? []);
        sort($tags);

        return implode('+', $tags);
    }

    /**
     * One posted string back into a target.
     *
     * A DECLARED STAT IS AN ID, and anything that is plainly a tag set — more
     * than one tag, or one that stats actually carry — is a set. Everything
     * else stays an ID and fails validation below, which is the point: a
     * mistyped stat that quietly became `all_of: ['hitpints_max']` would build,
     * resolve, match nothing and change nothing, for ever, in silence.
     *
     * @return array<string, mixed>
     */
    private static function target(string $value): array
    {
        if ($value === '') {
            return [];
        }

        if (app(StatRegistry::class)->has($value)) {
            return ['id' => $value];
        }

        $tags = array_values(array_filter(explode('+', $value)));

        if (count($tags) > 1 || (count($tags) === 1 && in_array($tags[0], self::knownTags(), true))) {
            return ['all_of' => $tags];
        }

        return ['id' => $value];
    }

    /**
     * Every tag any declared stat carries.
     *
     * The vocabulary a set may be built from — a tag nothing wears is a target
     * that can never match, which is worth refusing rather than storing.
     *
     * @return list<string>
     */
    private static function knownTags(): array
    {
        $registry = app(StatRegistry::class);
        $tags = [];

        foreach ($registry->ids() as $id) {
            foreach ($registry->get($id)->tags as $tag) {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }

    /** Numbers arrive from a form as strings, and -5 must not become "-5". */
    private static function number(mixed $value): float|int|string
    {
        if (! is_numeric($value)) {
            return is_string($value) ? $value : '';
        }

        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }
}
