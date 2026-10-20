@use('App\Support\Text')
@extends('layouts.app')
@section('title', $grid ? $grid->name : Text::get('admin.subnets.new'))

@php
    $creating = $grid === null;
    $value = fn (string $field, $fallback = null) => old($field, $grid?->{$field} ?? $fallback);
    $sp = fn (string $field, $fallback = null) => old($field, $spec[$field] ?? $fallback);
    $chosenEntities = array_map('intval', (array) old('entities', $spec['entities'] ?? []));
    $chosenTypes = (array) old('types', $spec['types'] ?? []);
@endphp

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li><a href="{{ route('admin.subnets.index') }}">{{ Text::get('admin.subnets.title') }}</a></li>
        <li aria-current="page">{{ $creating ? Text::get('admin.subnets.new') : $grid->name }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ $creating ? Text::get('admin.subnets.new') : $grid->name }}</h1>

    <x-auth-errors />

    <form method="POST" action="{{ $creating ? route('admin.subnets.store') : route('admin.subnets.update', $grid->id) }}"
          class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @csrf
        @unless ($creating) @method('PUT') @endunless

        <div class="panel">
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.subnets.name') }}</span>
                    <input class="input" name="name" maxlength="80" required value="{{ $value('name') }}">
                </label>
                @if ($creating)
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.subnets.slug') }}</span>
                        <input class="input" name="slug" maxlength="40" required value="{{ $value('slug') }}">
                        <span class="field__hint">{{ Text::get('admin.subnets.slug_hint') }}</span>
                    </label>
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.subnets.prefix') }}</span>
                        <input class="input input--mono" name="prefix" minlength="2" maxlength="5" pattern="[a-z]{2,5}" required value="{{ $value('prefix') }}">
                        <span class="field__hint">{{ Text::get('admin.subnets.prefix_hint') }}</span>
                    </label>
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.subnets.size') }}</span>
                        <input class="input num" type="number" name="size" min="8" max="215" required value="{{ $value('size', 32) }}">
                        <span class="field__hint">{{ Text::get('admin.subnets.size_hint') }}</span>
                    </label>
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.subnets.entry_x') }}</span>
                        <input class="input num" type="number" name="entry_x" min="0" required value="{{ $value('entry_x', 16) }}">
                    </label>
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.subnets.entry_y') }}</span>
                        <input class="input num" type="number" name="entry_y" min="0" required value="{{ $value('entry_y', 16) }}">
                        <span class="field__hint">{{ Text::get('admin.subnets.entry_hint') }}</span>
                    </label>
                @else
                    <p class="muted" style="font-size:var(--fs-sm)">{{ $grid->prefix }} · {{ $grid->size }}×{{ $grid->size }} · {{ Text::get('admin.subnets.entry', ['x' => $grid->entry_x, 'y' => $grid->entry_y]) }} · {{ Text::get('admin.subnets.systems') }}: {{ $count }}</p>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.subnets.gateways') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.subnets.ice') }}</span>
                    <select class="select" name="ice_id">
                        <option value="">{{ Text::get('admin.subnets.no_ice') }}</option>
                        @foreach ($ices as $ice)
                            <option value="{{ $ice->id }}" @selected((int) old('ice_id', $gate?->ice_id) === $ice->id)>{{ $ice->name }} ({{ $ice->level }})</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.subnets.ice_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.subnets.flag') }}</span>
                    <input class="input input--mono" name="flag" maxlength="60" value="{{ old('flag', $flag) }}">
                    <span class="field__hint">{{ Text::get('admin.subnets.flag_hint') }}</span>
                </label>
                <label class="check">
                    <input type="checkbox" name="infiltrate" value="1" @checked(old('infiltrate', $infiltrate))>
                    <span class="check__box"></span>
                    <span>{{ Text::get('admin.subnets.infiltrate') }}</span>
                </label>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.subnets.spec') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.subnets.grade_min') }}</span>
                    <input class="input num" type="number" name="grade_min" min="1" max="9" required value="{{ $sp('grade_min', 5) }}">
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.subnets.grade_max') }}</span>
                    <input class="input num" type="number" name="grade_max" min="1" max="9" required value="{{ $sp('grade_max', 8) }}">
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.subnets.occupancy') }}</span>
                    <input class="input num" type="number" name="occupancy" min="1" max="95" required value="{{ $sp('occupancy', 40) }}">
                </label>
            </div>
            <div class="panel__bd stack" style="--gap:var(--s-2)">
                <span class="field__label">{{ Text::get('admin.subnets.types') }}</span>
                <span class="field__hint">{{ Text::get('admin.subnets.types_hint') }}</span>
                <div class="row row--wrap" style="--gap:var(--s-4)">
                    @foreach ($types as $type)
                        <label class="check">
                            <input type="checkbox" name="types[]" value="{{ $type->value }}" @checked(in_array($type->value, $chosenTypes, true))>
                            <span class="check__box"></span>
                            <span>{{ $type->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="panel__bd stack" style="--gap:var(--s-2)">
                <span class="field__label">{{ Text::get('admin.subnets.entities') }}</span>
                <span class="field__hint">{{ Text::get('admin.subnets.entities_hint') }}</span>
                <div class="row row--wrap" style="--gap:var(--s-4)">
                    @foreach ($entities as $entity)
                        <label class="check">
                            <input type="checkbox" name="entities[]" value="{{ $entity->id }}" @checked(in_array($entity->id, $chosenEntities, true))>
                            <span class="check__box"></span>
                            <span>{{ $entity->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.subnets.wardens') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-2)">
                <span class="field__hint">{{ Text::get('admin.subnets.wardens_hint') }}</span>
                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr))">
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.subnets.warden_count') }}</span>
                        <input class="input num" type="number" name="warden_count" min="0" max="200" value="{{ old('warden_count', $spec['wardens']['count'] ?? 0) }}">
                    </label>
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.subnets.warden_grade_min') }}</span>
                        <input class="input num" type="number" name="warden_grade_min" min="1" max="5" value="{{ old('warden_grade_min', $spec['wardens']['grade_min'] ?? 1) }}">
                    </label>
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.subnets.warden_grade_max') }}</span>
                        <input class="input num" type="number" name="warden_grade_max" min="1" max="5" value="{{ old('warden_grade_max', $spec['wardens']['grade_max'] ?? 1) }}">
                    </label>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.subnets.certificate') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-2)">
                <span class="field__hint">{{ Text::get('admin.subnets.certificate_hint') }}</span>
                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.subnets.certificate_chance') }}</span>
                        <input class="input num" type="number" name="certificate_chance" min="0" max="100" value="{{ old('certificate_chance', $spec['certificate']['chance'] ?? 0) }}">
                    </label>
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.subnets.certificate_min_grade') }}</span>
                        <input class="input num" type="number" name="certificate_min_grade" min="1" max="9" value="{{ old('certificate_min_grade', $spec['certificate']['min_grade'] ?? 1) }}">
                    </label>
                </div>
                <div class="row row--wrap" style="--gap:var(--s-4)">
                    @foreach ($types as $type)
                        <label class="check">
                            <input type="checkbox" name="certificate_types[]" value="{{ $type->value }}" @checked(in_array($type->value, (array) old('certificate_types', $spec['certificate']['types'] ?? []), true))>
                            <span class="check__box"></span>
                            <span>{{ $type->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="row" style="--gap:var(--s-3)">
            <button type="submit" class="btn btn--primary cut">{{ Text::get('admin.subnets.save') }}</button>
        </div>
    </form>

    @unless ($creating)
        <div class="row row--wrap" style="--gap:var(--s-3);margin-top:var(--s-6)">
            <form method="POST" action="{{ route('admin.subnets.generate', $grid->id) }}">
                @csrf
                <button type="submit" class="btn cut">{{ Text::get('admin.subnets.generate') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.subnets.destroy', $grid->id) }}"
                  onsubmit="return confirm('{{ Text::get('admin.subnets.confirm_delete') }}')">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn--ghost cut">{{ Text::get('admin.subnets.delete') }}</button>
            </form>
        </div>
    @endunless
@endsection
