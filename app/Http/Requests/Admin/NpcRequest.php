<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Combat\ArmourSlot;
use App\Combat\CombatTag;
use App\Combat\DamageProfile;
use App\Combat\Fight\FightState;
use App\Models\Item;
use App\Models\Npc;
use App\Models\Weapon;
use App\Support\Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An NPC, checked before the table has to refuse it. The CHECKs are the guarantee for the plain
 * columns; the rules the schema cannot hold are here, because three of the columns are foreign keys
 * with a delete rule and MySQL refuses a CHECK on those (error 3823): a ranged weapon is a Ranged
 * class, a melee weapon a Close one, and a standing round fits the weapon it is for.
 */
class NpcRequest extends FormRequest
{
    /** Rows the loot panel can hold: a drop list longer than this is a different design. */
    public const MAX_LOOT = 12;

    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // A slug is permanent: other rows will name it.
            'slug' => $this->npc() === null
                ? ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/', 'unique:npcs,slug']
                : ['prohibited'],
            'name' => ['required', 'string', 'max:100'],
            'flavor' => ['nullable', 'string', 'max:2000'],
            'grade' => ['required', 'integer', 'between:1,5'],
            'hitpoints' => ['required', 'integer', 'between:1,9999'],
            'aim' => ['required', 'numeric', 'between:0,99.9'],
            'defence' => ['required', 'numeric', 'between:0,99.9'],
            'pursuit' => ['required', 'numeric', 'between:0,99.9'],
            // A share of its hit points. Blank never flees.
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
            'image_credit' => ['nullable', 'string', 'max:255'],
            'image_link' => ['nullable', 'url:http,https', 'max:255'],
            'flees_at' => ['nullable', 'numeric', 'between:0,1'],
            // How far from the meeting point it must be to Disengage: blank is the fight's default. Never past the fight's reach.
            'flee_beyond' => ['nullable', 'integer', 'min:0', 'max:'.(max(0, FightState::reach() - 1))],
            'ranged_weapon_id' => ['nullable', 'integer', 'exists:items,id'],
            'melee_weapon_id' => ['nullable', 'integer', 'exists:items,id'],
            'ammo_item_id' => ['nullable', 'integer', 'exists:items,id'],
            'resistances' => ['nullable', 'array'],
            'slot_resistances' => ['nullable', 'array'],
            // Scenery keeps no books, so an opponent owned by it would move nothing: refused.
            'entity_id' => ['nullable', 'integer', Rule::exists('entities', 'id')->where('interactable', true)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', Rule::in(CombatTag::values())],
            'loot' => ['nullable', 'array', 'max:'.self::MAX_LOOT],
            'loot.*.item_id' => ['nullable', 'integer'],
            'loot.*.chance' => ['nullable', 'integer'],
            'loot.*.min' => ['nullable', 'integer'],
            'loot.*.max' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ranged = $this->weaponOf('ranged_weapon_id');
            $melee = $this->weaponOf('melee_weapon_id');

            if ($this->filled('ranged_weapon_id') && ($ranged === null || ! $ranged->class->ranged())) {
                $validator->errors()->add('ranged_weapon_id', Text::get('admin.npcs.validation.not_ranged'));
            }

            if ($this->filled('melee_weapon_id') && ($melee === null || $melee->class->ranged())) {
                $validator->errors()->add('melee_weapon_id', Text::get('admin.npcs.validation.not_melee'));
            }

            // A firearm's damage is its round's: a gunman with no standing round has nothing to fire.
            if ($ranged !== null && $ranged->class->firearm() && ! $this->filled('ammo_item_id') && ! $validator->errors()->has('ranged_weapon_id')) {
                $validator->errors()->add('ammo_item_id', Text::get('admin.npcs.validation.needs_round'));
            }

            if ($this->filled('ammo_item_id') && ! $validator->errors()->has('ranged_weapon_id')) {
                $round = Item::query()->with('ammunition')->find((int) $this->input('ammo_item_id'))?->ammunition;

                if ($round === null) {
                    $validator->errors()->add('ammo_item_id', Text::get('admin.npcs.validation.not_ammunition'));
                } elseif ($ranged === null || ! $ranged->class->firearm() || $ranged->calibre !== $round->calibre) {
                    $validator->errors()->add('ammo_item_id', Text::get('admin.npcs.validation.wrong_round'));
                }
            }

            $this->checkLoot($validator);

            foreach (['resistances' => (array) $this->input('resistances', []), 'slot_resistances' => (array) $this->input('slot_resistances', [])] as $field => $raw) {
                $problem = $field === 'resistances' ? $this->problemIn($raw) : $this->slotProblem($raw);

                if ($problem !== null) {
                    $validator->errors()->add($field, Text::get("admin.npcs.validation.{$problem}"));
                }
            }
        });
    }

    /** The row's columns, resistances cleaned to what was meant. @return array<string, mixed> */
    public function columns(): array
    {
        $data = $this->safe()->except(['resistances', 'slot_resistances', 'tags', 'loot', 'image', 'remove_image']);

        // A slug is permanent. `prohibited` lets an empty value through, so on update it is dropped here.
        if ($this->npc() !== null) {
            unset($data['slug']);
        }

        foreach (['image_credit', 'image_link', 'flees_at', 'flee_beyond', 'ranged_weapon_id', 'melee_weapon_id', 'ammo_item_id', 'flavor', 'entity_id'] as $nullable) {
            if (($data[$nullable] ?? '') === '') {
                $data[$nullable] = null;
            }
        }

        // A weapon-less rider has nothing to fire: no ranged weapon, no standing round.
        if ($data['ranged_weapon_id'] === null) {
            $data['ammo_item_id'] = null;
        }

        $slots = [];

        foreach (ArmourSlot::values() as $slot) {
            $clean = $this->clean((array) (((array) $this->input('slot_resistances', []))[$slot] ?? []));

            if ($clean !== []) {
                $slots[$slot] = $clean;
            }
        }

        // No box ticked is the default, which is NULL; anything ticked replaces it (CombatTag).
        $tags = CombatTag::fromStored(array_values((array) $this->input('tags', [])));

        return $data + [
            'tags' => $this->input('tags') ? array_map(static fn (CombatTag $tag): string => $tag->value, $tags) : null,
            'resistances' => $this->clean((array) $this->input('resistances', [])) ?: null,
            'slot_resistances' => $slots ?: null,
        ];
    }

    /**
     * The drops, blank rows gone: a list of {item_id, chance, min, max}.
     *
     * @return list<array{item_id: int, chance: int, min_quantity: int, max_quantity: int}>
     */
    public function loot(): array
    {
        $rows = [];

        foreach ((array) $this->input('loot', []) as $row) {
            if (! is_array($row) || ($row['item_id'] ?? '') === '') {
                continue;
            }

            $rows[] = [
                'item_id' => (int) $row['item_id'],
                'chance' => (int) $row['chance'],
                'min_quantity' => (int) $row['min'],
                'max_quantity' => (int) $row['max'],
            ];
        }

        return $rows;
    }

    /**
     * Each drop names an item that exists and sits in the physical inventory (a data item would have to ask
     * the deck for room, and a drop cannot be refused), once; its chance is a whole percent and its
     * quantity a range from one up.
     */
    private function checkLoot(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('loot', []) as $index => $row) {
            if (! is_array($row) || ($row['item_id'] ?? '') === '') {
                continue;
            }

            $item = Item::query()->find((int) $row['item_id']);
            $chance = $row['chance'] ?? '';
            $min = $row['min'] ?? '';
            $max = $row['max'] ?? '';

            $problem = match (true) {
                $item === null => 'loot_no_item',
                $item->container !== Item::CONTAINER_PHYSICAL => 'loot_not_physical',
                in_array($item->getKey(), $seen, true) => 'loot_twice',
                ! is_numeric($chance) || (int) $chance < 1 || (int) $chance > 100 => 'loot_chance',
                ! is_numeric($min) || ! is_numeric($max) || (int) $min < 1 || (int) $max < (int) $min => 'loot_quantity',
                default => null,
            };

            if ($problem !== null) {
                $validator->errors()->add("loot.{$index}", Text::get("admin.npcs.validation.{$problem}"));
            }

            if ($item !== null) {
                $seen[] = $item->getKey();
            }
        }
    }

    private function npc(): ?Npc
    {
        $npc = $this->route('npc');

        return $npc instanceof Npc ? $npc : null;
    }

    private function weaponOf(string $field): ?Weapon
    {
        return $this->filled($field) ? Item::query()->with('weapon')->find((int) $this->input($field))?->weapon : null;
    }

    /**
     * Only the eight types, only non-zero whole percentages, in a fixed order.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, int>
     */
    private function clean(array $raw): array
    {
        $clean = [];

        foreach (DamageProfile::types() as $type) {
            $value = $raw[$type] ?? null;

            if (is_numeric($value) && (int) $value !== 0) {
                $clean[$type] = (int) $value;
            }
        }

        return $clean;
    }

    /** @param  array<string, mixed>  $raw */
    private function problemIn(array $raw): ?string
    {
        foreach ($raw as $type => $value) {
            if ($value === null || $value === '' || (string) $value === '0') {
                continue;
            }

            if (! in_array($type, DamageProfile::types(), true)) {
                return 'unknown_type';
            }

            if (! is_numeric($value) || (int) $value != $value || (int) $value < -100 || (int) $value > 100) {
                return 'bad_percent';
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $raw */
    private function slotProblem(array $raw): ?string
    {
        foreach ($raw as $slot => $types) {
            if (! in_array($slot, ArmourSlot::values(), true)) {
                return 'unknown_slot';
            }

            if (($problem = $this->problemIn((array) $types)) !== null) {
                return $problem;
            }
        }

        return null;
    }
}
