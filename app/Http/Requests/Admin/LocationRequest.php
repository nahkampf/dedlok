<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Characters\Meal;
use App\City\ClinicService;
use App\City\Concealment;
use App\City\DiscoveryRule;
use App\Insurance\Insurance;
use App\Insurance\InsuranceKind;
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
                ? ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/', 'unique:locations,slug']
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
            'shop' => ['nullable', 'array', 'max:100'],
            'shop.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'shop.*.price' => ['nullable', 'integer', 'between:0,1000000'],
            'shop.*.max_stock' => ['nullable', 'integer', 'between:0,1000000'],
            'shop.*.restock_amount' => ['nullable', 'integer', 'between:0,1000000'],
            'shop.*.restock_hours' => ['nullable', 'integer', 'between:1,65535'],
            'buys' => ['nullable', 'array', 'max:60'],
            'buys.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'buys.*.percent' => ['nullable', 'integer', 'between:0,1000'],
            'buys.*.max_quantity' => ['nullable', 'integer', 'between:1,1000000'],
            'surgery' => ['nullable', 'array'],
            'surgery.offered' => ['nullable', 'boolean'],
            'surgery.rating' => ['nullable', 'integer', 'between:0,200', 'required_with:surgery.offered'],
            'surgery.install_price' => ['nullable', 'integer', 'between:0,1000000', 'required_with:surgery.offered'],
            'surgery.removal_price' => ['nullable', 'integer', 'between:0,1000000', 'required_with:surgery.offered'],
            'meals' => ['nullable', 'array', 'max:30'],
            'meals.*.name' => ['nullable', 'string', 'max:100'],
            'meals.*.price' => ['nullable', 'integer', 'between:0,1000000'],
            ...Meal::rules('meals.*.'),
            'insurance' => ['nullable', 'array'],
            'insurance.*' => ['nullable', 'array'],
            'insurance.*.*' => ['nullable', 'integer', 'between:0,1000000'],
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
     * The menu: a row with a name is a meal, blank rows dropped.
     *
     * @return list<array{name: string, price: int, meal_fullness: float, meal_fatigue: float}>
     */
    public function mealRows(): array
    {
        $rows = [];

        foreach ((array) $this->input('meals', []) as $row) {
            if (trim((string) ($row['name'] ?? '')) !== '') {
                $rows[] = [
                    'name' => trim((string) $row['name']),
                    'price' => (int) ($row['price'] ?? 0),
                    'meal_fullness' => (float) ($row['meal_fullness'] ?? 0),
                    'meal_fatigue' => (float) ($row['meal_fatigue'] ?? 0),
                ];
            }
        }

        return $rows;
    }

    /**
     * What the place buys: one per item, blank rows dropped.
     *
     * @return list<array{item_id: int, percent: int, max_quantity: int|null}>
     */
    public function buyRows(): array
    {
        $rows = [];

        foreach ((array) $this->input('buys', []) as $row) {
            if (! empty($row['item_id'])) {
                $rows[(int) $row['item_id']] = [
                    'item_id' => (int) $row['item_id'],
                    'percent' => ($row['percent'] ?? '') === '' ? 100 : (int) $row['percent'],
                    'max_quantity' => ($row['max_quantity'] ?? '') === '' ? null : (int) $row['max_quantity'],
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * The clinic's surgery, or null when it does none.
     *
     * @return array{rating: int, install_price: int, removal_price: int}|null
     */
    public function surgeryRow(): ?array
    {
        if (! $this->boolean('surgery.offered')) {
            return null;
        }

        return [
            'rating' => (int) $this->input('surgery.rating'),
            'install_price' => (int) $this->input('surgery.install_price'),
            'removal_price' => (int) $this->input('surgery.removal_price'),
        ];
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

    /**
     * The policies sold: a blank price means not offered. Only real kinds and tiers.
     *
     * @return list<array{kind: string, share: int, price: int}>
     */
    public function insuranceRows(): array
    {
        $rows = [];

        foreach ((array) $this->input('insurance', []) as $kind => $tiers) {
            foreach ((array) $tiers as $share => $price) {
                if (InsuranceKind::tryFrom((string) $kind) !== null && in_array((int) $share, Insurance::TIERS, true) && $price !== null && $price !== '') {
                    $rows[] = ['kind' => (string) $kind, 'share' => (int) $share, 'price' => (int) $price];
                }
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        $data = $this->safe()->only(['district_id', 'slug', 'name', 'description', 'map_x', 'map_y', 'discovery', 'concealment', 'required_flag']);

        // A slug is permanent. `prohibited` lets an empty value through, so on update it is dropped
        // here rather than trusted: writing the NULL it arrives as would be a 500.
        if ($this->route('location') !== null) {
            unset($data['slug']);
        }

        return $data + ['concealment' => null, 'required_flag' => null];
    }
}
