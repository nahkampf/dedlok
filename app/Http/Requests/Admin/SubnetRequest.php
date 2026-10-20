<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Cyberspace\Grade;
use App\Cyberspace\SystemType;
use App\Support\Text;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A subnet, checked before anything is written. The slug, prefix, size and entry square are permanent (addresses embed
 * the prefix, systems stand on the coordinates), so they are `prohibited` on update.
 */
class SubnetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /** @return array<string, string> The form's own labels, so a refusal names the field as the page does. */
    public function attributes(): array
    {
        return [
            'size' => Text::get('admin.subnets.size'),
            'entry_x' => Text::get('admin.subnets.entry_x'),
            'entry_y' => Text::get('admin.subnets.entry_y'),
            'prefix' => Text::get('admin.subnets.prefix'),
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $creating = $this->route('subnet') === null;
        $size = (int) $this->input('size');

        return [
            'slug' => $creating ? ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/', 'unique:grids,slug'] : ['prohibited'],
            'prefix' => $creating ? ['required', 'regex:/^[a-z]{2,5}$/', 'unique:grids,prefix'] : ['prohibited'],
            'size' => $creating ? ['required', 'integer', 'between:8,215'] : ['prohibited'],
            'entry_x' => $creating ? ['required', 'integer', 'min:0', 'lt:'.max($size, 1)] : ['prohibited'],
            'entry_y' => $creating ? ['required', 'integer', 'min:0', 'lt:'.max($size, 1)] : ['prohibited'],
            'name' => ['required', 'string', 'max:80'],
            'ice_id' => ['nullable', 'integer', 'exists:ice,id'],
            'flag' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'infiltrate' => ['nullable', 'boolean'],
            'entities' => ['nullable', 'array'],
            'entities.*' => ['integer', 'exists:entities,id'],
            'types' => ['nullable', 'array'],
            'types.*' => ['in:'.implode(',', array_map(fn (SystemType $t): string => $t->value, SystemType::ordinary()))],
            'grade_min' => ['required', 'integer', 'between:'.Grade::MIN.','.Grade::MAX],
            'grade_max' => ['required', 'integer', 'between:'.Grade::MIN.','.Grade::MAX, 'gte:grade_min'],
            'occupancy' => ['required', 'integer', 'between:1,95'],
            'warden_count' => ['nullable', 'integer', 'between:0,200'],
            'warden_grade_min' => ['nullable', 'integer', 'between:1,5'],
            'warden_grade_max' => ['nullable', 'integer', 'between:1,5', 'gte:warden_grade_min'],
            'certificate_types' => ['nullable', 'array'],
            'certificate_types.*' => ['in:'.implode(',', array_map(fn (SystemType $t): string => $t->value, SystemType::ordinary()))],
            'certificate_chance' => ['nullable', 'integer', 'between:0,100'],
            'certificate_min_grade' => ['nullable', 'integer', 'between:'.Grade::MIN.','.Grade::MAX],
        ];
    }

    /** @return array{slug: string, name: string, prefix: string, size: int, entry_x: int, entry_y: int} */
    public function gridColumns(): array
    {
        return [
            'slug' => (string) $this->input('slug'),
            'name' => (string) $this->input('name'),
            'prefix' => (string) $this->input('prefix'),
            'size' => (int) $this->input('size'),
            'entry_x' => (int) $this->input('entry_x'),
            'entry_y' => (int) $this->input('entry_y'),
        ];
    }

    /** @return array<string, mixed> */
    public function spec(): array
    {
        return [
            'entities' => array_map('intval', (array) $this->input('entities', [])),
            'types' => array_values((array) $this->input('types', [])),
            'grade_min' => (int) $this->input('grade_min'),
            'grade_max' => (int) $this->input('grade_max'),
            'occupancy' => (int) $this->input('occupancy'),
            'wardens' => [
                'count' => (int) $this->input('warden_count', 0),
                'grade_min' => (int) ($this->input('warden_grade_min') ?: 1),
                'grade_max' => (int) ($this->input('warden_grade_max') ?: 1),
            ],
            'certificate' => [
                'types' => array_values((array) $this->input('certificate_types', [])),
                'chance' => (int) $this->input('certificate_chance', 0),
                'min_grade' => (int) ($this->input('certificate_min_grade') ?: 1),
            ],
        ];
    }

    /** @return list<array<string, string>> */
    public function rulesList(): array
    {
        $flag = trim((string) $this->input('flag'));

        return [
            ...($flag !== '' ? [['kind' => 'flag', 'flag' => $flag]] : []),
            ...($this->boolean('infiltrate') ? [['kind' => 'infiltrate']] : []),
        ];
    }

    public function iceId(): ?int
    {
        return $this->filled('ice_id') ? (int) $this->input('ice_id') : null;
    }
}
