@use('App\Support\Text')
@extends('layouts.app')
@section('title', $location ? $location->name : Text::get('admin.locations.new'))

@php
    $creating = $location === null;
    $value = fn (string $field, $fallback = null) => old($field, $location?->{$field} ?? $fallback);
    $enum = fn (string $field, $fallback = null) => ($v = $value($field, $fallback)) instanceof \BackedEnum ? $v->value : $v;
@endphp

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li><a href="{{ route('admin.locations.index') }}">{{ Text::get('admin.locations.title') }}</a></li>
        <li aria-current="page">{{ $creating ? Text::get('admin.locations.new') : $location->name }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ $creating ? Text::get('admin.locations.new') : $location->name }}</h1>

    <x-auth-errors />

    <form method="POST" action="{{ $creating ? route('admin.locations.store') : route('admin.locations.update', $location) }}"
          class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @csrf
        @unless ($creating) @method('PUT') @endunless

        <div class="panel">
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                @if ($creating)
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.locations.slug') }}</span>
                        <input class="input" name="slug" maxlength="60" required value="{{ $value('slug') }}">
                    </label>
                @endif
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.locations.name') }}</span>
                    <input class="input" name="name" maxlength="100" required value="{{ $value('name') }}">
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.locations.district') }}</span>
                    <select class="select" name="district_id">
                        @foreach ($districts as $district)
                            <option value="{{ $district->id }}" @selected((int) $value('district_id') === $district->id)>{{ $district->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.locations.x') }}</span>
                    <input class="input num" type="number" name="map_x" min="25" max="75" required value="{{ $value('map_x', 50) }}">
                    <span class="field__hint">{{ Text::get('admin.locations.range_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.locations.y') }}</span>
                    <input class="input num" type="number" name="map_y" min="25" max="75" required value="{{ $value('map_y', 50) }}">
                </label>
            </div>
            <div class="panel__bd">
                <label class="field" style="margin:0">
                    <span class="field__label">{{ Text::get('admin.locations.description') }}</span>
                    <textarea class="input" name="description" rows="3" maxlength="2000">{{ $value('description') }}</textarea>
                </label>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.locations.discovery') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.locations.discovery') }}</span>
                    <select class="select" name="discovery">
                        @foreach ($rules as $rule)
                            <option value="{{ $rule->value }}" @selected($enum('discovery', 'open') === $rule->value)>{{ $rule->value }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.locations.discovery_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.locations.concealment') }}</span>
                    <select class="select" name="concealment">
                        <option value=""></option>
                        @foreach ($concealments as $concealment)
                            <option value="{{ $concealment->value }}" @selected($enum('concealment') === $concealment->value)>{{ $concealment->value }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.locations.flag') }}</span>
                    <input class="input" name="required_flag" maxlength="60" value="{{ $value('required_flag') }}">
                </label>
            </div>
        </div>

        @php($shopRows = old('shop', $shop))
        @php($shopRows = array_pad(array_values($shopRows), count($shopRows) + 3, ['item_id' => null, 'price' => null]))
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.locations.shop') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                <span class="field__hint">{{ Text::get('admin.locations.shop_hint') }}</span>
                @foreach ($shopRows as $i => $row)
                    <div class="row row--wrap" style="--gap:var(--s-3)">
                        <x-card-select name="shop[{{ $i }}][item_id]" :groups="$shopItems" :selected="$row['item_id'] ?? null" :label="Text::get('admin.locations.item')" style="flex:1 1 220px;min-width:0" />
                        <input class="input num" type="number" min="0" name="shop[{{ $i }}][price]" value="{{ $row['price'] ?? '' }}" placeholder="{{ Text::get('admin.locations.price_default') }}" style="width:7rem" aria-label="{{ Text::get('admin.locations.price') }}">
                        <input class="input num" type="number" min="0" name="shop[{{ $i }}][max_stock]" value="{{ $row['max_stock'] ?? '' }}" placeholder="{{ Text::get('admin.locations.unlimited') }}" style="width:7rem" aria-label="{{ Text::get('admin.locations.max_stock') }}">
                        <input class="input num" type="number" min="0" name="shop[{{ $i }}][restock_amount]" value="{{ $row['restock_amount'] ?? '' }}" placeholder="{{ Text::get('admin.locations.restock_amount') }}" style="width:7rem" aria-label="{{ Text::get('admin.locations.restock_amount') }}">
                        <input class="input num" type="number" min="1" name="shop[{{ $i }}][restock_hours]" value="{{ $row['restock_hours'] ?? '' }}" placeholder="{{ Text::get('admin.locations.restock_hours') }}" style="width:7rem" aria-label="{{ Text::get('admin.locations.restock_hours') }}">
                    </div>
                @endforeach
            </div>
        </div>

        @php($buyRows = old('buys', $buys))
        @php($buyRows = array_pad(array_values($buyRows), count($buyRows) + 3, ['item_id' => null, 'percent' => null, 'max_quantity' => null]))
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.locations.buys') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                <span class="field__hint">{{ Text::get('admin.locations.buys_hint') }}</span>
                @foreach ($buyRows as $i => $row)
                    <div class="row row--wrap" style="--gap:var(--s-3)">
                        <select class="select" name="buys[{{ $i }}][item_id]" style="flex:1 1 220px;min-width:0" aria-label="{{ Text::get('admin.locations.item') }}">
                            <option value=""></option>
                            <x-grouped-options :groups="$items" :selected="$row['item_id'] ?? null" />
                        </select>
                        <input class="input num" type="number" min="0" max="1000" name="buys[{{ $i }}][percent]" value="{{ $row['percent'] ?? '' }}" placeholder="{{ Text::get('admin.locations.buy_percent') }}" style="width:7rem" aria-label="{{ Text::get('admin.locations.buy_percent') }}">
                        <input class="input num" type="number" min="1" name="buys[{{ $i }}][max_quantity]" value="{{ $row['max_quantity'] ?? '' }}" placeholder="{{ Text::get('admin.locations.unlimited') }}" style="width:7rem" aria-label="{{ Text::get('admin.locations.buy_max') }}">
                    </div>
                @endforeach
            </div>
        </div>

        @php($mealRows = old('meals', $menu))
        @php($mealRows = array_pad(array_values($mealRows), count($mealRows) + 2, ['name' => '']))
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.locations.menu') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                <span class="field__hint">{{ Text::get('admin.locations.menu_hint') }}</span>
                @foreach ($mealRows as $i => $row)
                    <div class="row row--wrap" style="--gap:var(--s-3)">
                        <input class="input" name="meals[{{ $i }}][name]" maxlength="100" value="{{ $row['name'] ?? '' }}" placeholder="{{ Text::get('admin.locations.meal_name') }}" style="flex:1 1 180px;min-width:0" aria-label="{{ Text::get('admin.locations.meal_name') }}">
                        <input class="input num" type="number" min="0" name="meals[{{ $i }}][price]" value="{{ $row['price'] ?? '' }}" placeholder="{{ Text::get('admin.locations.price') }}" style="width:7rem" aria-label="{{ Text::get('admin.locations.price') }}">
                        <input class="input num" type="number" min="0" max="{{ config('fatigue.fullness.max') }}" step="0.01" name="meals[{{ $i }}][meal_fullness]" value="{{ $row['meal_fullness'] ?? '' }}" placeholder="{{ Text::get('meal.fullness') }}" style="width:7rem" aria-label="{{ Text::get('meal.fullness') }}">
                        <input class="input num" type="number" min="0" step="0.01" name="meals[{{ $i }}][meal_fatigue]" value="{{ $row['meal_fatigue'] ?? '' }}" placeholder="{{ Text::get('meal.fatigue') }}" style="width:7rem" aria-label="{{ Text::get('meal.fatigue') }}">
                    </div>
                @endforeach
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.locations.clinic') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                <span class="field__hint">{{ Text::get('admin.locations.clinic_hint') }}</span>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(300px,100%),1fr));gap:var(--s-2) var(--s-4)">
                    @foreach ($services as $service)
                        <label class="row" style="--gap:var(--s-3);align-items:center;justify-content:space-between;margin:0">
                            <span style="min-width:0">{{ $service->label() }}</span>
                            <input class="input num" type="number" min="0" name="clinic[{{ $service->value }}]" value="{{ old('clinic.'.$service->value, $clinic[$service->value] ?? '') }}" style="width:min(7rem,40%);flex:none">
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        @php($sg = old('surgery', $surgery ? ['offered' => 1, 'rating' => $surgery->rating, 'install_price' => $surgery->install_price, 'removal_price' => $surgery->removal_price] : []))
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.locations.surgery') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                <span class="field__hint">{{ Text::get('admin.locations.surgery_hint') }}</span>
                <label class="check">
                    <input type="checkbox" name="surgery[offered]" value="1" @checked(! empty($sg['offered']))>
                    <span class="check__box"></span>
                    <span>{{ Text::get('admin.locations.surgery_offered') }}</span>
                </label>
                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(160px,100%),1fr))">
                    <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.locations.rating') }}</span>
                        <input class="input num" type="number" min="0" max="200" name="surgery[rating]" value="{{ $sg['rating'] ?? '' }}">
                        @php($roller = app(\App\Checks\CheckRoller::class))
                        @foreach (config('city.surgery.difficulty') as $tier => $difficulty)
                            <span class="field__hint">{{ Text::get('admin.locations.rating_hint', ['tier' => $tier, 'difficulty' => $difficulty, 'chances' => collect([-8, 0, 8, 16])->map(fn ($d) => ($difficulty + $d).' → '.round($roller->chance($difficulty + $d, $difficulty, 'surgery') * 100).'%')->implode(', ')]) }}</span>
                        @endforeach
                    </label>
                    <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.locations.install_price') }}</span>
                        <input class="input num" type="number" min="0" name="surgery[install_price]" value="{{ $sg['install_price'] ?? '' }}"></label>
                    <label class="field" style="margin:0"><span class="field__label">{{ Text::get('admin.locations.removal_price') }}</span>
                        <input class="input num" type="number" min="0" name="surgery[removal_price]" value="{{ $sg['removal_price'] ?? '' }}"></label>
                </div>
            </div>
        </div>

        <div class="row" style="--gap:var(--s-3)">
            <button type="submit" class="btn btn--primary cut">{{ Text::get('admin.locations.save') }}</button>
        </div>
    </form>

    @unless ($creating)
        <form method="POST" action="{{ route('admin.locations.destroy', $location) }}" style="margin-top:var(--s-6)"
              onsubmit="return confirm('{{ Text::get('admin.locations.confirm_delete') }}')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn--ghost cut">{{ Text::get('admin.locations.delete') }}</button>
        </form>
    @endunless
@endsection
