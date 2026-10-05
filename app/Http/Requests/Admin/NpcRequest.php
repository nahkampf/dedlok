<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Combat\ArmourSlot;
use App\Combat\DamageProfile;
use App\Models\Item;
use App\Models\Npc;
use App\Models\Weapon;
use App\Support\Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An NPC, checked before the table has to refuse it. The CHECKs are the guarantee for the plain
 * columns; the rules the schema cannot hold are here, because three of the columns are foreign keys
 * with a delete rule and MySQL refuses a CHECK on those (error 3823): a ranged weapon is a Ranged
 * class, a melee weapon a Close one, and a standing round fits the weapon it is for.
 */
class NpcRequest extends FormRequest
{
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
            'flees_at' => ['nullable', 'numeric', 'between:0,1'],
            'ranged_weapon_id' => ['nullable', 'integer', 'exists:items,id'],
            'melee_weapon_id' => ['nullable', 'integer', 'exists:items,id'],
            'ammo_item_id' => ['nullable', 'integer', 'exists:items,id'],
            'resistances' => ['nullable', 'array'],
            'slot_resistances' => ['nullable', 'array'],
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

            if ($this->filled('ammo_item_id') && ! $validator->errors()->has('ranged_weapon_id')) {
                $round = Item::query()->with('ammunition')->find((int) $this->input('ammo_item_id'))?->ammunition;

                if ($round === null) {
                    $validator->errors()->add('ammo_item_id', Text::get('admin.npcs.validation.not_ammunition'));
                } elseif ($ranged === null || ! $ranged->class->firearm() || $ranged->calibre !== $round->calibre) {
                    $validator->errors()->add('ammo_item_id', Text::get('admin.npcs.validation.wrong_round'));
                }
            }

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
        $data = $this->safe()->except(['resistances', 'slot_resistances']);

        // A slug is permanent. `prohibited` lets an empty value through, so on update it is dropped here.
        if ($this->npc() !== null) {
            unset($data['slug']);
        }

        foreach (['flees_at', 'ranged_weapon_id', 'melee_weapon_id', 'ammo_item_id', 'flavor'] as $nullable) {
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

        return $data + [
            'resistances' => $this->clean((array) $this->input('resistances', [])) ?: null,
            'slot_resistances' => $slots ?: null,
        ];
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
