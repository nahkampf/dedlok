@use('App\Support\Text')
@extends('layouts.app')
@section('title', $district ? $district->name : Text::get('admin.districts.new'))

@php
    $creating = $district === null;
    $value = fn (string $field, $fallback = null) => old($field, $district?->{$field} ?? $fallback);
    $chosen = array_map('intval', (array) old('links', $linked));
@endphp

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li><a href="{{ route('admin.districts.index') }}">{{ Text::get('admin.districts.title') }}</a></li>
        <li aria-current="page">{{ $creating ? Text::get('admin.districts.new') : $district->name }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ $creating ? Text::get('admin.districts.new') : $district->name }}</h1>

    <x-auth-errors />

    <form method="POST" action="{{ $creating ? route('admin.districts.store') : route('admin.districts.update', $district) }}"
          class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @csrf
        @unless ($creating) @method('PUT') @endunless

        <div class="panel">
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                @if ($creating)
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.districts.slug') }}</span>
                        <input class="input" name="slug" maxlength="50" required value="{{ $value('slug') }}">
                        <span class="field__hint">{{ Text::get('admin.districts.slug_hint') }}</span>
                    </label>
                @endif
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.districts.name') }}</span>
                    <input class="input" name="name" maxlength="100" required value="{{ $value('name') }}">
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.districts.color') }}</span>
                    <select class="select" name="color">
                        @foreach ($colors as $color)
                            <option value="{{ $color->value }}" @selected($value('color', 'steel') instanceof \App\City\DistrictColor ? $value('color')->value === $color->value : $value('color', 'steel') === $color->value)>{{ $color->value }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.districts.x') }}</span>
                    <input class="input num" type="number" name="map_x" min="20" max="80" required value="{{ $value('map_x', 50) }}">
                    <span class="field__hint">{{ Text::get('admin.districts.range_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.districts.y') }}</span>
                    <input class="input num" type="number" name="map_y" min="20" max="80" required value="{{ $value('map_y', 50) }}">
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.districts.weight') }}</span>
                    <input class="input num" type="number" name="travel_weight" min="0.01" max="99.99" step="0.01" required value="{{ $value('travel_weight', 1) }}">
                    <span class="field__hint">{{ Text::get('admin.districts.weight_hint') }}</span>
                </label>
            </div>
            <div class="panel__bd">
                <label class="field" style="margin:0">
                    <span class="field__label">{{ Text::get('admin.districts.description') }}</span>
                    <textarea class="input" name="description" rows="3" maxlength="2000">{{ $value('description') }}</textarea>
                </label>
            </div>
        </div>

        @unless ($creating)
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('admin.districts.places') }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-2)">
                    <span class="field__hint">{{ Text::get('admin.districts.places_hint') }}</span>
                    <input type="hidden" name="positions" id="positions">
                    <x-city-map edit="#positions" :label="Text::get('city.district_map')"
                        :nodes="$district->locations->map(fn ($l) => [
                            'key' => (string) $l->id, 'label' => $l->name, 'x' => $l->map_x, 'y' => $l->map_y,
                            'href' => null, 'here' => false, 'pin' => true, 'hue' => $district->color->token(),
                        ])->all()" />
                </div>
            </div>
        @endunless

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.districts.links') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-2)">
                <span class="field__hint">{{ Text::get('admin.districts.links_hint') }}</span>
                <div class="row row--wrap" style="--gap:var(--s-4)">
                    @foreach ($others as $other)
                        <label class="check">
                            <input type="checkbox" name="links[]" value="{{ $other->id }}" @checked(in_array($other->id, $chosen, true))>
                            <span class="check__box"></span>
                            <span>{{ $other->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="row" style="--gap:var(--s-3)">
            <button type="submit" class="btn btn--primary cut">{{ Text::get('admin.districts.save') }}</button>
        </div>
    </form>

    @unless ($creating)
        <form method="POST" action="{{ route('admin.districts.destroy', $district) }}" style="margin-top:var(--s-6)"
              onsubmit="return confirm('{{ Text::get('admin.districts.confirm_delete') }}')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn--ghost cut">{{ Text::get('admin.districts.delete') }}</button>
        </form>
    @endunless
@endsection
