<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

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
            // The same slug shape `Flags::set()` insists on.
            'required_flag' => [
                Rule::requiredIf(fn () => $this->input('discovery') === DiscoveryRule::ExploredByFlag->value),
                'nullable',
                'regex:/^[a-z0-9_]{1,60}$/',
                'prohibited_unless:discovery,'.DiscoveryRule::ExploredByFlag->value,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        $data = $this->safe()->only(['district_id', 'slug', 'name', 'description', 'map_x', 'map_y', 'discovery', 'concealment', 'required_flag']);

        return $data + ['concealment' => null, 'required_flag' => null];
    }
}
