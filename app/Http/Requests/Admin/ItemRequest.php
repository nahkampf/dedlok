<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Decks\Hardware;
use App\Decks\SlotKind;
use App\Decks\SlotLayout;
use App\Decks\SlotType;
use App\Decks\Variant;
use App\Market\Rarity;
use App\Models\Item;
use App\Support\Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An item definition, checked before the database has to refuse it.
 *
 * Every rule here has a counterpart in the items table's CHECK constraints —
 * on purpose. The constraints are the guarantee; these are the explanation.
 * A form that hands back "the pair (use_op, use_skill_id) disagrees" is worth
 * having even though the database would have caught it, because what the
 * database returns is a 500 and a constraint name.
 *
 * Some rules here have NO counterpart in the schema, because they are cases
 * SQL cannot express against a single row:
 *
 * - One book per skill. `use_skill_id` is unique across items, because a
 *   second book teaching the same skill would make Skillbook::forSkill()
 *   ambiguous and give the skill two names.
 * - A container cannot stack, and neither does a cyberdeck. InventoryItem
 *   derives `stack_key` from (character, item, parent), so two stackable
 *   containers merge into one row with a quantity of 2 — and the contents of
 *   the second are gone. A deck is the same case one step ahead of itself.
 *
 * The deck hardware adds a third kind of rule: one that spans rows the schema
 * cannot see from a single one. A deck's sockets are rows in `deck_slots`, so
 * no CHECK on any one of them can say "at most five expansion slots" or "a
 * deck has to have somewhere to put a CPU". Those live in withValidator().
 */
class ItemRequest extends FormRequest
{
    /**
     * The four columns a module may be measured by, at most one of which it
     * fills. The same list the schema's chk_deck_modules_one_measure counts.
     *
     * @var list<string>
     */
    private const MEASURES = ['qflops', 'quantabits', 'bandwidth', 'watts'];

    /** What an unsigned int column holds. A number above it is a typo. */
    private const MAX_MEASURE = 4_294_967_295;

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
            'hardware' => $this->filled('hardware') ? $this->input('hardware') : null,
            'variant' => $this->filled('variant') ? $this->input('variant') : null,
            // Empty is no value. Every one of these is prohibited for some
            // kind of item, and `prohibited` passes on null but not on ''.
            'power_draw' => $this->numberOrNull('power_draw'),
            'qflops' => $this->numberOrNull('qflops'),
            'quantabits' => $this->numberOrNull('quantabits'),
            'bandwidth' => $this->numberOrNull('bandwidth'),
            'watts' => $this->numberOrNull('watts'),
            // The socket counters, with the empty ones dropped. The editor
            // renders all fourteen whatever kind of item is being edited, so
            // a form full of zeroes has to arrive as "no sockets asked for"
            // rather than as fourteen requests for none — otherwise a CPU
            // could never be saved, its slots being prohibited and present.
            'slots' => $this->positiveCounts(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $capacity = (int) $this->input('capacity', 0);
        $isSkillbook = $this->input('use_op') === Item::USE_INJECT_SKILL;
        $hardware = $this->hardware();

        return array_merge([
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

            // Hardware is matter: a cyberdeck and every module that goes in
            // one is something you carry, never something written to a deck.
            'container' => $hardware === null
                ? ['required', Rule::in(Item::CONTAINERS)]
                : ['required', Rule::in([Item::CONTAINER_PHYSICAL])],
            'tier' => ['required', 'integer', 'between:1,'.Item::MAX_TIER],
            'rarity' => ['required', Rule::enum(Rarity::class)],

            // See the class comment: a container that stacks loses its
            // contents the moment a player owns two of them. A deck is the
            // same case one step ahead of itself — its sockets are what will
            // be filled per object, so two decks can never share a row.
            'stackable' => $capacity > 0 || $hardware?->isDeck()
                ? ['boolean', 'declined']
                : ['boolean'],

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

            // What kind of deck hardware this is, if any. NULL is most of
            // the catalogue and is the default.
            'hardware' => ['nullable', Rule::enum(Hardware::class)],

            // A deck says what sockets it has; nothing else may.
            'slots' => $hardware?->isDeck()
                ? ['required', 'array']
                : ['prohibited'],
        ], $this->slotCountRules(), $this->moduleRules($hardware));
    }

    /**
     * One rule per socket type, capped at what a deck may present of it.
     *
     * Per type rather than one `slots.*`, because the caps differ: five
     * expansion slots, but only one neuralink port. The cap that spans the
     * variants of a kind is withValidator()'s, since no per-field rule can
     * see the other fields.
     *
     * @return array<string, mixed>
     */
    private function slotCountRules(): array
    {
        $rules = [];

        foreach (SlotType::all() as $type) {
            // min:1 rather than min:0 — the zeroes were dropped on the way in,
            // so anything still here is a request for at least one socket.
            $rules['slots.'.$type->key()] = ['integer', 'min:1', 'max:'.$type->max()];
        }

        return $rules;
    }

