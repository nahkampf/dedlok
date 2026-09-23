<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Skill;
use App\Skills\SkillProgression;
use App\Support\Text;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An archetype's identity and the loadout it hands a new character.
 *
 * The columns are ordinary. The loadout is the part worth a class: it is two
 * sets of rows pointing at content that lives in other tables, and the rules
 * that matter are the ones a CHECK constraint cannot see from a single row.
 *
 * - **One grant per skill, one per item.** The unique indexes say the same
 *   thing; this says it in words, because "Hacking at 1 and Hacking at 3" is a
 *   data error rather than two grants.
 * - **A level above 0 has to be reachable.** A skill granted at level 2 whose
 *   prerequisite is not also granted describes a character in a state the
 *   training system could never have produced — the queue would have refused
 *   every minute of it. Level 0 is exempt on purpose: it is what injecting a
 *   imprint buys, and injection has never checked prerequisites.
 *
 * Neither `id` nor `slug` is editable, and both are refused rather than
 * ignored. An archetype id is written into every character row that chose it.
 */
class ArchetypeRequest extends FormRequest
{
    /** More than any archetype should hand out, and small enough to catch a loop. */
    private const MAX_GRANTS = 30;

    /** What an unsigned int column holds. */
    private const MAX_CREDS = 4_294_967_295;

    /** One archetype cannot hand a character a warehouse. */
    private const MAX_QUANTITY = 999;

    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'description' => $this->filled('description') ? $this->input('description') : null,
            // An unchecked checkbox is absent rather than false, and absent
            // would leave a withdrawn archetype on the menu.
            'playable' => $this->boolean('playable'),
            // A form with every row removed sends nothing at all, which has to
            // mean "grant nothing" rather than "leave the loadout be".
            'skills' => $this->rows('skills', ['skill_id', 'level']),
            'items' => $this->rows('items', ['item_id', 'quantity']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $progression = SkillProgression::fromConfig();

        return [
            // Permanent, and refused rather than dropped: a hand-rolled POST
            // asking to renumber an archetype is asking to re-role every
            // character holding the old id.
            'id' => ['prohibited'],
            'slug' => ['prohibited'],

            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:2000'],

            'starting_creds' => ['required', 'integer', 'min:0', 'max:'.self::MAX_CREDS],

            // Whether a new character may be one. Withdrawing an archetype is
            // this going false — never a delete, since character rows point at
            // the id.
            'playable' => ['boolean'],

            'skills' => ['array', 'max:'.self::MAX_GRANTS],
            'skills.*.skill_id' => [
                'required', 'integer', Rule::exists('skills', 'id'),
                // The unique index on (archetype_id, skill_id), said in words.
                'distinct',
            ],
            // 0 is a real level — injected and untrained — so the range starts
            // there rather than at 1, unlike a prerequisite's.
            'skills.*.level' => ['required', 'integer', 'between:0,'.$progression->maxLevel()],

            'items' => ['array', 'max:'.self::MAX_GRANTS],
            'items.*.item_id' => ['required', 'integer', Rule::exists('items', 'id'), 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.prohibited' => Text::get('admin.validation.slug_immutable'),
            'skills.*.skill_id.distinct' => Text::get('admin.validation.granted_twice'),
            'items.*.item_id.distinct' => Text::get('admin.validation.granted_twice'),
        ];
    }

    /**
     * The rule that needs the skill graph rather than the row.
     *
     * A grant above level 0 has to be a level the character could have got to.
     * Everything a granted skill requires must be granted as well, at least as
     * far — otherwise the archetype mints a character whose own training queue
     * would refuse to continue the skill it started them on.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;   // The ids may not exist; nothing to look up.
            }

            $granted = [];

            foreach ($this->skills() as $grant) {
                $granted[$grant['skill_id']] = $grant['level'];
            }

            $trained = array_filter($granted, static fn (int $level): bool => $level > 0);

            if ($trained === []) {
                return;
            }

            $skills = Skill::query()
                ->with('prerequisites.requiredSkill:id,title')
                ->whereIn('id', array_keys($trained))
                ->get();

            foreach ($skills as $skill) {
                foreach ($skill->prerequisites as $prerequisite) {
                    if (($granted[$prerequisite->required_skill_id] ?? 0) >= $prerequisite->required_level) {
                        continue;
                    }

                    $validator->errors()->add('skills', Text::get('admin.validation.grant_needs_prerequisite', [
                        'skill' => $skill->title,
                        'level' => $trained[$skill->id],
                        'requires' => $prerequisite->requiredSkill?->title ?? $prerequisite->required_skill_id,
                        'required' => $prerequisite->required_level,
                    ]));
                }
            }
        });
    }

    /**
     * The row's own columns, without the loadout — those are rows in two other
     * tables and App\Archetypes\LoadoutEditor writes them.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        unset($data['skills'], $data['items'], $data['id'], $data['slug']);

        return $data;
    }

    /**
     * @return list<array{skill_id: int, level: int}>
     */
    public function skills(): array
    {
        return array_map(
            static fn (array $row): array => [
                'skill_id' => (int) $row['skill_id'],
                'level' => (int) $row['level'],
            ],
            $this->validated()['skills'] ?? [],
        );
    }

    /**
     * @return list<array{item_id: int, quantity: int}>
     */
    public function items(): array
    {
        return array_map(
            static fn (array $row): array => [
                'item_id' => (int) $row['item_id'],
                'quantity' => (int) $row['quantity'],
            ],
            $this->validated()['items'] ?? [],
        );
    }

    /**
     * Drop the blank rows a repeater sends and reindex.
     *
     * A gap in the keys turns the array into an object, and `distinct` stops
     * seeing a list — the same trap SkillRequest documents for prerequisites.
     *
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function rows(string $field, array $keys): array
    {
        $rows = [];

        foreach ((array) $this->input($field, []) as $row) {
            if (! is_array($row) || ! isset($row[$keys[0]]) || $row[$keys[0]] === '') {
                continue;
            }

            $rows[] = array_combine($keys, array_map(
                static fn (string $key) => $row[$key] ?? null,
                $keys,
            ));
        }

        return $rows;
    }
}
