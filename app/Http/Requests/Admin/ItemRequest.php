<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Market\Rarity;
use App\Models\Item;
use App\Support\Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An item definition, checked before the database has to refuse it.
 *
 * Every rule here has a counterpart in the items table's CHECK constraints —
 * on purpose. The constraints are the guarantee; these are the explanation.
 * A form that hands back "the pair (use_op, use_skill_id) disagrees" is worth
 * having even though the database would have caught it, because what the
 * database returns is a 500 and a constraint name.
 *
 * Two rules here have NO counterpart in the schema, and both are cases SQL
 * cannot express against a single row:
 *
 * - One book per skill. `use_skill_id` is unique across items, because a
 *   second book teaching the same skill would make Skillbook::forSkill()
 *   ambiguous and give the skill two names.
 * - A container cannot stack. InventoryItem derives `stack_key` from
 *   (character, item, parent), so two stackable containers merge into one row
 *   with a quantity of 2 — and the contents of the second are gone.
 */
class ItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /**
     * Normalise what a form sends into what the columns hold.
     *
     * An unchecked checkbox is absent rather than false, and an empty <select>
     * option is '' rather than null. Both would otherwise reach rules that
     * mean something quite different by them.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'stackable' => $this->boolean('stackable'),
            'on_market' => $this->boolean('on_market'),
            // An empty text field is no value, not a value of ''. A skillbook
            // stores a null name; '' would be a name that renders as nothing.
            'name' => $this->filled('name') ? $this->input('name') : null,
            'use_op' => $this->filled('use_op') ? $this->input('use_op') : null,
            'use_skill_id' => $this->filled('use_skill_id') ? $this->input('use_skill_id') : null,
            'accepts' => $this->filled('accepts') ? $this->input('accepts') : null,
            'flavor' => $this->filled('flavor') ? $this->input('flavor') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $capacity = (int) $this->input('capacity', 0);
        $isSkillbook = $this->input('use_op') === Item::USE_INJECT_SKILL;

        return [
            // Only on create. A slug is permanent — inventory rows and the
            // skillbook naming both reference it — so update() never sees one.
            'slug' => $this->isCreating()
                ? ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', Rule::unique('items', 'slug')]
                : ['prohibited'],

            // A skillbook has no name of its own: it composes from the skill
            // it teaches, and storing one would be a second copy of the
            // skill's title waiting to disagree with it.
            'name' => $isSkillbook
                ? ['nullable', 'string', 'max:255']
                : ['required', 'string', 'max:255'],

            'flavor' => ['nullable', 'string', 'max:2000'],

            'container' => ['required', Rule::in(Item::CONTAINERS)],
            'tier' => ['required', 'integer', 'between:1,'.Item::MAX_TIER],
            'rarity' => ['required', Rule::enum(Rarity::class)],

            // See the class comment: a container that stacks loses its
            // contents the moment a player owns two of them.
            'stackable' => $capacity > 0 ? ['boolean', 'declined'] : ['boolean'],

            'use_op' => ['nullable', Rule::in(Item::USE_OPS)],
            'use_skill_id' => $isSkillbook
                ? [
                    'required', 'integer', Rule::exists('skills', 'id'),
                    Rule::unique('items', 'use_skill_id')->ignore($this->item()?->id),
                ]
                : ['prohibited'],

            'weight' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'size' => ['required', 'integer', 'min:0'],

            'capacity' => ['required', 'integer', 'min:0'],
            // The other half of chk_items_capacity_pair. Capacity without a
            // kind holds anything; a kind without capacity holds nothing while
            // claiming otherwise. Neither is a container.
            'accepts' => $capacity > 0
                ? ['required', Rule::in(Item::CONTAINERS)]
                : ['prohibited'],

            'base_cost' => ['required', 'integer', 'min:0'],
            'on_market' => ['boolean'],
            'seed_quantity' => ['required', 'integer', 'min:0'],
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
            'name.required' => Text::get('admin.validation.name_required'),
            'stackable.declined' => Text::get('admin.validation.container_stacks'),
            'use_skill_id.required' => Text::get('admin.validation.book_needs_skill'),
            'use_skill_id.prohibited' => Text::get('admin.validation.skill_without_book'),
            'use_skill_id.unique' => Text::get('admin.validation.skill_has_a_book'),
            'accepts.required' => Text::get('admin.validation.capacity_needs_kind'),
            'accepts.prohibited' => Text::get('admin.validation.kind_needs_capacity'),
        ];
    }

    /** The row being edited, or null on create. */
    public function item(): ?Item
    {
        $item = $this->route('item');

        return $item instanceof Item ? $item : null;
    }

    public function isCreating(): bool
    {
        return $this->item() === null;
    }

    /**
     * The validated payload, as columns to fill.
     *
     * Not attributes(): FormRequest already defines that, and it means the
     * display NAMES the validator puts in its messages.
     *
     * `slug` is stripped on update rather than merely unvalidated: it is
     * prohibited above, so it cannot arrive, and this makes that explicit at
     * the point the row is written.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        if (! $this->isCreating()) {
            unset($data['slug']);
        }

        return $data;
    }
}