    /**
     * The module's own columns, which depend entirely on what kind it is.
     *
     * Every field is prohibited for every kind that does not use it, rather
     * than merely ignored: the deck_modules CHECK constraint allows at most
     * one measure, and a CPU that arrived carrying a wattage is a form that
     * has been tampered with or a bug — either way, silently dropping the
     * number is the answer that teaches nobody anything.
     *
     * With no hardware at all every one of them is prohibited, which is what
     * makes the editor's hidden fields harmless: they post empty, and empty
     * is not present.
     *
     * @return array<string, mixed>
     */
    private function moduleRules(?Hardware $hardware): array
    {
        $variants = $hardware?->variants() ?? [];

        $rules = [
            'variant' => $variants === []
                ? ['prohibited']
                : ['required', Rule::in(array_map(
                    static fn (Variant $variant): string => $variant->value,
                    $variants,
                ))],

            // A supply gives power and draws none — chk_deck_modules_supply.
            'power_draw' => $hardware?->draws()
                ? ['required', 'integer', 'min:0', 'max:'.self::MAX_MEASURE]
                : ['prohibited'],
        ];

        $measure = $hardware?->measure();

        foreach (self::MEASURES as $column) {
            $rules[$column] = $column === $measure
                ? ['required', 'integer', 'min:1', 'max:'.self::MAX_MEASURE]
                : ['prohibited'];
        }

        return $rules;
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
            'use_skill_id.required' => Text::get('admin.validation.book_needs_skill'),
            'use_skill_id.prohibited' => Text::get('admin.validation.skill_without_book'),
            'use_skill_id.unique' => Text::get('admin.validation.skill_has_a_book'),
            'accepts.required' => Text::get('admin.validation.capacity_needs_kind'),
            'accepts.prohibited' => Text::get('admin.validation.kind_needs_capacity'),

            // One field, two reasons to refuse it. A deck is unstackable
            // because of its sockets, a crate because of its contents, and
            // being told the wrong one is worse than being told neither.
            'stackable.declined' => (int) $this->input('capacity', 0) > 0
                ? Text::get('admin.validation.container_stacks')
                : Text::get('admin.validation.deck_stacks'),

            'container.in' => Text::get('admin.validation.hardware_is_physical'),
            'slots.required' => Text::get('admin.validation.deck_needs_slots'),
            'slots.prohibited' => Text::get('admin.validation.slots_need_a_deck'),
            'variant.required' => Text::get('admin.validation.module_needs_variant'),
            'variant.prohibited' => Text::get('admin.validation.variant_needs_a_kind'),
            'power_draw.prohibited' => Text::get('admin.validation.no_power_draw'),
            'power_draw.required' => Text::get('admin.validation.module_needs_power'),
        ];
    }

    /**
     * Names for the measures, so a refusal reads as the form does.
     *
     * Without this the validator says "the qflops field is prohibited",
     * naming a column rather than the field the admin is looking at.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = ['variant' => 'socket', 'power_draw' => 'power_draw'];

        foreach (self::MEASURES as $column) {
            $names[$column] = $column;
        }

        return array_map(
            static fn (string $key): string => Text::get("admin.items.fields.{$key}"),
            $names,
        );
    }

    /**
     * The rules that need to see more than one field, or more than one row.
     *
     * A deck's sockets are rows in `deck_slots`, so nothing the schema can
     * express about one of them says how many there are altogether. These are
     * the three things it cannot:
     *
     * - A kind's cap applies across its variants. Five expansion slots means
     *   five, not five XGate and five PEI.
     * - A deck with nowhere to put a CPU is not a deck.
     * - Neither is one with no power.
     *
     * The last two are game design rather than data integrity, and they are
     * here rather than in the schema for exactly that reason: they are a line
     * each to move when a design turns up that wants a deck without one.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! ($this->hardware()?->isDeck() ?? false) || $validator->errors()->has('slots')) {
                return;
            }

            $layout = $this->slotLayout();

            foreach (SlotKind::cases() as $kind) {
                if ($layout->ofKind($kind) > $kind->maxPerDeck()) {
                    $validator->errors()->add('slots', Text::get('admin.validation.too_many_slots', [
                        'kind' => $kind->label(),
                        'max' => $kind->maxPerDeck(),
                    ]));
                }
            }

            foreach ([SlotKind::Cpu, SlotKind::Psu] as $required) {
                if ($layout->ofKind($required) < 1) {
                    $validator->errors()->add('slots', Text::get('admin.validation.deck_needs_kind', [
                        'kind' => $required->label(),
                    ]));
                }
            }
        });
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

        // The hardware lives in two other tables. `hardware` itself stays —
        // it is the column on `items` that says which of them to look in.
        unset($data['slots'], $data['variant'], $data['power_draw']);

        foreach (self::MEASURES as $column) {
            unset($data[$column]);
        }

        return $data;
    }

    /** What kind of deck hardware this payload describes, if any. */
    public function hardware(): ?Hardware
    {
        $value = $this->input('hardware');

        return is_string($value) ? Hardware::tryFrom($value) : null;
    }

    /** The sockets this payload asks for. Empty for anything but a deck. */
    public function slotLayout(): SlotLayout
    {
        if (! ($this->hardware()?->isDeck() ?? false)) {
            return SlotLayout::empty();
        }

        return SlotLayout::fromCounts($this->positiveCounts());
    }

    /**
     * The module's row, with every measure it does not use set to null.
     *
     * Nulls rather than omissions, because this is written over an existing
     * row: a CPU re-saved as a PSU has to lose its qFLOPS, and an update that
     * only carried the columns it filled would leave them behind.
     *
     * @return array<string, mixed>
     */
    public function moduleColumns(): array
    {
        $data = $this->validated();

        $columns = [
            'variant' => $data['variant'] ?? null,
            'power_draw' => (int) ($data['power_draw'] ?? 0),
        ];

        foreach (self::MEASURES as $column) {
            $columns[$column] = $data[$column] ?? null;
        }

        return $columns;
    }

    /** A number field, or null when it was left blank. */
    private function numberOrNull(string $field): ?string
    {
        return $this->filled($field) ? (string) $this->input($field) : null;
    }

    /**
     * The socket counters that actually ask for a socket.
     *
     * @return array<string, int>
     */
    private function positiveCounts(): array
    {
        $counts = [];

        foreach ((array) $this->input('slots', []) as $key => $count) {
            if (is_string($key) && (int) $count > 0) {
                $counts[$key] = (int) $count;
            }
        }

        return $counts;
    }
}
