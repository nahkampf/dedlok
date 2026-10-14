<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\EntityOffer;
use App\Standing\Service;
use App\Support\Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One row on an entity's loyalty shelf, checked before the table has to refuse it.
 *
 * Every rule here has a counterpart in the schema — the CHECKs and the unique
 * key are the guarantee, these are the explanation. What a database returns is
 * a 500 and a constraint name; what this returns is a sentence naming the
 * field.
 *
 * The one rule with no counterpart SQL can express against a single row is the
 * uniqueness of (entity, item), because it has to ignore the row being edited.
 */
class EntityOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /**
     * An empty number field is the DEFAULT, not a zero.
     *
     * The shelf's two ends and its standing gate all have sensible defaults,
     * and somebody adding a row should be able to leave them alone: a blank
     * span means every desk, and a blank gate means the desk's own.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'min_grade' => $this->filled('min_grade') ? $this->input('min_grade') : 1,
            'max_grade' => $this->filled('max_grade') ? $this->input('max_grade') : 5,
            'min_standing' => $this->filled('min_standing') ? $this->input('min_standing') : 0,
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var EntityOffer|null $offer */
        $offer = $this->route('offer');

        return [
            // AN ITEM OR A SERVICE, never both and never neither. Not a CHECK:
            // item_id's foreign key cascades, and MySQL will not put a CHECK
            // on such a column, so this is the only place the rule lives.
            'item_id' => [
                'nullable',
                'required_without:service',
                'prohibits:service',
                'integer',
                Rule::exists('items', 'id'),
                // ONE OFFER PER ITEM PER ENTITY. Two rows would be two ranges,
                // and an overlap would be two prices at one desk with nothing
                // to say which applies.
                Rule::unique('entity_offers', 'item_id')
                    ->where('entity_id', $this->route('entity')?->getKey())
                    ->ignore($offer?->getKey()),
            ],

            'service' => [
                'nullable',
                Rule::enum(Service::class),
                Rule::unique('entity_offers', 'service')
                    ->where('entity_id', $this->route('entity')?->getKey())
                    ->ignore($offer?->getKey()),
            ],
            'service_amount' => ['exclude_without:service', 'required', 'numeric', 'min:0.1', 'max:99999.9'],

            // Inclusive at both ends, and `lte`/`gte` rather than a custom
            // rule so the message names the field somebody has to fix.
            'min_grade' => ['required', 'integer', 'min:1', 'max:5', 'lte:max_grade'],
            'max_grade' => ['required', 'integer', 'min:1', 'max:5', 'gte:min_grade'],

            'loyalty_price' => ['required', 'numeric', 'min:0', 'max:999999999'],

            // Nobody sells at negative standing — the same rule the desk's own
            // gate keeps, and the same CHECK.
            'min_standing' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'item_id.unique' => Text::get('admin.validation.offer_twice'),
            'service.unique' => Text::get('admin.validation.offer_twice'),
            'min_grade.lte' => Text::get('admin.validation.grades_inverted'),
            'max_grade.gte' => Text::get('admin.validation.grades_inverted'),
        ];
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        return [
            'item_id' => $this->filled('item_id') ? (int) $this->input('item_id') : null,
            'service' => $this->input('service') ?: null,
            'service_amount' => $this->filled('service') ? (float) $this->input('service_amount') : null,
            'min_grade' => (int) $this->input('min_grade'),
            'max_grade' => (int) $this->input('max_grade'),
            'loyalty_price' => \App\Support\Points::of($this->input('loyalty_price'), 3),
            'min_standing' => (float) $this->input('min_standing'),
        ];
    }
}
