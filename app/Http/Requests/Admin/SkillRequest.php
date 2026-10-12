<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Admin\ModifierField;
use App\Models\Character;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Skills\PrerequisiteGraph;
use App\Skills\SkillProgression;
use App\Support\Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A skill definition and its prerequisites.
 *
 * The columns are the easy half; the prerequisites are the reason this class
 * is more than a rule array. They are a graph, and the two things that make a
 * graph wrong cannot be seen from one row:
 *
 * - A skill requiring itself, which the schema does catch (chk_prereq_not_self).
 * - A CYCLE, which it cannot. Nothing in "A needs B" and "B needs A" is wrong
 *   on its own; together they are two skills nobody can ever learn. That check
 *   runs in withValidator(), against the graph as it WOULD be once saved —
 *   see App\Skills\PrerequisiteGraph.
 */
class SkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /** The extra key a skill's modifiers carry beyond a plain definition. */
    private const EXTRAS = ['level'];

    protected function prepareForValidation(): void
    {
        $this->merge([
            'on_market' => $this->boolean('on_market'),
            'description' => $this->filled('description') ? $this->input('description') : null,
            // A form with every prerequisite row removed sends nothing at all,
            // which has to mean "no prerequisites" rather than "leave them be".
            'prerequisites' => $this->normalisedPrerequisites(),
            // What KNOWING this skill grants, read by
            // App\Stats\Sources\SkillEffects. Each row carries the level it
            // starts applying from, which is the extra key this table has and
            // the source strips before building.
            'modifiers' => ModifierField::normalise($this->input('modifiers'), self::EXTRAS),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $progression = SkillProgression::fromConfig();

        return [
            // Permanent, like an item's: effects, requirements and saved data
            // reference it, and the skill's imprint is named from it.
            'slug' => $this->isCreating()
                ? ['required', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/', Rule::unique('skills', 'slug')]
                : ['prohibited'],

            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:255'],

            // Rank multiplies every threshold, so it is one of a fixed set
            // rather than any number: chk_skills_rank says the same thing.
            'rank' => ['required', 'integer', Rule::in($progression->ranks())],

            'primary_attribute' => ['required', Rule::in(Character::ATTRIBUTES)],
            'secondary_attribute' => ['required', Rule::in(Character::ATTRIBUTES), 'different:primary_attribute'],

            'base_cost' => ['required', 'integer', 'min:0'],
            'on_market' => ['boolean'],

            /*
             * What having the skill grants — not the check it feeds, which is
             * a generated stat and belongs to nobody. Judged by BUILDING each
             * one in withValidator(), because content authored here is content
             * no test sees: a typo in a target id is a 500 on the character
             * sheet of everybody who has the skill.
             */
            'modifiers' => ['nullable', 'array'],

            'prerequisites' => ['array', 'max:20'],
            'prerequisites.*.required_skill_id' => [
                'required', 'integer', Rule::exists('skills', 'id'),
                // The schema's chk_prereq_not_self, said in words.
                // 0 is never an id, so a skill being CREATED still gets a
                // well-formed rule rather than an empty not_in list.
                Rule::notIn([$this->skill()?->id ?? 0]),
                // The unique index on (skill_id, required_skill_id): "needs
                // Cryptography 3 and Cryptography 5" is a data error, not two
                // requirements.
                'distinct',
            ],
            'prerequisites.*.required_level' => [
                'required', 'integer', 'between:1,'.$progression->maxLevel(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => Text::get('admin.validation.slug'),
            'slug.prohibited' => Text::get('admin.validation.slug_immutable'),
            'secondary_attribute.different' => Text::get('admin.validation.same_attribute'),
            'prerequisites.*.required_skill_id.not_in' => Text::get('admin.validation.requires_itself'),
            'prerequisites.*.required_skill_id.distinct' => Text::get('admin.validation.requires_twice'),
        ];
    }

    /**
     * The check no single row can fail: would saving this close a loop?
     *
     * Asked of the graph as it would be AFTER the save — every edge in the
     * database, with this skill's own requirements replaced by the ones being
     * submitted. A skill being created has no id yet, so it gets a synthetic
     * one that cannot collide; it is only ever compared, never stored.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->has('modifiers')) {
                ModifierField::validate($validator, 'modifiers', (array) $this->input('modifiers', []), self::EXTRAS);
            }
        });

        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;   // The ids may not even exist yet; nothing to walk.
            }

            $required = array_map(
                static fn (array $row): int => (int) $row['required_skill_id'],
                $this->input('prerequisites', []),
            );

            if ($required === []) {
                return;
            }

            $id = $this->skill()?->id ?? PHP_INT_MAX;

            $graph = PrerequisiteGraph::fromRows(
                SkillPrerequisite::all(['skill_id', 'required_skill_id'])->toArray()
            )->with($id, $required);

            $cycle = $graph->cycleThrough($id);

            if ($cycle === []) {
                return;
            }

            $validator->errors()->add('prerequisites', Text::get('admin.validation.cycle', [
                'path' => $this->describeCycle($cycle, $id),
            ]));
        });
    }

    /** The row being edited, or null on create. */
    public function skill(): ?Skill
    {
        $skill = $this->route('skill');

        return $skill instanceof Skill ? $skill : null;
    }

    public function isCreating(): bool
    {
        return $this->skill() === null;
    }

    /**
     * The validated columns, without the prerequisites — those are rows in
     * another table and the controller writes them separately.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        unset($data['prerequisites']);

        // Null rather than [], the same as an item's: a skill that grants
        // nothing genuinely grants nothing.
        $data['modifiers'] = ($data['modifiers'] ?? []) === [] ? null : array_values((array) $data['modifiers']);

        if (! $this->isCreating()) {
            unset($data['slug']);
        }

        return $data;
    }

    /**
     * @return list<array{required_skill_id: int, required_level: int}>
     */
    public function prerequisites(): array
    {
        return array_map(
            static fn (array $row): array => [
                'required_skill_id' => (int) $row['required_skill_id'],
                'required_level' => (int) $row['required_level'],
            ],
            $this->validated()['prerequisites'] ?? [],
        );
    }

    /**
     * Drop the blank rows an empty repeater sends, and reindex — a gap in the
     * keys turns the array into an object and `distinct` stops seeing a list.
     *
     * @return list<array{required_skill_id: mixed, required_level: mixed}>
     */
    private function normalisedPrerequisites(): array
    {
        $rows = $this->input('prerequisites');

        if (! is_array($rows)) {
            return [];
        }

        $kept = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! filled($row['required_skill_id'] ?? null)) {
                continue;
            }

            $kept[] = [
                'required_skill_id' => $row['required_skill_id'],
                'required_level' => $row['required_level'] ?? null,
            ];
        }

        return $kept;
    }

    /**
     * The loop, named in slugs. The skill being saved may have no slug yet, so
     * it reads as the title the admin just typed.
     *
     * @param  list<int>  $cycle
     */
    private function describeCycle(array $cycle, int $id): string
    {
        $slugs = Skill::whereIn('id', $cycle)->pluck('slug', 'id');
        $mine = $this->skill()?->slug ?? (string) $this->input('slug', $this->input('title', '?'));

        return implode(' → ', array_map(
            static fn (int $node): string => $node === $id ? $mine : ($slugs[$node] ?? "#{$node}"),
            $cycle,
        ));
    }
}
