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
