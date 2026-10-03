<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\City\ClinicService;
use App\City\Concealment;
use App\City\DiscoveryRule;
use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A location. The discovery rule decides which of two fields is required and
 * which is forbidden, mirroring `chk_locations_discovery`. Pins are kept inside
 * 25-75% so the tooltip is not clipped at an edge of the district map.
 */
class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var Location|null $location */
        $location = $this->route('location');

        return [
            'district_id' => ['required', 'integer', 'exists:districts,id'],
            'slug' => $location === null
                ? ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', 'unique:locations,slug']
                : ['prohibited'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'map_x' => ['required', 'integer', 'between:25,75'],
            'map_y' => ['required', 'integer', 'between:25,75'],
            'discovery' => ['required', Rule::enum(DiscoveryRule::class)],
            'concealment' => [
                Rule::requiredIf(fn () => $this->input('discovery') === DiscoveryRule::Explorable->value),
                'nullable',
                Rule::enum(Concealment::class),
                'prohibited_unless:discovery,'.DiscoveryRule::Explorable->value,
            ],
            // What the place does. Blank rows are skipped, not errors.
            'shop' => ['nullable', 'array', 'max:60'],
            'shop.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'shop.*.price' => ['nullable', 'integer', 'between:0,1000000'],
            'shop.*.max_stock' => ['nullable', 'integer', 'between:0,1000000'],
            'shop.*.restock_amount' => ['nullable', 'integer', 'between:0,1000000'],
            'shop.*.restock_hours' => ['nullable', 'integer', 'between:1,65535'],
            'buys' => ['nullable', 'array', 'max:60'],
            'buys.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'buys.*.price' => ['nullable', 'integer', 'between:0,1000000'],
            'clinic' => ['nullable', 'array'],
            'clinic.*' => ['nullable', 'integer', 'between:0,1000000'],
            // The same slug shape `Flags::set()` insists on.
            'required_flag' => [
                Rule::requiredIf(fn () => $this->input('discovery') === DiscoveryRule::ExploredByFlag->value),
                'nullable',
                'regex:/^[a-z0-9_]{1,60}$/',
                'prohibited_unless:discovery,'.DiscoveryRule::ExploredByFlag->value,
            ],
        ];
    }

    /**
     * The shop's rows: one per item, blank rows dropped, the last price wins.
     *
     * @return list<array{item_id: int, price: int|null, max_stock: int|null, restock_amount: int, restock_hours: int|null}>
     */
    public function shopRows(): array
    {
        $rows = [];

        foreach ((array) $this->input('shop', []) as $row) {
            if (! empty($row['item_id'])) {
                $blank = static fn (string $key): bool => ($row[$key] ?? '') === '';

                $rows[(int) $row['item_id']] = [
                    'item_id' => (int) $row['item_id'],
                    'price' => $blank('price') ? null : (int) $row['price'],
                    'max_stock' => $blank('max_stock') ? null : (int) $row['max_stock'],
                    'restock_amount' => $blank('restock_amount') ? 0 : (int) $row['restock_amount'],
                    'restock_hours' => $blank('restock_hours') ? null : (int) $row['restock_hours'],
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * What the place buys: one per item, blank rows dropped.
     *
     * @return list<array{item_id: int, price: int|null}>
     */
    public function buyRows(): array
    {
        $rows = [];

        foreach ((array) $this->input('buys', []) as $row) {
            if (! empty($row['item_id'])) {
                $rows[(int) $row['item_id']] = [
                    'item_id' => (int) $row['item_id'],
                    'price' => ($row['price'] ?? '') === '' ? null : (int) $row['price'],
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * The clinic's services: a blank price means not offered. Only real services.
     *
     * @return array<string, int>
     */
    public function clinicRows(): array
    {
        $rows = [];

        foreach ((array) $this->input('clinic', []) as $service => $price) {
            if (ClinicService::tryFrom((string) $service) !== null && $price !== null && $price !== '') {
                $rows[(string) $service] = (int) $price;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        $data = $this->safe()->only(['district_id', 'slug', 'name', 'description', 'map_x', 'map_y', 'discovery', 'concealment', 'required_flag']);

        return $data + ['concealment' => null, 'required_flag' => null];
    }
}
