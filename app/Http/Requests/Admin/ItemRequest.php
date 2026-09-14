<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Decks\BoardLayout;
use App\Decks\Hardware;
use App\Decks\SlotKind;
use App\Decks\SlotLayout;
use App\Decks\SlotType;
use App\Decks\Variant;
use App\Market\Rarity;
use App\Models\Item;
use App\Programs\ProgramKind;
use App\Stats\Modifier;
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
    private const MEASURES = ['qflops', 'quantabits', 'bandwidth', 'watts', 'cooling', 'storage'];

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
            'cooling' => $this->numberOrNull('cooling'),
            'storage' => $this->numberOrNull('storage'),
            'thermal_load' => $this->numberOrNull('thermal_load'),
            'thermal_warning' => $this->numberOrNull('thermal_warning'),
            'thermal_limit' => $this->numberOrNull('thermal_limit'),
            // The socket counters, with the empty ones dropped. The editor
            // renders all fourteen whatever kind of item is being edited, so
            // a form full of zeroes has to arrive as "no sockets asked for"
            // rather than as fourteen requests for none — otherwise a CPU
            // could never be saved, its slots being prohibited and present.
            'slots' => $this->positiveCounts(),
            // The board arrives as one JSON string, because it is one document
            // and fourteen pairs of number inputs is not a thing anyone would
            // arrange by hand. Decoded here so the ordinary array rules below
            // can read its shape; a string that is not an object stays a
            // string and fails `array`, which is the message we want.
            'board_layout' => $this->boardLayout(),
            // Software. Empty is no value, the same as every measure above.
            'program_kind' => $this->filled('program_kind') ? $this->input('program_kind') : null,
            'qflops_cost' => $this->numberOrNull('qflops_cost'),
            'memory_cost' => $this->numberOrNull('memory_cost'),
            'storage_size' => $this->numberOrNull('storage_size'),
            'background_process' => $this->boolean('background_process'),
            // One JSON document, like the board, and decoded here so the array
            // rules below can see its shape. A string that is not an array
            // stays a string and fails `array`, which is the message we want.
            'program_modifiers' => $this->programModifiers(),
        ]);
    }

    /**
     * The posted board, decoded, or null when the field is blank.
     *
     * Blank is "not arranged", which is a real state and the one every deck
     * starts in — App\Decks\BoardLayout draws the automatic ring for it.
     */
    private function boardLayout(): mixed
    {
        $raw = $this->input('board_layout');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        // Not decodable, or decodable to something that is not a map: hand it
        // back as it came so `array` refuses it and says so.
        return is_array($decoded) ? $decoded : $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $capacity = (int) $this->input('capacity', 0);
        $isSkillbook = $this->input('use_op') === Item::USE_INJECT_SKILL;
        $hardware = $this->hardware();
        $isProgram = $this->programKind() !== null;

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
            //
            // A MODULE is the same case from the other end. A fitting points
            // at an inventory row, and a row holding two sticks cannot be
            // pointed at: fitting one of them would mean splitting the pile,
            // and what came back would not be the object the player picked.
            'stackable' => $capacity > 0 || $hardware !== null
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

            // A deck may have no arrangement — that is the ring — but only a
            // deck may have one at all.
            'board_layout' => $hardware?->isDeck()
                ? ['nullable', 'array']
                : ['prohibited'],
            'board_layout.*' => ['array', 'size:2'],
            'board_layout.*.0' => ['numeric', 'between:'.BoardLayout::MIN.','.BoardLayout::MAX],
            'board_layout.*.1' => ['numeric', 'between:'.BoardLayout::MIN.','.BoardLayout::MAX],

            /*
             * SOFTWARE. An item is a program because it has a kind, the same
             * way it is deck hardware because it has one — and the two are
             * mutually exclusive without a rule saying so, because hardware
             * forces `container` to physical and a program is data.
             *
             * A skillbook is not a program either. Both are data with a job,
             * and `use_op` already says which job; a row claiming both would
             * be a book that runs and a program that teaches.
             */
            'program_kind' => $hardware !== null || $isSkillbook
                ? ['prohibited']
                : ['nullable', Rule::enum(ProgramKind::class)],

            'qflops_cost' => $isProgram
                ? ['required', 'integer', 'min:0', 'max:'.self::MAX_MEASURE]
                : ['prohibited'],
            'memory_cost' => $isProgram
                ? ['required', 'integer', 'min:0', 'max:'.self::MAX_MEASURE]
                : ['prohibited'],
            'storage_size' => $isProgram
                ? ['required', 'integer', 'min:0', 'max:'.self::MAX_MEASURE]
                : ['prohibited'],

            // A checkbox is always present and always boolean, so this one is
            // not prohibited for a non-program — it is simply not written.
            'background_process' => ['boolean'],

            /*
             * What running it does. A list of modifier definitions in the
             * shape App\Stats\Modifier::fromArray() reads, and validated by
             * BUILDING them in withValidator() — the only check that is worth
             * anything, since a shape that parses but names an unknown op is
             * a modifier that throws the first time a player runs it.
             *
             * Empty is ordinary. A plain icebreaker modifies nothing; being a
             * gate is the whole of what it does.
             */
            'program_modifiers' => $isProgram ? ['nullable', 'array'] : ['prohibited'],
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

            // And the mirror: a radiator takes load away and makes none —
            // chk_deck_modules_thermal. Zero is allowed on everything else,
            // because most modules run cool and a required 1 would be a
            // number invented to satisfy a rule.
            'thermal_load' => $hardware?->heats()
                ? ['required', 'integer', 'min:0', 'max:'.self::MAX_MEASURE]
                : ['prohibited'],

            /*
             * What the module can stand. Nullable together — null is a part
             * that does not care how hot the deck gets, which is a real answer
             * and the one every module in the catalogue already gives. The
             * pairing and the ordering are withValidator()'s, because neither
             * rule can see the other field from here.
             */
            'thermal_warning' => $hardware?->isModule()
                ? ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MEASURE]
                : ['prohibited'],
            'thermal_limit' => $hardware?->isModule()
                ? ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MEASURE]
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
            'board_layout.prohibited' => Text::get('admin.validation.board_needs_a_deck'),
            'board_layout.array' => Text::get('admin.validation.board_malformed'),
            'variant.required' => Text::get('admin.validation.module_needs_variant'),
            'variant.prohibited' => Text::get('admin.validation.variant_needs_a_kind'),
            'power_draw.prohibited' => Text::get('admin.validation.no_power_draw'),
            'power_draw.required' => Text::get('admin.validation.module_needs_power'),
            'thermal_load.prohibited' => Text::get('admin.validation.no_thermal_load'),
            'thermal_load.required' => Text::get('admin.validation.module_needs_thermal_load'),
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
        /*
         * A modifier definition is validated by BUILDING it.
         *
         * Nothing weaker is worth having. A shape that parses as JSON but
         * names an op that does not exist, or a condition whose predicate is
         * misspelled, is a modifier that throws the first time a player runs
         * the program — which is a 500 on somebody else's screen, hours later,
         * with nothing pointing back here. Modifier::fromArray() already
         * refuses all of it with a message that names the problem; this hands
         * that message to the person who can fix it.
         */
        $validator->after(function (Validator $validator): void {
            if ($this->programKind() === null || $validator->errors()->has('program_modifiers')) {
                return;
            }

            foreach ((array) $this->input('program_modifiers', []) as $index => $definition) {
                if (! is_array($definition)) {
                    $validator->errors()->add('program_modifiers', Text::get('admin.validation.modifier_shape'));

                    continue;
                }

                try {
                    Modifier::fromArray($definition);
                } catch (\InvalidArgumentException $e) {
                    $validator->errors()->add('program_modifiers', Text::get('admin.validation.modifier_invalid', [
                        'index' => (int) $index + 1,
                        'reason' => $e->getMessage(),
                    ]));
                }
            }
        });

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

            // Both thermal levels or neither, and in that order. The schema's
            // chk_deck_modules_thermal_levels says the same; this is the half
            // that explains itself, since what the database returns is a 500
            // and a constraint name.
            $warning = $this->input('thermal_warning');
            $limit = $this->input('thermal_limit');

            if (($warning === null) !== ($limit === null)) {
                $validator->errors()->add('thermal_limit', Text::get('admin.validation.thermal_levels_pair'));
            } elseif ($warning !== null && (int) $limit < (int) $warning) {
                $validator->errors()->add('thermal_limit', Text::get('admin.validation.thermal_levels_order'));
            }

            // A board's keys are socket types. `board_layout.*` can say what
            // a VALUE looks like and nothing about what a key is allowed to
            // be, so this is the half the rule set cannot reach.
            foreach (array_keys((array) $this->input('board_layout', [])) as $key) {
                if (! is_string($key) || SlotType::fromKey($key) === null) {
                    $validator->errors()->add('board_layout', Text::get('admin.validation.board_unknown_socket'));

                    break;
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
        unset(
            $data['slots'], $data['variant'], $data['power_draw'], $data['thermal_load'],
            $data['thermal_warning'], $data['thermal_limit'],
        );

        // Always written, never merely omitted: an item that has stopped being
        // a deck has to LOSE its board, and a key that is absent from an
        // update leaves the old one sitting there. The same reason
        // HardwareEditor clears the side an item stopped being.
        //
        // Cleaned on the way past as well. The validator has already refused
        // the shapes that matter; this is what stops a coordinate a hair
        // outside the frame, or a key for a socket the deck no longer has,
        // from being stored as the truth.
        $data['board_layout'] = ($this->hardware()?->isDeck() ?? false)
            ? (BoardLayout::clean((array) ($data['board_layout'] ?? [])) ?: null)
            : null;

        foreach (self::MEASURES as $column) {
            unset($data[$column]);
        }

        // Software lives in its own table too. Nothing on `items` says an item
        // is a program — the row is what says so — so none of these stay.
        unset(
            $data['program_kind'], $data['qflops_cost'], $data['memory_cost'],
            $data['storage_size'], $data['background_process'], $data['program_modifiers'],
        );

        return $data;
    }

    /** What kind of software this payload describes, if any. */
    public function programKind(): ?ProgramKind
    {
        $value = $this->input('program_kind');

        return is_string($value) && $value !== '' ? ProgramKind::tryFrom($value) : null;
    }

    /**
     * The program's row, everything but the kind.
     *
     * @return array<string, mixed>
     */
    public function programColumns(): array
    {
        $data = $this->validated();

        return [
            'qflops_cost' => (int) ($data['qflops_cost'] ?? 0),
            'memory_cost' => (int) ($data['memory_cost'] ?? 0),
            'storage_size' => (int) ($data['storage_size'] ?? 0),
            'background_process' => (bool) ($data['background_process'] ?? false),
            // Null rather than [], because a program that only gates genuinely
            // modifies nothing and the two should not be different spellings
            // of one fact.
            'modifiers' => ($data['program_modifiers'] ?? []) === []
                ? null
                : array_values((array) $data['program_modifiers']),
        ];
    }

    /**
     * The modifiers field, decoded.
     *
     * @return array<int, mixed>|string|null
     */
    private function programModifiers(): array|string|null
    {
        $raw = $this->input('program_modifiers');

        if ($raw === null || $raw === '' || $raw === '[]') {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : (string) $raw;
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
            // A radiator's is prohibited and therefore absent, which is the
            // zero chk_deck_modules_thermal wants.
            'thermal_load' => (int) ($data['thermal_load'] ?? 0),
            'thermal_warning' => $data['thermal_warning'] ?? null,
            'thermal_limit' => $data['thermal_limit'] ?? null,
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
