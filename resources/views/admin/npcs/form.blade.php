@use('App\Support\Text')
@extends('layouts.app')
@section('title', $npc ? $npc->name : Text::get('admin.npcs.new'))

@php
    $creating = $npc === null;
    $value = fn (string $field, $fallback = null) => old($field, $npc?->{$field} ?? $fallback);
    $resist = fn (string $type) => old("resistances.{$type}", $npc?->resistances[$type] ?? '');
    $slotResist = fn (string $slot, string $type) => old("slot_resistances.{$slot}.{$type}", $npc?->slot_resistances[$slot][$type] ?? '');
    $ticked = collect(old('tags', $npc?->tags ?? []))->all();
    $rows = old('loot') !== null
        ? array_values(old('loot'))
        : $loot->map(fn ($d) => ['item_id' => $d->item_id, 'chance' => $d->chance, 'min' => $d->min_quantity, 'max' => $d->max_quantity])->values()->all();
    $rows = array_pad($rows, min(\App\Http\Requests\Admin\NpcRequest::MAX_LOOT, count($rows) + 3), ['item_id' => '', 'chance' => 100, 'min' => 1, 'max' => 1]);
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
          class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)" enctype="multipart/form-data">
        @csrf
        @unless ($creating) @method('PUT') @endunless

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.npcs.sections.who') }}</span></div>
            <div class="panel__bd row row--wrap" style="--gap:var(--s-5);align-items:flex-start">
                @unless ($creating)
                    {{-- Its own picture, or the folder's default (public/images/enemies/<id>.jpg, default.jpg). Uploaded below. --}}
                    @if ($pic = \App\Combat\NpcImage::url($npc))
                        <span class="entity-image entity-image--portrait"><img src="{{ $pic }}" alt="{{ $npc->name }}" width="300" height="400"></span>
                    @endif
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
                    <label class="field" style="grid-column:1/-1">
                        <span class="field__label">{{ Text::get('admin.npcs.image') }}</span>
                        <input class="input" name="image" type="file" accept="image/jpeg,image/png,image/webp">
                        <span class="field__hint">{{ Text::get('admin.npcs.image_hint') }}</span>
                        @error('image') <span class="field__error">{{ $message }}</span> @enderror
                    </label>
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.npcs.image_credit') }}</span>
                        <input class="input" name="image_credit" maxlength="255" value="{{ $value('image_credit') }}">
                        @error('image_credit') <span class="field__error">{{ $message }}</span> @enderror
                    </label>
                    <label class="field">
                        <span class="field__label">{{ Text::get('admin.npcs.image_link') }}</span>
                        <input class="input" type="url" name="image_link" maxlength="255" value="{{ $value('image_link') }}">
                        @error('image_link') <span class="field__error">{{ $message }}</span> @enderror
                    </label>
                    @if (! $creating && \App\Combat\NpcImage::exists($npc))
                        <label class="check" style="grid-column:1/-1">
                            <input type="checkbox" name="remove_image" value="1">
                            <span class="check__box"></span><span>{{ Text::get('admin.npcs.image_remove') }}</span>
                        </label>
                    @endif
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
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.flee_beyond') }}</span>
                    <input class="input num" type="number" name="flee_beyond" min="0" max="{{ max(0, \App\Combat\Fight\FightState::reach() - 1) }}" step="1" value="{{ $value('flee_beyond') }}">
                    <span class="field__hint">{{ Text::get('admin.npcs.flee_beyond_hint', ['default' => config('combat.distance.flee_beyond'), 'reach' => \App\Combat\Fight\FightState::reach()]) }}</span>
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
                            <option value="{{ $item->id }}" @selected((int) $value('ranged_weapon_id') === $item->id)>{{ $item->displayName() }} — {{ $item->weapon->class->label() }}, {{ $item->weapon->damage ?? $item->weapon->calibre }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.npcs.ranged_hint') }}</span>
                </label>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.ammo') }}</span>
                    <select class="select" name="ammo_item_id">
                        <option value="">{{ Text::get('admin.npcs.no_ammo') }}</option>
                        @foreach ($rounds as $item)
                            <option value="{{ $item->id }}" @selected((int) $value('ammo_item_id') === $item->id)>{{ $item->displayName() }} — {{ $item->ammunition->calibre }}, {{ $item->ammunition->damage }}</option>
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

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.npcs.sections.spoils') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-4)">
                <span class="field__hint">{{ Text::get('admin.npcs.spoils_hint') }}</span>
                <label class="field">
                    <span class="field__label">{{ Text::get('admin.npcs.entity') }}</span>
                    <select class="select" name="entity_id">
                        <option value="">{{ Text::get('admin.npcs.no_entity') }}</option>
                        @foreach ($entities as $entity)
                            <option value="{{ $entity->id }}" @selected((int) $value('entity_id') === $entity->id)>{{ $entity->name }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.npcs.entity_hint') }}</span>
                </label>

                <div class="stack" style="--gap:var(--s-2)">
                    <span class="field__label">{{ Text::get('admin.npcs.tags') }}</span>
                    <span class="field__hint">{{ Text::get('admin.npcs.tags_hint') }}</span>
                    <div class="row row--wrap" style="--gap:var(--s-5)">
                        @foreach ($tags as $tag)
                            <label class="check">
                                <input type="checkbox" name="tags[]" value="{{ $tag->value }}" @checked(in_array($tag->value, $ticked, true))>
                                <span class="check__box"></span><span>{{ $tag->label() }} — {{ $tag->hint() }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="stack" style="--gap:var(--s-2)">
                    <span class="field__label">{{ Text::get('admin.npcs.loot') }}</span>
                    <span class="field__hint">{{ Text::get('admin.npcs.loot_hint') }}</span>
                    @foreach ($rows as $i => $row)
                        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(110px,100%),1fr))">
                            <label class="field" style="margin:0;grid-column:span 2">
                                <span class="micro" style="color:var(--ink-3)">{{ Text::get('admin.npcs.loot_item') }}</span>
                                <select class="select" name="loot[{{ $i }}][item_id]">
                                    <option value="">{{ Text::get('admin.npcs.loot_none') }}</option>
                                    @foreach ($items as $item)
                                        <option value="{{ $item->id }}" @selected((int) ($row['item_id'] ?? 0) === $item->id)>{{ $item->displayName() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="field" style="margin:0"><span class="micro" style="color:var(--ink-3)">{{ Text::get('admin.npcs.loot_chance') }}</span>
                                <input class="input num" type="number" min="1" max="100" step="1" name="loot[{{ $i }}][chance]" value="{{ $row['chance'] ?? 100 }}"></label>
                            <label class="field" style="margin:0"><span class="micro" style="color:var(--ink-3)">{{ Text::get('admin.npcs.loot_min') }}</span>
                                <input class="input num" type="number" min="1" step="1" name="loot[{{ $i }}][min]" value="{{ $row['min'] ?? 1 }}"></label>
                            <label class="field" style="margin:0"><span class="micro" style="color:var(--ink-3)">{{ Text::get('admin.npcs.loot_max') }}</span>
                                <input class="input num" type="number" min="1" step="1" name="loot[{{ $i }}][max]" value="{{ $row['max'] ?? 1 }}"></label>
                        </div>
                    @endforeach
                </div>
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
