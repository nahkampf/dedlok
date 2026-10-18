@use('App\Support\Text')
@props(['model' => null])

{{-- The two numbers of a meal, for any editor that authors one (a safehouse; later a location's menu). Validated by
     App\Characters\Meal::rules(). --}}
<label class="field">
    <span class="field__label">{{ Text::get('meal.fullness') }}</span>
    <input class="input num" type="number" name="meal_fullness" min="0" max="{{ config('fatigue.fullness.max') }}" step="0.01" value="{{ old('meal_fullness', $model?->meal_fullness ?? 0) }}">
    <span class="field__hint">{{ Text::get('meal.fullness_hint', ['max' => config('fatigue.fullness.max')]) }}</span>
</label>
<label class="field">
    <span class="field__label">{{ Text::get('meal.fatigue') }}</span>
    <input class="input num" type="number" name="meal_fatigue" min="0" max="9999.99" step="0.01" value="{{ old('meal_fatigue', $model?->meal_fatigue ?? 0) }}">
    <span class="field__hint">{{ Text::get('meal.fatigue_hint') }}</span>
</label>
