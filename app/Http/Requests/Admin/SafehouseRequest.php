<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Characters\Meal;
use App\Models\Safehouse;
use App\Safehouses\Action;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A safehouse, checked before the table has to refuse it. The slug is permanent: config names the fallback by it.
 */
class SafehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var Safehouse|null $safehouse */
        $safehouse = $this->route('safehouse');

        return [
            'slug' => $safehouse === null
                ? ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/', 'unique:safehouses,slug']
                : ['prohibited'],
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'district_id' => ['required', 'integer', 'exists:districts,id'],
            'rent' => ['required', 'integer', 'between:0,1000000'],
            'security' => ['required', 'integer', 'between:0,100'],
            'stealth' => ['required', 'integer', 'between:0,100'],
            'rest_rate' => ['required', 'numeric', 'between:0,9999.99'],
            ...Meal::rules(),
            'storage_slots' => ['nullable', 'integer', 'between:0,500'],
            'actions' => ['nullable', 'array'],
            'actions.*' => ['string', Rule::enum(Action::class)],
        ];
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        $data = $this->safe()->only(['slug', 'title', 'description', 'district_id', 'rent', 'security', 'stealth', 'rest_rate', 'meal_fullness', 'meal_fatigue', 'storage_slots', 'actions']);
        $data['meal_fullness'] ??= 0;
        $data['meal_fatigue'] ??= 0;
        $data['storage_slots'] ??= 0;
        $data['actions'] = array_values((array) ($data['actions'] ?? []));

        // `prohibited` lets an empty value through, so on update the slug is dropped rather than written as NULL.
        if ($this->route('safehouse') !== null) {
            unset($data['slug']);
        }

        return $data;
    }
}
