@use('App\Support\Text')
@extends('layouts.app')
@section('title', $safehouse ? $safehouse->title : Text::get('admin.safehouses.new'))

@php
    $creating = $safehouse === null;
    $value = fn (string $field, $fallback = null) => old($field, $safehouse?->{$field} ?? $fallback);
@endphp

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li><a href="{{ route('admin.safehouses.index') }}">{{ Text::get('admin.safehouses.title') }}</a></li>
        <li aria-current="page">{{ $creating ? Text::get('admin.safehouses.new') : $safehouse->title }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ $creating ? Text::get('admin.safehouses.new') : $safehouse->title }}</h1>

    <x-auth-errors />

    <form method="POST" action="{{ $creating ? route('admin.safehouses.store') : route('admin.safehouses.update', $safehouse) }}"
          class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @csrf
        @unless ($creating) @method('PUT') @endunless

        <div class="panel">
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                @if ($creating)
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.safehouses.slug') }}</span>
                        <input class="input" name="slug" maxlength="50" required value="{{ $value('slug') }}">
                        <span class="field__hint">{{ Text::get('admin.safehouses.slug_hint') }}</span>
                    </label>
                @endif
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.safehouses.name') }}</span>
                    <input class="input" name="title" maxlength="100" required value="{{ $value('title') }}">
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.safehouses.district') }}</span>
                    <select class="select" name="district_id" required>
                        @foreach ($districts as $district)
                            <option value="{{ $district->id }}" @selected((int) $value('district_id') === $district->id)>{{ $district->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.safehouses.rent') }}</span>
                    <input class="input num" type="number" name="rent" min="0" max="1000000" required value="{{ $value('rent', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.safehouses.rent_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.safehouses.rest_rate') }}</span>
                    <input class="input num" type="number" name="rest_rate" min="0" max="9999.99" step="0.01" required value="{{ $value('rest_rate', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.safehouses.rest_rate_hint') }}</span>
                </label>
                <x-meal-fields :model="$safehouse" />
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.safehouses.storage_slots') }}</span>
                    <input class="input num" type="number" name="storage_slots" min="0" max="500" required value="{{ $value('storage_slots', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.safehouses.storage_slots_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.safehouses.security') }}</span>
                    <input class="input num" type="number" name="security" min="0" max="100" required value="{{ $value('security', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.safehouses.security_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.safehouses.stealth') }}</span>
                    <input class="input num" type="number" name="stealth" min="0" max="100" required value="{{ $value('stealth', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.safehouses.stealth_hint') }}</span>
                </label>
            </div>
            <div class="panel__bd stack" style="--gap:var(--s-2)">
                <span class="field__label">{{ Text::get('admin.safehouses.actions') }}</span>
                <div class="row row--wrap" style="--gap:var(--s-4)">
                    @foreach (\App\Safehouses\Action::cases() as $action)
                        <label class="row" style="--gap:var(--s-2);align-items:center">
                            <input type="checkbox" name="actions[]" value="{{ $action->value }}" @checked(in_array($action->value, (array) old('actions', $safehouse?->actions ?? []), true))>
                            <span>{{ $action->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="panel__bd">
                <label class="field" style="margin:0">
                    <span class="field__label">{{ Text::get('admin.safehouses.description') }}</span>
                    <textarea class="input" name="description" rows="3" maxlength="2000">{{ $value('description') }}</textarea>
                </label>
            </div>
        </div>

        <div class="row" style="--gap:var(--s-3)">
            <button type="submit" class="btn btn--primary cut">{{ Text::get('admin.safehouses.save') }}</button>
        </div>
    </form>

    @unless ($creating)
        <form method="POST" action="{{ route('admin.safehouses.destroy', $safehouse) }}" style="margin-top:var(--s-6)"
              onsubmit="return confirm('{{ Text::get('admin.safehouses.confirm_delete', ['holders' => $holders]) }}')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn--ghost cut">{{ Text::get('admin.safehouses.delete') }}</button>
        </form>
    @endunless
@endsection
