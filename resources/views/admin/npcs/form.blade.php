@use('App\Support\Text')
@extends('layouts.app')
@section('title', $npc ? $npc->name : Text::get('admin.npcs.new'))

@php
    $creating = $npc === null;
    $value = fn (string $field, $fallback = null) => old($field, $npc?->{$field} ?? $fallback);
    $resist = fn (string $type) => old("resistances.{$type}", $npc?->resistances[$type] ?? '');
    $slotResist = fn (string $slot, string $type) => old("slot_resistances.{$slot}.{$type}", $npc?->slot_resistances[$slot][$type] ?? '');
    $hasSlots = collect(old('slot_resistances', $npc?->slot_resistances ?? []))->flatten()->filter()->isNotEmpty();
@endphp

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li><a href="{{ route('admin.npcs.index') }}">{{ Text::get('admin.npcs.title') }}</a></li>
        <li aria-current="page">{{ $creating ? Text::get('admin.npcs.new') : $npc->name }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ $creating ? Text::get('admin.npcs.new') : $npc->name }}</h1>

    <x-auth-errors />

    <form method="POST" action="{{ $creating ? route('admin.npcs.store') : route('admin.npcs.update', $npc) }}"
          class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @csrf
        @unless ($creating) @method('PUT') @endunless

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.npcs.sections.who') }}</span></div>
            <div class="panel__bd row row--wrap" style="--gap:var(--s-5);align-items:flex-start">
                @unless ($creating)
                    {{-- Found by convention, like every picture: public/images/npcs/<id>.jpg, 3:4. --}}
                    <div class="stack" style="--gap:var(--s-2)">
                        <x-entity-image kind="npcs" :id="$npc->id" :alt="$npc->name" portrait />
                        <span class="micro" style="color:var(--ink-3);max-width:min(300px,100%)">{{ Text::get('admin.npcs.picture_hint', ['path' => 'public/images/npcs/'.$npc->id.'.jpg']) }}</span>
                    </div>
                @endunless
                <div class="grid" style="flex:1 1 min(320px,100%);min-width:0;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                    @if ($creating)
                        <label class="field">
                            <span class="field__label">{{ Text::get('admin.npcs.slug') }}</span>
                            <input class="input" name="slug" maxlength="50" required value="{{ $value('slug') }}">
                            <span class="field__hint">{{ Text::get('admin.npcs.slug_hint') }}</span>
                        </label>
                    @endif
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.npcs.name') }}</span>
                        <input class="input" name="name" maxlength="100" required value="{{ $value('name') }}">
                    </label>
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.npcs.grade') }}</span>
                        <input class="input num" type="number" name="grade" min="1" max="5" required value="{{ $value('grade', 1) }}">
                        <span class="field__hint">{{ Text::get('admin.npcs.grade_hint') }}</span>
                    </label>
                    <label class="field" style="grid-column:1/-1">
                        <span class="field__label">{{ Text::get('admin.npcs.flavor') }}</span>
                        <textarea class="input" name="flavor" rows="3" maxlength="2000">{{ $value('flavor') }}</textarea>
                    </label>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.npcs.sections.numbers') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr))">
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.hitpoints') }}</span>
                    <input class="input num" type="number" name="hitpoints" min="1" max="9999" required value="{{ $value('hitpoints', 40) }}">
                    <span class="field__hint">{{ Text::get('admin.npcs.hitpoints_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.aim') }}</span>
                    <input class="input num" type="number" name="aim" min="0" max="99.9" step="0.1" required value="{{ $value('aim', 10) }}">
                    <span class="field__hint">{{ Text::get('admin.npcs.aim_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.defence') }}</span>
                    <input class="input num" type="number" name="defence" min="0" max="99.9" step="0.1" required value="{{ $value('defence', 8) }}">
                    <span class="field__hint">{{ Text::get('admin.npcs.defence_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.pursuit') }}</span>
                    <input class="input num" type="number" name="pursuit" min="0" max="99.9" step="0.1" required value="{{ $value('pursuit', 8) }}">
                    <span class="field__hint">{{ Text::get('admin.npcs.pursuit_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.flees_at') }}</span>
                    <input class="input num" type="number" name="flees_at" min="0" max="1" step="0.01" value="{{ $value('flees_at') }}">
                    <span class="field__hint">{{ Text::get('admin.npcs.flees_at_hint') }}</span>
                </label>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.npcs.sections.weapons') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.ranged') }}</span>
                    <select class="select" name="ranged_weapon_id">
                        <option value="">{{ Text::get('admin.npcs.no_ranged') }}</option>
                        @foreach ($rangedWeapons as $item)
                            <option value="{{ $item->id }}" @selected((int) $value('ranged_weapon_id') === $item->id)>{{ $item->displayName() }} — {{ $item->weapon->class->label() }}, {{ $item->weapon->damage }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.npcs.ranged_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.ammo') }}</span>
                    <select class="select" name="ammo_item_id">
                        <option value="">{{ Text::get('admin.npcs.no_ammo') }}</option>
                        @foreach ($rounds as $item)
                            <option value="{{ $item->id }}" @selected((int) $value('ammo_item_id') === $item->id)>{{ $item->displayName() }} — {{ $item->ammunition->calibre }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.npcs.ammo_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.melee') }}</span>
                    <select class="select" name="melee_weapon_id">
                        <option value="">{{ Text::get('combat.weapons.bare_hands') }}</option>
                        @foreach ($meleeWeapons as $item)
                            <option value="{{ $item->id }}" @selected((int) $value('melee_weapon_id') === $item->id)>{{ $item->displayName() }} — {{ $item->weapon->class->label() }}, {{ $item->weapon->damage }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.npcs.melee_hint') }}</span>
                </label>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.npcs.sections.resistances') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                <span class="field__hint">{{ Text::get('admin.npcs.resistances_hint') }}</span>
                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(130px,100%),1fr))">
                    @foreach ($types as $type => $label)
                        <label class="field" style="margin:0"><span class="field__label">{{ $label }}</span>
                            <input class="input num" type="number" min="-100" max="100" step="1" name="resistances[{{ $type }}]" value="{{ $resist($type) }}"></label>
                    @endforeach
                </div>

                <details @if ($hasSlots) open @endif>
                    <summary class="micro" style="cursor:pointer">{{ Text::get('admin.npcs.slot_resistances') }}</summary>
                    <div class="stack" style="--gap:var(--s-3);margin-top:var(--s-3)">
                        <span class="field__hint">{{ Text::get('admin.npcs.slot_resistances_hint') }}</span>
                        @foreach ($slots as $slot)
                            <div class="stack" style="--gap:var(--s-1)">
                                <span class="field__label">{{ $slot->label() }}</span>
                                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(130px,100%),1fr))">
                                    @foreach ($types as $type => $label)
                                        <label class="field" style="margin:0"><span class="micro" style="color:var(--ink-3)">{{ $label }}</span>
                                            <input class="input num" type="number" min="-100" max="100" step="1" name="slot_resistances[{{ $slot->value }}][{{ $type }}]" value="{{ $slotResist($slot->value, $type) }}"></label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            </div>
        </div>

        <div class="row row--wrap" style="--gap:var(--s-2)">
            <button type="submit" class="btn btn--primary cut">{{ Text::get('admin.npcs.save') }}</button>
            <a class="btn btn--ghost cut" href="{{ route('admin.npcs.index') }}">{{ Text::get('admin.npcs.cancel') }}</a>
        </div>
    </form>

    @unless ($creating)
        <form method="POST" action="{{ route('admin.npcs.destroy', $npc) }}" style="margin-top:var(--s-5)"
              onsubmit="return confirm({{ \Illuminate\Support\Js::from(Text::get('admin.npcs.delete_confirm', ['name' => $npc->name])) }})">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('admin.npcs.delete') }}</button>
        </form>
    @endunless
@endsection
