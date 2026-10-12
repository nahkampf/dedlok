<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\City\DistrictColor;
use App\Models\District;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A district, checked before the table has to refuse it. The CHECKs are the
 * guarantee; these are the explanation. Coordinates are kept inside 20-80%
 * because a district's name box is wide and a label is cut off at an edge
 * (the 320px audit in CLAUDE.md).
 */
class DistrictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var District|null $district */
        $district = $this->route('district');

        return [
            // A slug is permanent: config and other rows name it.
            'slug' => $district === null
                ? ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/', 'unique:districts,slug']
                : ['prohibited'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['required', Rule::enum(DistrictColor::class)],
            'map_x' => ['required', 'integer', 'between:20,80'],
            'map_y' => ['required', 'integer', 'between:20,80'],
            'travel_weight' => ['required', 'numeric', 'gt:0', 'max:99.99'],
            // Never a link to itself.
            'links' => ['nullable', 'array'],
            'links.*' => ['integer', 'exists:districts,id', Rule::notIn([$district?->getKey()])],
        ];
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        $data = $this->safe()->only(['slug', 'name', 'description', 'color', 'map_x', 'map_y', 'travel_weight']);

        // A slug is permanent. `prohibited` lets an empty value through, so on update it is dropped here
        // rather than trusted: writing the NULL it arrives as would be a 500.
        if ($this->route('district') !== null) {
            unset($data['slug']);
        }

        return $data;
    }

    /** @return list<int> */
    public function linkIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('links', []))));
    }
}
