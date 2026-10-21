<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Admin\ModifierField;
use App\Combat\AmmoType;
use App\Combat\DamageProfile;
use App\Combat\Riders;
use App\Combat\WeaponClass;
use App\Combat\WearSlot;
use App\Cyberspace\SystemType;
use App\Decks\BoardLayout;
use App\Decks\Hardware;
use App\Decks\SlotKind;
use App\Decks\SlotLayout;
use App\Decks\SlotType;
use App\Decks\Variant;
use App\Drugs\Family;
use App\Implants\BodyRegion;
use App\Items\UseEffect;
use App\Market\Rarity;
use App\Models\Ammunition;
use App\Models\Item;
use App\Models\Program;
use App\Programs\ProgramKind;
use App\Stats\Modifier;
use App\Support\Qb;
use App\Support\Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
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
 * - One imprint per skill. `use_skill_id` is unique across items, because a
 *   second imprint teaching the same skill would make Imprint::forSkill()
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

    /** The measures carried to one decimal place (see App\Support\Qb), and the most one may hold. */
    private const TENTHS = ['qflops', 'quantabits', 'storage'];

    private const MAX_QB = 999_999_999.9;

    /** @return list<mixed> A number with at most one decimal place. */
    private static function qb(int|float $min = 0): array
    {
        return ['numeric', 'regex:/^\d+(\.\d)?$/', 'min:'.$min, 'max:'.self::MAX_QB];
    }

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
        /*
         * WHICH PANELS THE FORM IS ACTUALLY FILLING IN.
         *
         * The editor renders every panel whatever kind of item is being
         * edited, and the software and drug panels default their numbers to
         * ZERO rather than to blank. `filled('0')` is true, so without this
         * those six fields arrive present on every save — and each of them is
         * `prohibited` for an item that is not that kind, which made creating
         * an implant, a module or a plain item impossible.
         *
         * A panel's own gate decides: no `program_kind` means the software
         * numbers are not values, no `drug_family` means the drug numbers are
         * not. This is the decision `positiveCounts()` already makes for the
         * fourteen socket counters, for exactly the same reason.
         *
         * A ZERO ON A PANEL THAT IS OFF IS THE FORM'S DEFAULT; anything else
         * is somebody typing a number. So only the zeroes are dropped, and a
         * cost of 5 posted with no program kind is still REFUSED rather than
         * quietly thrown away — the same distinction `positiveCounts()` draws
         * for the socket counters, which keeps a count of 3 on a non-deck an
         * error and a count of 0 a no-op.
         *
         * When the panel IS on, every value passes through untouched, because
         * zero is a real answer there: a drug that does not addict and a
         * program that costs no compute are both things somebody may author.
         */
        $isProgramForm = $this->filled('program_kind');
        $isDrugForm = $this->filled('drug_family');
        $isWeaponForm = $this->filled('weapon_class');
        $isAmmoForm = $this->filled('ammo_calibre');
        // A firearm's damage and profile are its round's: the weapon panel is on for everything else.
        $weaponClass = WeaponClass::tryFrom((string) $this->input('weapon_class'));
        $weaponOwnsDamage = $isWeaponForm && ! ($weaponClass?->firearm() ?? false);

        $this->merge([
            'stackable' => $this->boolean('stackable'),
            'on_market' => $this->boolean('on_market'),
            // An empty text field is no value, not a value of ''. An imprint
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
            // Empty is "use the fallback", not zero.
            'onboard_storage' => $this->numberOrNull('onboard_storage'),
            // Software. Empty is no value, the same as every measure above.
            'program_kind' => $this->filled('program_kind') ? $this->input('program_kind') : null,
            // Drugs, the same way: empty is no value.
            'drug_family' => $this->filled('drug_family') ? $this->input('drug_family') : null,
            // A consumable: one number per kind of effect, blank meaning none. Whole numbers only, so a stray
            // decimal is a validation error and not a quiet rounding.
            ...collect(UseEffect::cases())->mapWithKeys(fn (UseEffect $k): array => [
                'use_'.$k->value => $this->filled('use_'.$k->value) ? $this->input('use_'.$k->value) : null,
            ])->all(),
            // A fight-only drug counts rounds, so its minutes are not asked (0, unread) and the two fight fields only
            // mean anything when it is one: blank otherwise, however the form posted them.
            'fight_only' => $this->boolean('fight_only'),
            // A Script's lifetime: blank is an ordinary program.
            'lifetime_seconds' => $this->numberOrNull('lifetime_seconds'),
            // A Pathfinder's seconds per move: blank is the default (`cyberspace.locate.step_seconds`).
            'traverse_seconds' => $this->numberOrNull('traverse_seconds'),
            // A Decryptor's cycle, an Assembler's reduction and a Booster's boost: stored together as `programs.produces`.
            'produce_cycle_minutes' => $this->numberOrNull('produce_cycle_minutes'),
            'produce_reduction' => $this->numberOrNull('produce_reduction'),
            'produce_boost' => $this->numberOrNull('produce_boost'),
            'produce_range' => $this->numberOrNull('produce_range'),
            // A Leecher: the one resource it takes from the system it hangs on, and how much.
            'leech_resource' => $this->filled('leech_resource') ? $this->input('leech_resource') : null,
            'leech_amount' => $this->numberOrNull('leech_amount'),
            'produce_strength' => $this->numberOrNull('produce_strength'),
            // A design: the product is what makes the item one. Lines with no component chosen are the form's spare rows.
            'design_product' => $this->filled('design_product') ? $this->input('design_product') : null,
            // The panel's own defaults (a level of 1) mean nothing when there is no product, so they are not values.
            'design_seconds' => $this->filled('design_product') ? $this->numberOrNull('design_seconds') : null,
            'design_single_use' => $this->filled('design_product') && $this->boolean('design_single_use'),
            'design_skill_id' => $this->filled('design_product') && $this->filled('design_skill_id') ? $this->input('design_skill_id') : null,
            'design_skill_level' => $this->filled('design_product') && $this->filled('design_skill_id') ? $this->numberOrNull('design_skill_level') : null,
            'design_lines' => $this->designLinesInput(),
            'duration_minutes' => $this->boolean('fight_only') ? '0' : $this->panelNumber('duration_minutes', $isDrugForm),
            'duration_turns' => $this->boolean('fight_only') ? $this->numberOrNull('duration_turns') : null,
            'turn_hitpoints' => $this->boolean('fight_only') ? ($this->numberOrNull('turn_hitpoints') ?? '0') : null,
            'addictive' => $this->panelNumber('addictive', $isDrugForm),
            'tolerance_gain' => $this->panelNumber('tolerance_gain', $isDrugForm),
            'lethal_at' => $this->panelNumber('lethal_at', $isDrugForm),
            'wakefulness' => $this->panelNumber('wakefulness', $isDrugForm),
            'qflops_cost' => $this->panelNumber('qflops_cost', $isProgramForm),
            'memory_cost' => $this->panelNumber('memory_cost', $isProgramForm),
            'storage_size' => $this->panelNumber('storage_size', $isProgramForm),
            'background_process' => $this->boolean('background_process'),
            'multithreaded' => $this->boolean('multithreaded'),
            // A Utility's abilities (see ProgramKind::Utility).
            'locates' => $this->boolean('locates'),
            'walks' => $this->boolean('walks'),
            'jumps' => $this->boolean('jumps'),
            'tracks' => $this->boolean('tracks'),
            // Which encryptions a Decryptor can break: ticked slugs, none ticked meaning any.
            'program_decrypts' => array_values(array_filter((array) $this->input('program_decrypts', []), 'is_string')),
            // Rows from the effects editor, turned into modifier definitions
            // here so everything downstream sees the shape
            // Modifier::fromArray() reads. A raw JSON string is still accepted:
            // a payload written before the editor existed is not wrong.
            'program_modifiers' => ModifierField::normalise($this->input('program_modifiers')),
            // What the ITEM does, as opposed to the software on it. This is
            // where an implant's effects live.
            'modifiers' => ModifierField::normalise($this->input('modifiers')),
            'implant_region' => $this->filled('implant_region') ? $this->input('implant_region') : null,
            // Armour: the slots ticked. An item is armour because it covers at least one, the same
            // way it is a drug because it has a family. An unticked box is simply absent.
            'armour_slots' => array_values(array_filter((array) $this->input('armour_slots', []), static fn ($slot): bool => is_string($slot) && $slot !== '')),
            // Weapons and ammunition, the way drugs are: a class (or a calibre) makes the item one,
            // and a zero on a panel that is off is the form's default rather than a value.
            'weapon_class' => $this->filled('weapon_class') ? $this->input('weapon_class') : null,
            'weapon_damage' => $this->panelNumber('weapon_damage', $weaponOwnsDamage),
            'weapon_accuracy' => $this->panelNumber('weapon_accuracy', $isWeaponForm),
            // The rate's form default is 1, not 0, so a hidden Weapon panel posts a 1 that is not a value.
            'weapon_rate' => ! $isWeaponForm && $this->numberOrNull('weapon_rate') === '1' ? null : $this->panelNumber('weapon_rate', $isWeaponForm),
            'weapon_magazine' => $this->panelNumber('weapon_magazine', $isWeaponForm),
            'weapon_calibre' => $this->filled('weapon_calibre') ? trim((string) $this->input('weapon_calibre')) : null,
            'weapon_profile' => $this->profileInput('weapon_profile', $weaponOwnsDamage),
            'weapon_riders' => $this->ridersInput('weapon_riders', $isWeaponForm),
            'ammo_calibre' => $this->filled('ammo_calibre') ? trim((string) $this->input('ammo_calibre')) : null,
            'ammo_type' => $isAmmoForm ? ($this->filled('ammo_type') ? $this->input('ammo_type') : AmmoType::Standard->value) : null,
            'ammo_damage' => $this->panelNumber('ammo_damage', $isAmmoForm),
            'ammo_penetration' => $this->panelNumber('ammo_penetration', $isAmmoForm),
            'ammo_profile' => $this->profileInput('ammo_profile', $isAmmoForm),
            'ammo_riders' => $this->ridersInput('ammo_riders', $isAmmoForm),
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
        $capacity = (float) $this->input('capacity', 0);
        $isImprint = $this->input('use_op') === Item::USE_INJECT_SKILL;
        $hardware = $this->hardware();
        $isProgram = $this->programKind() !== null;
        $isDrug = $this->drugFamily() !== null;
        $isDesign = $this->filled('design_product');
        $isConsumable = $this->consumableEffects() !== [];
        $isArmour = $this->armourSlots() !== [];
        $weaponClass = $this->weaponClass();
        $isWeapon = $weaponClass !== null;
        $isAmmo = $this->ammoCalibre() !== null;
        $isGear = $isArmour || $isWeapon || $isAmmo;

        return array_merge([
            // Only on create. A slug is permanent — inventory rows and the
            // imprint naming both reference it — so update() never sees one.
            'slug' => $this->isCreating()
                ? ['required', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/', Rule::unique('items', 'slug')]
                : ['prohibited'],

            // An imprint has no name of its own: it composes from the skill
            // it teaches, and storing one would be a second copy of the
            // skill's title waiting to disagree with it.
            'name' => $isImprint
                ? ['nullable', 'string', 'max:255']
                : ['required', 'string', 'max:255'],

            'flavor' => ['nullable', 'string', 'max:2000'],

            // Hardware is matter: a cyberdeck and every module that goes in
            // one is something you carry, never something written to a deck.
            'container' => $hardware === null && ! $isGear
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
            // ARMOUR is the same case again: a worn row points at ONE inventory row, and a row holding
            // three vests cannot say which one is on.
            //
            // A WEAPON is the same, for the hand it is held in. Ammunition is the opposite: it is
            // bought and spent by the box, so it must stack.
            'stackable' => $isAmmo
                ? ['accepted']
                : ($capacity > 0 || $hardware !== null || $isArmour || $isWeapon
                    ? ['boolean', 'declined']
                    : ['boolean']),

            'use_op' => ['nullable', Rule::in(Item::USE_OPS)],
            'use_skill_id' => $isImprint
                ? [
                    'required', 'integer', Rule::exists('skills', 'id'),
                    Rule::unique('items', 'use_skill_id')->ignore($this->item()?->id),
                ]
                : ['prohibited'],

            'weight' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'size' => ['required', ...self::qb()],

            'capacity' => ['required', ...self::qb()],
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
            'onboard_storage' => $hardware?->isDeck()
                ? ['nullable', ...self::qb()]
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
             * An imprint is not a program either. Both are data with a job,
             * and `use_op` already says which job; a row claiming both would
             * be an imprint that runs and a program that teaches.
             */
            'program_kind' => $hardware !== null || $isImprint || $isGear
                ? ['prohibited']
                : ['nullable', Rule::enum(ProgramKind::class)],

            'qflops_cost' => $isProgram
                ? ['required', ...self::qb()]
                : ['prohibited'],
            'memory_cost' => $isProgram
                ? ['required', ...self::qb()]
                : ['prohibited'],
            'storage_size' => $isProgram
                ? ['required', ...self::qb()]
                : ['prohibited'],
            /*
             * DRUGS. An item is a drug because it has a family, the same way
             * it is software because it has a kind — and the row is what says
             * so, never a column here.
             *
             * A drug is a PHYSICAL object you swallow, so it cannot also be
             * software or deck hardware. What it DOES lives on `modifiers`
             * like an implant's; these four are what it costs.
             */
            'drug_family' => $hardware !== null || $isProgram || $isImprint || $isGear
                ? ['prohibited']
                : ['nullable', Rule::enum(Family::class)],

            /*
             * CONSUMABLES. An item is one because it names something using a copy does, the way it is a drug
             * because it has a family. What it does is a number per kind (`App\Items\UseEffect`); blank is none.
             * A consumable is a PHYSICAL object used up once. It may also be a DRUG (a trauma drip that heals now and
             * mends for ten minutes), and is none of the other kinds.
             */
            ...collect(UseEffect::cases())->mapWithKeys(fn (UseEffect $k): array => [
                'use_'.$k->value => $hardware !== null || $isProgram || $isImprint || $isGear || $this->filled('implant_region')
                    ? ['prohibited']
                    : ['nullable', 'integer', 'min:1', 'max:10000'],
            ])->all(),

            'fight_only' => ['boolean'],
            'duration_minutes' => $isDrug
                ? ($this->boolean('fight_only') ? ['required', 'integer', 'in:0'] : ['required', 'integer', 'min:1', 'max:10080'])
                : ['prohibited'],
            // Rounds, when it is fight-only: a fight is a few rounds, so a long one is a typo.
            'duration_turns' => $isDrug && $this->boolean('fight_only')
                ? ['required', 'integer', 'min:1', 'max:50']
                : ['prohibited'],
            // Signed: positive restores, negative is the drug's price. Whole points.
            'turn_hitpoints' => $isDrug && $this->boolean('fight_only')
                ? ['required', 'integer', 'min:-100', 'max:100']
                : ['prohibited'],
            'wakefulness' => $isDrug
                ? ['nullable', 'integer', 'min:0', 'max:255']
                : ['prohibited'],
            'addictive' => $isDrug
                ? ['required', 'numeric', 'min:0', 'max:100']
                : ['prohibited'],
            'tolerance_gain' => $isDrug
                ? ['required', 'numeric', 'min:0', 'max:100']
                : ['prohibited'],

            // Nearly always blank: what too much does belongs to the family,
            // and this is the one drug that disagrees. Null asks the family.
            'lethal_at' => $isDrug
                ? ['nullable', 'integer', 'min:1', 'max:255']
                : ['prohibited'],

            // A checkbox is always present and always boolean, so this one is
            // not prohibited for a non-program — it is simply not written.
            'background_process' => ['boolean'],

            /*
             * Whether two copies may be pointed at two things at once. A
             * property of the software: how many a character may run is the
             * `program_threads` stat, and no amount of skill runs two of
             * something this is false on.
             */
            'multithreaded' => ['boolean'],
            'locates' => ['boolean'],
            'walks' => ['boolean'],
            'jumps' => ['boolean'],
            'tracks' => ['boolean'],
            'lifetime_seconds' => $isProgram
                ? ['nullable', 'integer', 'min:1', 'max:'.self::MAX_MEASURE]
                : ['prohibited'],
            'traverse_seconds' => $isProgram
                ? ['nullable', 'integer', 'min:1', 'max:600']
                : ['prohibited'],
            'produce_cycle_minutes' => $isProgram ? ['nullable', 'numeric', 'min:1', 'max:100000'] : ['prohibited'],
            'produce_reduction' => $isProgram ? ['nullable', 'numeric', 'min:0', 'max:100'] : ['prohibited'],
            'produce_boost' => $isProgram ? ['nullable', 'numeric', 'min:0', 'max:1000'] : ['prohibited'],
            'leech_resource' => $isProgram ? ['nullable', 'in:compute,memory,storage'] : ['prohibited'],
            'leech_amount' => $isProgram ? ['nullable', 'numeric', 'min:0', 'max:1000'] : ['prohibited'],
            'produce_range' => $isProgram ? ['nullable', 'integer', 'min:0', 'max:20'] : ['prohibited'],
            'produce_strength' => $isProgram ? ['nullable', 'numeric', 'min:0', 'max:1000'] : ['prohibited'],

            /*
             * A DESIGN (CRAFTING.md): plain data with a recipe. The product must be a program of a kind somebody can be taught to build,
             * and every line names a data item. A line's filters are all optional.
             */
            'design_product' => $hardware !== null || $isProgram || $isImprint || $isGear || $isDrug || $this->filled('implant_region')
                ? ['prohibited']
                : ['nullable', 'integer', Rule::exists('programs', 'item_id'), function (string $attribute, mixed $value, \Closure $fail): void {
                    $kind = Program::query()->where('item_id', (int) $value)->value('kind');

                    if (config('crafting.gate.'.($kind instanceof ProgramKind ? $kind->value : (string) $kind)) === null) {
                        $fail(Text::get('crafting.refused.uncraftable'));
                    }
                }],
            'design_seconds' => $isDesign ? ['required', 'integer', 'min:1', 'max:'.self::MAX_MEASURE] : ['prohibited'],
            'design_single_use' => ['boolean'],
            'design_skill_id' => $isDesign ? ['nullable', 'integer', Rule::exists('skills', 'id')] : ['prohibited'],
            'design_skill_level' => $isDesign ? ['nullable', 'integer', 'min:1', 'max:20'] : ['prohibited'],
            'design_lines' => $isDesign ? ['required', 'array', 'min:1', 'max:'.(int) config('crafting.max_components', 8)] : ['prohibited'],
            'design_lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('container', Item::CONTAINER_DATA)],
            'design_lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'design_lines.*.grade_min' => ['nullable', 'integer', 'between:1,9'],
            'design_lines.*.grade_max' => ['nullable', 'integer', 'between:1,9', 'gte:design_lines.*.grade_min'],
            'design_lines.*.os_slug' => ['nullable', 'string', Rule::exists('skills', 'slug')->where('category', 'Operating Systems')],
            'design_lines.*.owner_id' => ['nullable', 'integer', Rule::exists('entities', 'id')],
            'design_lines.*.system_type' => ['nullable', Rule::enum(SystemType::class)],
            'design_lines.*.ice_min' => ['nullable', 'integer', 'min:1', 'max:20'],
            'design_lines.*.ids_min' => ['nullable', 'integer', 'min:1', 'max:100'],

            // The encryptions a Decryptor is limited to, by skill slug; empty is any. Stored only on a decryptor.
            'program_decrypts' => ['array'],
            'program_decrypts.*' => ['string', Rule::exists('skills', 'slug')->where('category', 'Encryption')],

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

            /*
             * What the ITEM grants whoever has it — an implant's effects, and
             * whatever else acquires them. Judged the same way and by the same
             * class as the software's.
             *
             * Not gated on being an implant: MECHANICS is explicit that a thing
             * with a row carries its modifiers on that row, and deciding here
             * which KINDS of thing are allowed to would be a second opinion
             * about that.
             */
            'modifiers' => ['nullable', 'array'],

            // ARMOUR. Which slots of the body a piece covers; ticking none means it is not armour.
            'armour_slots' => ['array'],
            'armour_slots.*' => ['distinct', Rule::in(WearSlot::values())],

            // WEAPONS. A class makes the item one. The damage is ONE fixed figure; the profile says
            // how it splits across damage types (and is judged in withValidator(), as a whole).
            // A FIREARM has neither: its round carries both, so they are refused on one.
            'weapon_class' => ['nullable', Rule::enum(WeaponClass::class)],
            'weapon_damage' => $isWeapon && ! $weaponClass->firearm() ? ['required', 'integer', 'between:1,1000'] : ['prohibited'],
            'weapon_accuracy' => $isWeapon ? ['required', 'integer', 'between:-100,100'] : ['prohibited'],
            // Shots a round only means something for something that shoots: a close weapon (unarmed, blade, blunt)
            // may leave it blank and is stored as 1.
            'weapon_rate' => $isWeapon ? [$weaponClass?->ranged() === false ? 'nullable' : 'required', 'integer', 'between:1,30'] : ['prohibited'],
            // A firearm takes a calibre of round from a magazine, and both are required together; a
            // blade or a grenade takes neither.
            'weapon_magazine' => $weaponClass?->firearm() ? ['required', 'integer', 'between:1,1000'] : ['prohibited'],
            'weapon_calibre' => $weaponClass?->firearm() ? ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9 ._-]+$/'] : ['prohibited'],
            'weapon_profile' => $isWeapon && ! $weaponClass->firearm() ? ['required', 'array'] : ['prohibited'],
            'weapon_riders' => $isWeapon ? ['nullable', 'array'] : ['prohibited'],

            // AMMUNITION. A calibre makes the item a round: it IS the hit of a firearm that fires it, so
            // it carries the damage and the profile, both required.
            'ammo_calibre' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9 ._-]+$/'],
            'ammo_type' => $isAmmo ? ['required', Rule::enum(AmmoType::class)] : ['prohibited'],
            'ammo_damage' => $isAmmo ? ['required', 'integer', 'between:1,1000'] : ['prohibited'],
            'ammo_penetration' => $isAmmo ? ['required', 'integer', 'between:-100,100'] : ['prohibited'],
            'ammo_profile' => $isAmmo ? ['required', 'array'] : ['prohibited'],
            'ammo_riders' => $isAmmo ? ['nullable', 'array'] : ['prohibited'],

            /*
             * Where in a body this goes, or nothing at all.
             *
             * The column is the only place a region is written down, so an
             * implant that cannot be authored here is an implant that has to
             * arrive by migration — which is how the placeholders got in.
             */
            'implant_region' => ['nullable', Rule::enum(BodyRegion::class)],
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
                ? ['required', ...(in_array($column, self::TENTHS, true) ? self::qb(0.1) : ['integer', 'min:1', 'max:'.self::MAX_MEASURE])]
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
            'stackable.declined' => (float) $this->input('capacity', 0) > 0
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
            if ($this->programKind() !== null && ! $validator->errors()->has('program_modifiers')) {
                ModifierField::validate($validator, 'program_modifiers', (array) $this->input('program_modifiers', []));
            }

            if (! $validator->errors()->has('modifiers')) {
                ModifierField::validate($validator, 'modifiers', (array) $this->input('modifiers', []));
            }
        });

        /*
         * GEAR IS ONLY ITS OWN KIND. Armour, a weapon and a round are each one thing: not each
         * other, and not deck hardware, software, a drug, an imprint or an implant. An implant is
         * the sharpest case for armour — plating applies EVERYWHERE by design, and a piece that was
         * both would count twice for no reason anybody could read off the card.
         *
         * Armour's modifiers may not carry a condition of their own: `WornArmour` adds the location
         * condition, one condition per modifier, so a second would either replace it or leave a
         * resistance silently working on every hit. And a weapon or a round may carry NO modifiers
         * at all: nothing reads them (a weapon is a row the engine looks at, and its numbers are
         * columns), so an effect typed there would do nothing and say nothing.
         *
         * The two profiles are judged as wholes: only damage types, whole percentages, summing to 100.
         */
        // A family that cannot hook anybody (Medical) cannot be given an addictive figure, even in a hand-made request.
        $validator->after(function (Validator $validator): void {
            $family = $this->drugFamily();

            if ($family !== null && ! $family->addicts() && (float) $this->input('addictive', 0) !== 0.0) {
                $validator->errors()->add('addictive', Text::get('admin.validation.not_addictive', ['family' => $family->label()]));
            }
        });

        $validator->after(function (Validator $validator): void {
            $kinds = array_filter([
                'armour_slots' => $this->armourSlots() !== [],
                'weapon_class' => $this->weaponClass() !== null,
                'ammo_calibre' => $this->ammoCalibre() !== null,
            ]);

            if ($kinds === []) {
                return;
            }

            if (count($kinds) > 1
                || $this->consumableEffects() !== []
                || $this->hardware() !== null
                || $this->input('use_op') === Item::USE_INJECT_SKILL
                || $this->filled('implant_region')) {
                $validator->errors()->add(array_key_first($kinds), Text::get('admin.validation.gear_exclusive'));
            }

            $modifiers = array_filter((array) $this->input('modifiers', []), 'is_array');

            if (isset($kinds['armour_slots'])) {
                foreach ($modifiers as $row) {
                    if (! empty($row['condition'])) {
                        $validator->errors()->add('modifiers', Text::get('admin.validation.armour_condition'));

                        break;
                    }
                }
            } elseif ($modifiers !== []) {
                $validator->errors()->add('modifiers', Text::get('admin.validation.gear_no_effects'));
            }

            // ONE ITEM PER KIND OF ROUND. Ammunition stacks per item, so a second "9mm hollow point"
            // would be a pile that never merges with the first.
            if ($this->ammoCalibre() !== null && ! $validator->errors()->has('ammo_type')) {
                $twin = Ammunition::query()
                    ->where('calibre', $this->ammoCalibre())
                    ->where('type', (string) $this->input('ammo_type'))
                    ->when($this->item() !== null, fn ($q) => $q->where('item_id', '!=', $this->item()->getKey()))
                    ->with('item')
                    ->first();

                if ($twin !== null) {
                    $validator->errors()->add('ammo_type', Text::get('admin.validation.ammo_kind_taken', ['item' => $twin->item?->name ?? '']));
                }
            }

            foreach (['weapon_riders' => $this->weaponClass() !== null, 'ammo_riders' => $this->ammoCalibre() !== null] as $field => $on) {
                $problem = $on ? Riders::problem((array) $this->input($field, [])) : null;

                if ($problem !== null) {
                    $validator->errors()->add($field, Text::get("admin.validation.rider_{$problem}"));
                }
            }

            if ($this->weaponClass() !== null && ! $this->weaponClass()->firearm() && ! $validator->errors()->has('weapon_profile')) {
                $this->judgeProfile($validator, 'weapon_profile', (array) $this->input('weapon_profile', []));
            }

            if ($this->ammoCalibre() !== null && ! $validator->errors()->has('ammo_profile')) {
                $this->judgeProfile($validator, 'ammo_profile', (array) $this->input('ammo_profile', []));
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

        // Always written, for the board's reason: an item that stops being a
        // deck stops holding anything.
        $data['onboard_storage'] = ($this->hardware()?->isDeck() ?? false)
            ? (isset($data['onboard_storage']) ? Qb::of($data['onboard_storage']) : null)
            : null;

        foreach (self::MEASURES as $column) {
            unset($data[$column]);
        }

        // Software lives in its own table too. Nothing on `items` says an item
        // is a program — the row is what says so — so none of these stay.
        unset(
            $data['program_kind'], $data['qflops_cost'], $data['memory_cost'],
            $data['storage_size'], $data['lifetime_seconds'], $data['traverse_seconds'], $data['background_process'], $data['multithreaded'],
            $data['locates'], $data['walks'], $data['jumps'], $data['tracks'],
            $data['design_product'], $data['design_seconds'], $data['design_single_use'], $data['design_skill_id'], $data['design_skill_level'], $data['design_lines'],
            $data['program_modifiers'], $data['program_decrypts'],
            $data['produce_cycle_minutes'], $data['produce_reduction'], $data['produce_boost'], $data['produce_range'], $data['produce_strength'], $data['leech_resource'], $data['leech_amount'],
        );

        // Armour, weapons and ammunition live in tables of their own, not on the item.
        unset(
            $data['armour_slots'],
            $data['weapon_class'], $data['weapon_damage'], $data['weapon_accuracy'], $data['weapon_rate'],
            $data['weapon_magazine'], $data['weapon_calibre'], $data['weapon_profile'], $data['weapon_riders'],
            $data['ammo_calibre'], $data['ammo_type'], $data['ammo_damage'], $data['ammo_penetration'], $data['ammo_profile'], $data['ammo_riders'],
        );

        // And so does a consumable.
        foreach (UseEffect::cases() as $kind) {
            unset($data['use_'.$kind->value]);
        }

        // And so does a drug. Nothing on `items` says an item is one.
        unset(
            $data['drug_family'], $data['fight_only'], $data['duration_minutes'], $data['duration_turns'], $data['turn_hitpoints'], $data['addictive'],
            $data['tolerance_gain'], $data['lethal_at'], $data['wakefulness'],
        );

        // Null rather than [], for the reason a program's are: a thing that
        // grants nothing genuinely grants nothing, and the two should not be
        // different spellings of one fact.
        $data['modifiers'] = ($data['modifiers'] ?? []) === [] ? null : array_values((array) $data['modifiers']);

        return $data;
    }

    /**
     * The wear slots this payload ticks, expanded to the hit locations they protect (as stored); empty when
     * it is not armour.
     *
     * @return list<string>
     */
    public function armourSlots(): array
    {
        return WearSlot::expand(array_map('strval', (array) $this->input('armour_slots', [])));
    }

    /** What class of weapon this payload describes, if any. */
    public function weaponClass(): ?WeaponClass
    {
        $value = $this->input('weapon_class');

        return is_string($value) && $value !== '' ? WeaponClass::tryFrom($value) : null;
    }

    /** The calibre this payload's ammunition fits; null when it is not ammunition. */
    public function ammoCalibre(): ?string
    {
        $value = $this->input('ammo_calibre');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The weapon's row, everything but the item; null when this is not a weapon.
     *
     * @return array<string, mixed>|null
     */
    public function weaponColumns(): ?array
    {
        $class = $this->weaponClass();

        if ($class === null) {
            return null;
        }

        $data = $this->validated();
        $firearm = $class->firearm();

        return [
            'class' => $class,
            'damage' => $firearm ? null : (int) $data['weapon_damage'],
            'damage_profile' => $firearm ? null : DamageProfile::clean((array) ($data['weapon_profile'] ?? [])),
            'riders' => Riders::clean((array) ($data['weapon_riders'] ?? [])) ?: null,
            'accuracy' => (int) $data['weapon_accuracy'],
            'rate' => (int) ($data['weapon_rate'] ?? 1),
            'magazine' => $firearm ? (int) $data['weapon_magazine'] : null,
            'calibre' => $firearm ? (string) $data['weapon_calibre'] : null,
        ];
    }

    /**
     * The ammunition's row, everything but the item; null when this is not ammunition.
     *
     * @return array<string, mixed>|null
     */
    public function ammoColumns(): ?array
    {
        if ($this->ammoCalibre() === null) {
            return null;
        }

        $data = $this->validated();

        return [
            'calibre' => $this->ammoCalibre(),
            'type' => (string) $data['ammo_type'],
            'damage' => (int) $data['ammo_damage'],
            'penetration' => (int) $data['ammo_penetration'],
            'damage_profile' => DamageProfile::clean((array) ($data['ammo_profile'] ?? [])),
            'riders' => Riders::clean((array) ($data['ammo_riders'] ?? [])) ?: null,
        ];
    }

    /**
     * A posted profile (a map of damage type to share), or null when the whole thing is blank.
     *
     * Blank is "none", not eight zeroes; a profile with anything typed stays as posted so the
     * panel's own rule can refuse a panel that is off, or judge one that is on.
     *
     * @return array<string, mixed>|null
     */
    private function profileInput(string $field, bool $panelIsOn): ?array
    {
        $raw = (array) $this->input($field, []);
        $typed = array_filter($raw, static fn ($share): bool => $share !== null && $share !== '' && (string) $share !== '0');

        if ($typed === [] && ! $panelIsOn) {
            return null;
        }

        return $raw;
    }

    /**
     * Posted riders (kind => chance, turns, amount), or null when nothing at all is typed and the
     * panel is off, so a hidden panel's empty inputs are the form's default and not a value.
     *
     * @return array<string, mixed>|null
     */
    private function ridersInput(string $field, bool $panelIsOn): ?array
    {
        $raw = (array) $this->input($field, []);
        $typed = array_filter(Arr::flatten($raw), static fn ($v): bool => $v !== null && $v !== '' && (string) $v !== '0');

        return $typed === [] && ! $panelIsOn ? null : $raw;
    }

    /** Add the profile's problem, in words, if it has one. @param array<string, mixed> $raw */
    private function judgeProfile(Validator $validator, string $field, array $raw): void
    {
        $problem = DamageProfile::problem($raw);

        if ($problem !== null) {
            $validator->errors()->add($field, Text::get("admin.validation.profile_{$problem}"));
        }
    }

    /**
     * What using one copy does, as the rows `consumables.effects` stores: a kind and a whole amount for each filled
     * field, in the vocabulary's order. Empty when this is not a consumable.
     *
     * @return list<array{kind: string, amount: int}>
     */
    public function consumableEffects(): array
    {
        $effects = [];

        foreach (UseEffect::cases() as $kind) {
            $value = $this->input('use_'.$kind->value);

            if ($value !== null && $value !== '' && (int) $value > 0) {
                $effects[] = ['kind' => $kind->value, 'amount' => (int) $value];
            }
        }

        return $effects;
    }

    /** The program a design makes, or null when this is not a design. */
    public function designProductId(): ?int
    {
        $value = $this->input('design_product');

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array<string, mixed> */
    public function designColumns(): array
    {
        $data = $this->validated();

        return [
            'craft_seconds' => (int) ($data['design_seconds'] ?? 1),
            'single_use' => (bool) ($data['design_single_use'] ?? false),
            'skill_id' => isset($data['design_skill_id']) ? (int) $data['design_skill_id'] : null,
            'skill_level' => (int) ($data['design_skill_level'] ?? 1),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function designLines(): array
    {
        return array_map(static fn (array $line): array => [
            'component_item_id' => (int) $line['item_id'],
            'quantity' => (int) $line['quantity'],
            'grade_min' => isset($line['grade_min']) ? (int) $line['grade_min'] : null,
            'grade_max' => isset($line['grade_max']) ? (int) $line['grade_max'] : null,
            'os_slug' => $line['os_slug'] ?? null,
            'owner_id' => isset($line['owner_id']) ? (int) $line['owner_id'] : null,
            'system_type' => $line['system_type'] ?? null,
            'ice_min' => isset($line['ice_min']) ? (int) $line['ice_min'] : null,
            'ids_min' => isset($line['ids_min']) ? (int) $line['ids_min'] : null,
        ], array_values((array) ($this->validated()['design_lines'] ?? [])));
    }

    /**
     * The form's recipe rows without its spare ones: a row with no component chosen is nothing, and a blank filter is no filter.
     *
     * @return list<array<string, mixed>>|null
     */
    private function designLinesInput(): ?array
    {
        if (! $this->filled('design_product')) {
            return null;
        }

        $lines = [];

        foreach ((array) $this->input('design_lines', []) as $line) {
            $line = (array) $line;

            if (($line['item_id'] ?? '') === '') {
                continue;
            }

            $lines[] = array_map(static fn (mixed $value): mixed => $value === '' ? null : $value, $line);
        }

        return $lines;
    }

    /** What family of drug this payload describes, if any. */
    public function drugFamily(): ?Family
    {
        $value = $this->input('drug_family');

        return is_string($value) && $value !== '' ? Family::tryFrom($value) : null;
    }

    /**
     * The drug's row, everything but the family.
     *
     * @return array<string, mixed>
     */
    public function drugColumns(): array
    {
        $data = $this->validated();

        return [
            'fight_only' => (bool) ($data['fight_only'] ?? false),
            'duration_minutes' => (int) ($data['duration_minutes'] ?? 0),
            'duration_turns' => ($data['duration_turns'] ?? null) === null ? null : (int) $data['duration_turns'],
            'turn_hitpoints' => (int) ($data['turn_hitpoints'] ?? 0),
            'addictive' => (float) ($data['addictive'] ?? 0),
            'tolerance_gain' => (float) ($data['tolerance_gain'] ?? 0),
            'lethal_at' => ($data['lethal_at'] ?? null) === null ? null : (int) $data['lethal_at'],
            'wakefulness' => (int) ($data['wakefulness'] ?? 0),
        ];
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
            'qflops_cost' => Qb::of($data['qflops_cost'] ?? 0),
            'memory_cost' => Qb::of($data['memory_cost'] ?? 0),
            'storage_size' => Qb::of($data['storage_size'] ?? 0),
            'background_process' => (bool) ($data['background_process'] ?? false),
            'leech_resource' => $data['leech_resource'] ?? null,
            'leech_amount' => Qb::of($data['leech_amount'] ?? 0),
            'lifetime_seconds' => isset($data['lifetime_seconds']) ? (int) $data['lifetime_seconds'] : null,
            'traverse_seconds' => isset($data['traverse_seconds']) ? (int) $data['traverse_seconds'] : null,
            'produces' => ($produces = array_filter([
                'cycle_minutes' => isset($data['produce_cycle_minutes']) ? (float) $data['produce_cycle_minutes'] : null,
                'reduction' => isset($data['produce_reduction']) ? (float) $data['produce_reduction'] : null,
                'boost' => isset($data['produce_boost']) ? (float) $data['produce_boost'] : null,
                'range' => isset($data['produce_range']) ? (int) $data['produce_range'] : null,
                'strength' => isset($data['produce_strength']) ? (float) $data['produce_strength'] : null,
            ], fn ($v): bool => $v !== null)) === [] ? null : $produces,
            'multithreaded' => (bool) ($data['multithreaded'] ?? false),
            'locates' => (bool) ($data['locates'] ?? false),
            'walks' => (bool) ($data['walks'] ?? false),
            'jumps' => (bool) ($data['jumps'] ?? false),
            'tracks' => (bool) ($data['tracks'] ?? false),
            // Null rather than [], because a program that only gates genuinely
            // modifies nothing and the two should not be different spellings
            // of one fact.
            'modifiers' => ($data['program_modifiers'] ?? []) === []
                ? null
                : array_values((array) $data['program_modifiers']),
            // NULL is any encryption, and only a Decryptor can be limited.
            'decrypts' => $this->programKind() === ProgramKind::Decryptor && ($data['program_decrypts'] ?? []) !== []
                ? array_values((array) $data['program_decrypts'])
                : null,
        ];
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
     * A number belonging to a panel that may not be in use.
     *
     * With the panel ON, whatever was typed — zero included, because zero is a
     * real answer for a drug that does not addict or a program that costs no
     * compute.
     *
     * With the panel OFF, a zero is the form's own default and means nothing
     * was asked for, so it becomes null and the `prohibited` rule passes.
     * Anything else was TYPED, and stays present so that rule can refuse it:
     * a cost on something that is not a program is a mistake worth a sentence,
     * not something to discard behind somebody's back.
     */
    private function panelNumber(string $field, bool $panelIsOn): ?string
    {
        $value = $this->numberOrNull($field);

        if ($panelIsOn || $value === null) {
            return $value;
        }

        return is_numeric($value) && (float) $value === 0.0 ? null : $value;
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
