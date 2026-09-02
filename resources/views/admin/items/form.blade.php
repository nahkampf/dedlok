@use('App\Support\Text')
@use('App\Models\Item')
@extends('layouts.app')
@section('title', $item ? Text::get('admin.items.editing', ['item' => $item->slug]) : Text::get('admin.items.new'))

@php
    $creating = $item === null;
    // old() first, so a rejected submission comes back as it was typed.
    $value = fn (string $field, $fallback = null) => old($field, $item?->{$field} ?? $fallback);
    $isBook = old('use_op', $item?->use_op) === Item::USE_INJECT_SKILL;
    $hasCapacity = (int) old('capacity', $item?->capacity ?? 0) > 0;
    // The column is cast to a Rarity, so the model side hands back an enum and
    // old() hands back the posted string. Normalise to the string once.
    $rarityValue = old('rarity', $item?->rarity?->value ?? 'common');
@endphp

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li><a href="{{ route('admin.items.index') }}">{{ Text::get('admin.items.title') }}</a></li>
        <li aria-current="page">{{ $creating ? Text::get('admin.items.new') : $item->slug }}</li>
    </ol>

    <h1 style="margin-top:var(--s-3)">
        {{ $creating ? Text::get('admin.items.new') : Text::get('admin.items.editing', ['item' => $item->slug]) }}
    </h1>

    <x-auth-errors />

    <form method="POST"
          action="{{ $creating ? route('admin.items.store') : route('admin.items.update', $item) }}"
          class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @csrf
        @unless ($creating) @method('PUT') @endunless

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.identity') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">

                <div class="field" @error('slug') data-invalid @enderror>
                    <label class="field__label" for="slug">{{ Text::get('admin.items.fields.slug') }}</label>
                    @if ($creating)
                        <input class="input input--mono" id="slug" name="slug" value="{{ old('slug') }}" required>
                        <span class="field__hint">{{ Text::get('admin.items.fields.slug_hint') }}</span>
                    @else
                        {{-- Rendered, never posted. A slug is permanent, and the
                             request prohibits it outright rather than trusting
                             a disabled attribute the client controls. --}}
                        <input class="input input--mono" value="{{ $item->slug }}" disabled>
                        <span class="field__hint">{{ Text::get('admin.items.fields.slug_locked') }}</span>
                    @endif
                    @error('slug') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('name') data-invalid @enderror>
                    <label class="field__label" for="name">{{ Text::get('admin.items.fields.name') }}</label>
                    <input class="input" id="name" name="name" value="{{ $value('name') }}">
                    <span class="field__hint" data-book-note @unless ($isBook) hidden @endunless>
                        {{ Text::get('admin.items.fields.name_book') }}
                    </span>
                    @error('name') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" style="grid-column:1/-1" @error('flavor') data-invalid @enderror>
                    <label class="field__label" for="flavor">{{ Text::get('admin.items.fields.flavor') }}</label>
                    <textarea class="textarea" id="flavor" name="flavor">{{ $value('flavor') }}</textarea>
                    <span class="field__hint">{{ Text::get('admin.items.fields.flavor_hint') }}</span>
                    @error('flavor') <span class="field__error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.classification') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr))">

                <div class="field" @error('container') data-invalid @enderror>
                    <label class="field__label" for="container">{{ Text::get('admin.items.fields.container') }}</label>
                    <select class="select" id="container" name="container">
                        @foreach ($containers as $container)
                            <option value="{{ $container }}" @selected($value('container', Item::CONTAINER_PHYSICAL) === $container)>
                                {{ Text::get("admin.items.containers.{$container}") }}
                            </option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.container_hint') }}</span>
                    @error('container') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('tier') data-invalid @enderror>
                    <label class="field__label" for="tier">{{ Text::get('admin.items.fields.tier') }}</label>
                    <select class="select" id="tier" name="tier">
                        @for ($tier = 1; $tier <= $maxTier; $tier++)
                            <option value="{{ $tier }}" @selected((int) $value('tier', 1) === $tier)>
                                {{ Text::get("items.tiers.{$tier}") }}
                            </option>
                        @endfor
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.tier_hint') }}</span>
                    @error('tier') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('rarity') data-invalid @enderror>
                    <label class="field__label" for="rarity">{{ Text::get('admin.items.fields.rarity') }}</label>
                    <select class="select" id="rarity" name="rarity">
                        @foreach ($rarities as $rarity)
                            <option value="{{ $rarity->value }}" @selected($rarityValue === $rarity->value)>
                                {{ $rarity->label() }}
                            </option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.rarity_hint') }}</span>
                    @error('rarity') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('stackable') data-invalid @enderror>
                    <span class="field__label">{{ Text::get('admin.items.fields.stackable') }}</span>
                    <label class="switch">
                        <input type="checkbox" name="stackable" value="1" @checked($value('stackable', true))>
                        <span class="switch__track"></span>
                        <span>{{ Text::get('admin.items.fields.stackable_hint') }}</span>
                    </label>
                    @error('stackable') <span class="field__error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.use') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">

                <div class="field" @error('use_op') data-invalid @enderror>
                    <label class="field__label" for="use_op">{{ Text::get('admin.items.fields.use_op') }}</label>
                    <select class="select" id="use_op" name="use_op" data-controls="use-skill">
                        <option value="">{{ Text::get('admin.items.fields.use_op_none') }}</option>
                        @foreach ($useOps as $op)
                            <option value="{{ $op }}" @selected($value('use_op') === $op)>{{ $op }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.use_op_hint') }}</span>
                    @error('use_op') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                {{-- Shown only for an injecting item. Progressive: without JS
                     it is visible and the request still enforces the pairing,
                     which is where the guarantee actually lives. --}}
                <div class="field" data-when="use-skill" @unless ($isBook) hidden @endunless @error('use_skill_id') data-invalid @enderror>
                    <label class="field__label" for="use_skill_id">{{ Text::get('admin.items.fields.use_skill') }}</label>
                    <select class="select" id="use_skill_id" name="use_skill_id">
                        <option value="">—</option>
                        @foreach ($skills as $skill)
                            <option value="{{ $skill->id }}" @selected((int) $value('use_skill_id') === $skill->id)>
                                {{ $skill->title }} ({{ $skill->slug }})
                            </option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.use_skill_hint') }}</span>
                    @error('use_skill_id') <span class="field__error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.physical') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr))">

                <div class="field" @error('weight') data-invalid @enderror>
                    <label class="field__label" for="weight">{{ Text::get('admin.items.fields.weight') }}</label>
                    <input class="input num" id="weight" name="weight" type="number" step="0.01" min="0"
                           value="{{ $value('weight', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.items.fields.weight_hint') }}</span>
                    @error('weight') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('size') data-invalid @enderror>
                    <label class="field__label" for="size">{{ Text::get('admin.items.fields.size') }}</label>
                    <input class="input num" id="size" name="size" type="number" min="0" value="{{ $value('size', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.items.fields.size_hint') }}</span>
                    @error('size') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('capacity') data-invalid @enderror>
                    <label class="field__label" for="capacity">{{ Text::get('admin.items.fields.capacity') }}</label>
                    <input class="input num" id="capacity" name="capacity" type="number" min="0"
                           value="{{ $value('capacity', 0) }}" data-controls="accepts">
                    <span class="field__hint">{{ Text::get('admin.items.fields.capacity_hint') }}</span>
                    @error('capacity') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                {{-- The other half of chk_items_capacity_pair. --}}
                <div class="field" data-when="accepts" @unless ($hasCapacity) hidden @endunless @error('accepts') data-invalid @enderror>
                    <label class="field__label" for="accepts">{{ Text::get('admin.items.fields.accepts') }}</label>
                    <select class="select" id="accepts" name="accepts">
                        <option value="">{{ Text::get('admin.items.fields.accepts_none') }}</option>
                        @foreach ($containers as $container)
                            <option value="{{ $container }}" @selected($value('accepts') === $container)>
                                {{ Text::get("admin.items.containers.{$container}") }}
                            </option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.accepts_hint') }}</span>
                    @error('accepts') <span class="field__error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.market') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr))">

                <div class="field" @error('base_cost') data-invalid @enderror>
                    <label class="field__label" for="base_cost">{{ Text::get('admin.items.fields.base_cost') }}</label>
                    <input class="input num" id="base_cost" name="base_cost" type="number" min="0"
                           value="{{ $value('base_cost', 0) }}">
                    @error('base_cost') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('seed_quantity') data-invalid @enderror>
                    <label class="field__label" for="seed_quantity">{{ Text::get('admin.items.fields.seed_quantity') }}</label>
                    <input class="input num" id="seed_quantity" name="seed_quantity" type="number" min="0"
                           value="{{ $value('seed_quantity', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.items.fields.seed_quantity_hint') }}</span>
                    @error('seed_quantity') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <span class="field__label">{{ Text::get('admin.items.fields.on_market') }}</span>
                    <label class="switch">
                        <input type="checkbox" name="on_market" value="1" @checked($value('on_market', true))>
                        <span class="switch__track"></span>
                        <span>{{ Text::get('admin.items.fields.on_market_hint') }}</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="row row--wrap">
            <button type="submit" class="btn btn--primary cut">
                {{ $creating ? Text::get('admin.actions.create') : Text::get('admin.actions.save') }}
            </button>
            <a class="btn btn--ghost" href="{{ route('admin.items.index') }}">{{ Text::get('admin.actions.cancel') }}</a>
        </div>
    </form>

    @unless ($creating)
        <div style="margin-top:var(--s-6)">
            <x-delete-panel :action="route('admin.items.destroy', $item)" :name="$item->slug" :impact="$impact" />
        </div>
    @endunless
@endsection

@push('scripts')
    {{-- Progressive disclosure only. Every pairing it hides is enforced by
         App\Http\Requests\Admin\ItemRequest and by a CHECK constraint behind
         that, so a client with this script disabled is inconvenienced rather
         than able to save something the schema forbids. --}}
    <script>
        document.querySelectorAll('[data-controls]').forEach(function (control) {
            var target = document.querySelector('[data-when="' + control.dataset.controls + '"]');
            if (!target) return;

            var sync = function () {
                var on = control.type === 'number'
                    ? Number(control.value) > 0
                    : control.value !== '';
                target.hidden = !on;

                var note = document.querySelector('[data-book-note]');
                if (note && control.id === 'use_op') note.hidden = !on;
            };

            control.addEventListener('change', sync);
            control.addEventListener('input', sync);
            sync();
        });
    </script>
@endpush
