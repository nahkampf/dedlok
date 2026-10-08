@use('App\Support\Text')
@use('App\Models\Item')
@use('App\Decks\Hardware')
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

    // The same normalisation for the hardware select: a stored enum on one
    // side, a posted string on the other.
    $hardwareValue = old('hardware', $item?->hardware?->value ?? '');
    $variantValue = old('variant', $module?->variant?->value ?? '');
    // Which panels start open. Everything they hide is enforced by the
    // request, so this is disclosure and never a guarantee.
    $isDeck = $hardwareValue === Hardware::Deck->value;
    $isModule = $hardwareValue !== '' && ! $isDeck;

    // Software, the same way: the select decides whether the costs are shown,
    // and ItemRequest decides whether they may be saved.
    $programValue = old('program_kind', $item?->program?->kind?->value ?? '');
    $isProgram = $programValue !== '';
    // The same normalisation again for the drug family.
    $drugValue = old('drug_family', $item?->drug?->family?->value ?? '');
    $isDrug = $drugValue !== '';
    // Weapons and ammunition, the same way: the class (or the calibre) decides whether the panel is on.
    $weaponValue = old('weapon_class', $item?->weapon?->class?->value ?? '');
    $isWeapon = $weaponValue !== '';
    $ammoValue = old('ammo_calibre', $item?->ammunition?->calibre ?? '');
    $isAmmo = $ammoValue !== '';
    $weaponProfile = old('weapon_profile', $item?->weapon?->profile() ?? []);
    $ammoProfile = old('ammo_profile', $item?->ammunition?->profile() ?? []);
    // A socket counter: what was typed, else what is stored, else none.
    $slotCount = fn ($type) => (int) old('slots.'.$type->key(), $layout->count($type));
    $moduleValue = fn (string $field) => old($field, $module?->{$field});
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
                    <input class="input num" id="size" name="size" type="number" min="0" step="0.1" value="{{ $value('size', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.items.fields.size_hint') }}</span>
                    @error('size') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('capacity') data-invalid @enderror>
                    <label class="field__label" for="capacity">{{ Text::get('admin.items.fields.capacity') }}</label>
                    <input class="input num" id="capacity" name="capacity" type="number" min="0" step="0.1"
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

            {{-- What the ITEM grants whoever has it, as opposed to the software
                 on it: an implant's effects live here. Not gated on being an
                 implant, because MECHANICS is explicit that a thing with a row
                 carries its modifiers on that row — deciding here which kinds
                 of thing may is a second opinion about that. --}}
            <x-modifier-rows name="modifiers" :rows="\App\Admin\ModifierField::rows($item?->modifiers)" />
        </div>

        {{-- Software. The mirror of the hardware panel below, and the two are
             mutually exclusive without a rule saying so: hardware forces the
             container to physical, and a program is data.

             What the KIND does is not decoration. A program is a gate as well
             as a modifier — you cannot fight ICE without a running icebreaker
             — so the kind is asked about by name, and no set of modifiers adds
             up to having one. --}}

        {{-- IMPLANTS, and a panel of their own for the reason the other three
             kinds have one: the field that DECIDES what an item is should not
             be buried among weight and capacity. It lived under bulk once, and
             nobody could find it.

             One field, because there is one fact. What an implant DOES is the
             modifiers panel above, read by `InstalledImplants`; how many fit
             is `BodyRegion`'s answer and never the item's. --}}
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.implant') }}</span></div>
            <div class="panel__bd">
                <div class="field" @error('implant_region') data-invalid @enderror>
                    <label class="field__label" for="implant_region">{{ Text::get('admin.items.fields.implant_region') }}</label>
                    <select class="select" id="implant_region" name="implant_region">
                        <option value="">{{ Text::get('admin.items.fields.implant_region_none') }}</option>
                        @foreach (\App\Implants\BodyRegion::cases() as $region)
                            <option value="{{ $region->value }}" @selected($value('implant_region') === $region->value)>
                                {{ $region->label() }} &mdash; {{ Text::plural('admin.items.fields.implant_slots', $region->capacity()) }}
                            </option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.implant_region_hint') }}</span>
                    @error('implant_region') <span class="field__error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        {{-- Drugs. The third thing an item can also be, and the only one that
             is physical: software is data and deck hardware is a component,
             while this is a thing you swallow.

             WHAT IT DOES is the modifiers panel above, like an implant's. What
             it COSTS is here — the family decides which books a dose moves,
             and the two figures are how fast it moves them. --}}
        {{-- A consumable: what using one copy does, once. A number per kind of effect; blank is none, and an item with
             none is not a consumable. A drug may carry one too (taken, it also does this at once). Not an effect row above (those are resolved stats). --}}
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.consumable') }}</span></div>
            <div class="panel__bd">
                @foreach (\App\Items\UseEffect::cases() as $kind)
                    @php($field = 'use_'.$kind->value)
                    @php($stored = collect($item?->consumable?->effects ?? [])->firstWhere('kind', $kind->value)['amount'] ?? '')
                    <div class="field" @error($field) data-invalid @enderror>
                        <label class="field__label" for="{{ $field }}">{{ Text::get('admin.items.fields.'.$field) }}</label>
                        <input class="input num" id="{{ $field }}" name="{{ $field }}" type="number" min="1" step="1"
                               value="{{ old($field, $stored) }}">
                        <span class="field__hint">{{ Text::get('admin.items.fields.'.$field.'_hint') }}</span>
                        @error($field) <span class="field__error">{{ $message }}</span> @enderror
                    </div>
                @endforeach
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.drug') }}</span></div>
            <div class="panel__bd">
                <div class="field" @error('drug_family') data-invalid @enderror>
                    <label class="field__label" for="drug_family">{{ Text::get('admin.items.fields.drug_family') }}</label>
                    <select class="select" id="drug_family" name="drug_family" data-controls="drug">
                        <option value="">{{ Text::get('admin.items.fields.drug_family_none') }}</option>
                        @foreach ($drugFamilies as $family)
                            <option value="{{ $family->value }}" @selected($drugValue === $family->value)>{{ $family->label() }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.drug_family_hint') }}</span>
                    @error('drug_family') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div data-when="drug" @unless ($isDrug) hidden @endunless>
                    {{-- Only in a fight: counts ROUNDS, so the minutes below are not read. --}}
                    <label class="check" style="margin-top:var(--s-4)">
                        <input type="checkbox" name="fight_only" value="1" @checked(old('fight_only', $item?->drug?->fight_only ?? false))>
                        <span class="check__box"></span>
                        <span>{{ Text::get('admin.items.fields.fight_only') }}</span>
                    </label>
                    <span class="field__hint">{{ Text::get('admin.items.fields.fight_only_hint') }}</span>
                    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));margin-top:var(--s-3)">
                        @foreach (['duration_turns' => 1, 'turn_hitpoints' => 1] as $field => $step)
                            <div class="field" @error($field) data-invalid @enderror>
                                <label class="field__label" for="{{ $field }}">{{ Text::get("admin.items.fields.{$field}") }}</label>
                                <input class="input num" id="{{ $field }}" name="{{ $field }}" type="number" step="{{ $step }}"
                                       value="{{ old($field, $item?->drug?->{$field} ?? ($field === 'turn_hitpoints' ? 0 : '')) }}">
                                <span class="field__hint">{{ Text::get("admin.items.fields.{$field}_hint") }}</span>
                                @error($field) <span class="field__error">{{ $message }}</span> @enderror
                            </div>
                        @endforeach
                    </div>
                    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));margin-top:var(--s-4)">
                        @foreach (['duration_minutes' => 1, 'addictive' => 0.5, 'tolerance_gain' => 0.5] as $field => $step)
                            <div class="field" @error($field) data-invalid @enderror>
                                <label class="field__label" for="{{ $field }}">{{ Text::get("admin.items.fields.{$field}") }}</label>
                                <input class="input num" id="{{ $field }}" name="{{ $field }}" type="number" min="0" step="{{ $step }}"
                                       value="{{ old($field, $item?->drug?->{$field} ?? 0) }}">
                                <span class="field__hint">{{ Text::get("admin.items.fields.{$field}_hint") }}</span>
                                @error($field) <span class="field__error">{{ $message }}</span> @enderror
                            </div>
                        @endforeach
                    </div>

                    {{-- The exception, and it is nearly always blank. What too
                         much does belongs to the FAMILY — pharmacology rather
                         than branding — and this is how one drug disagrees.
                         Empty asks the family; it does not mean never. --}}
                    <div class="field" style="margin-top:var(--s-3)" @error('lethal_at') data-invalid @enderror>
                        <label class="field__label" for="lethal_at">{{ Text::get('admin.items.fields.lethal_at') }}</label>
                        <input class="input num" id="lethal_at" name="lethal_at" type="number" min="1" max="255"
                               style="width:min(180px,100%)"
                               value="{{ old('lethal_at', $item?->drug?->lethal_at ?? '') }}">
                        <span class="field__hint">{{ Text::get('admin.items.fields.lethal_at_hint') }}</span>
                        @error('lethal_at') <span class="field__error">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        </div>
        {{-- Armour. Worn on the body, a part at a time, so the one fact a bare item has no place for is
             WHICH parts it covers: a helmet one, a plate one, a set all six. What it RESISTS is the effects
             panel above, against the damage types, and it counts only where this panel says it is worn. --}}
        @php($armourTicked = collect(old('armour_slots', array_map(fn ($g) => $g->value, \App\Combat\WearSlot::groupsOf((array) ($item?->armour?->slots ?? [])))))->map(fn ($v) => (string) $v)->all())
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.armour') }}</span></div>
            <div class="panel__bd">
                <div class="field" @error('armour_slots') data-invalid @enderror>
                    <span class="field__label">{{ Text::get('admin.items.fields.armour_slots') }}</span>
                    <div class="row row--wrap" style="--gap:var(--s-4)">
                        @foreach ($armourSlots as $slot)
                            <label class="check">
                                <input type="checkbox" name="armour_slots[]" value="{{ $slot->value }}" @checked(in_array($slot->value, $armourTicked, true))>
                                <span class="check__box"></span>
                                <span>{{ $slot->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                    <span class="field__hint">{{ Text::get('admin.items.fields.armour_slots_hint') }}</span>
                    @error('armour_slots') <span class="field__error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>
        {{-- Weapons. A hit does ONE fixed damage — never a range. A FIREARM has none of its own: its round
             carries the damage and the profile, so leave both blank on a gun. A blade, a club or a grenade
             keeps them here. How well a hit lands is decided by the roll, not here. The class says which
             skill aims it and whether it is a Ranged or a Close weapon. The PROFILE says how the hit
             splits across damage types, so a stun baton can be part blunt and part electrical. --}}
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.weapon') }}</span></div>
            <div class="panel__bd">
                <div class="field" @error('weapon_class') data-invalid @enderror>
                    <label class="field__label" for="weapon_class">{{ Text::get('admin.items.fields.weapon_class') }}</label>
                    <select class="select" id="weapon_class" name="weapon_class" data-controls="weapon">
                        <option value="">{{ Text::get('admin.items.fields.weapon_class_none') }}</option>
                        @foreach ($weaponClasses as $class)
                            <option value="{{ $class->value }}" @selected($weaponValue === $class->value)>{{ $class->label() }} · {{ Text::get($class->ranged() ? 'combat.weapons.ranged' : 'combat.weapons.melee') }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.weapon_class_hint') }}</span>
                    @error('weapon_class') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div data-when="weapon" @unless ($isWeapon) hidden @endunless>
                    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(160px,100%),1fr));margin-top:var(--s-4)">
                        @foreach (['weapon_damage' => ['', 1], 'weapon_accuracy' => [0, 0], 'weapon_rate' => [1, 1]] as $field => [$default, $min])
                            <div class="field" @error($field) data-invalid @enderror>
                                <label class="field__label" for="{{ $field }}">{{ Text::get("admin.items.fields.{$field}") }}</label>
                                <input class="input num" id="{{ $field }}" name="{{ $field }}" type="number" step="1"
                                       @if ($field !== 'weapon_accuracy') min="{{ $min }}" @endif
                                       value="{{ old($field, $item?->weapon?->{str_replace('weapon_', '', $field)} ?? $default) }}">
                                <span class="field__hint">{{ Text::get("admin.items.fields.{$field}_hint") }}</span>
                                @error($field) <span class="field__error">{{ $message }}</span> @enderror
                            </div>
                        @endforeach
                        <div class="field" @error('weapon_magazine') data-invalid @enderror>
                            <label class="field__label" for="weapon_magazine">{{ Text::get('admin.items.fields.weapon_magazine') }}</label>
                            <input class="input num" id="weapon_magazine" name="weapon_magazine" type="number" min="1" step="1"
                                   value="{{ old('weapon_magazine', $item?->weapon?->magazine ?? '') }}">
                            <span class="field__hint">{{ Text::get('admin.items.fields.weapon_magazine_hint') }}</span>
                            @error('weapon_magazine') <span class="field__error">{{ $message }}</span> @enderror
                        </div>
                        <div class="field" @error('weapon_calibre') data-invalid @enderror>
                            <label class="field__label" for="weapon_calibre">{{ Text::get('admin.items.fields.weapon_calibre') }}</label>
                            <input class="input" id="weapon_calibre" name="weapon_calibre" maxlength="32"
                                   value="{{ old('weapon_calibre', $item?->weapon?->calibre ?? '') }}">
                            <span class="field__hint">{{ Text::get('admin.items.fields.weapon_calibre_hint') }}</span>
                            @error('weapon_calibre') <span class="field__error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="field" style="margin-top:var(--s-3)" @error('weapon_profile') data-invalid @enderror>
                        <span class="field__label">{{ Text::get('admin.items.fields.weapon_profile') }}</span>
                        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(130px,100%),1fr))">
                            @foreach ($damageTypes as $type => $label)
                                <label class="field" style="margin:0"><span class="field__label">{{ $label }}</span>
                                    <input class="input num" type="number" min="0" max="100" step="1" name="weapon_profile[{{ $type }}]"
                                           value="{{ $weaponProfile[$type] ?? '' }}"></label>
                            @endforeach
                        </div>
                        <span class="field__hint">{{ Text::get('admin.items.fields.weapon_profile_hint') }}</span>
                        @error('weapon_profile') <span class="field__error">{{ $message }}</span> @enderror
                    </div>

                    @include('admin.items._riders', ['name' => 'weapon_riders', 'current' => old('weapon_riders') ? [] : $item?->weapon?->riders(), 'hint' => 'admin.items.fields.weapon_riders_hint'])
                </div>
            </div>
        </div>

        {{-- Ammunition. What a firearm's hit IS: the whole damage of a hit with this round, how it splits across
             damage types, a penetration (how much of the target's resistance it ignores) and riders. It fits
             a weapon of the same calibre. It stacks. --}}
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.ammunition') }}</span></div>
            <div class="panel__bd">
                <div class="field" @error('ammo_calibre') data-invalid @enderror>
                    <label class="field__label" for="ammo_calibre">{{ Text::get('admin.items.fields.ammo_calibre') }}</label>
                    <input class="input" id="ammo_calibre" name="ammo_calibre" maxlength="32" style="max-width:min(280px,100%)"
                           value="{{ $ammoValue }}" data-controls="ammo">
                    <span class="field__hint">{{ Text::get('admin.items.fields.ammo_calibre_hint') }}</span>
                    @error('ammo_calibre') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div data-when="ammo" @unless ($isAmmo) hidden @endunless>
                    <div class="field" style="margin-top:var(--s-4)" @error('ammo_type') data-invalid @enderror>
                        <label class="field__label" for="ammo_type">{{ Text::get('admin.items.fields.ammo_type') }}</label>
                        <select class="select" id="ammo_type" name="ammo_type" style="max-width:min(280px,100%)">
                            @foreach ($ammoTypes as $type)
                                <option value="{{ $type->value }}" @selected(old('ammo_type', $item?->ammunition?->type?->value ?? 'standard') === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <span class="field__hint">{{ Text::get('admin.items.fields.ammo_type_hint') }}</span>
                        @error('ammo_type') <span class="field__error">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));margin-top:var(--s-4)">
                        @foreach (['ammo_damage' => 'damage', 'ammo_penetration' => 'penetration'] as $field => $column)
                            <div class="field" @error($field) data-invalid @enderror>
                                <label class="field__label" for="{{ $field }}">{{ Text::get("admin.items.fields.{$field}") }}</label>
                                <input class="input num" id="{{ $field }}" name="{{ $field }}" type="number" step="1"
                                       value="{{ old($field, $item?->ammunition?->{$column} ?? ($field === 'ammo_damage' ? '' : 0)) }}">
                                <span class="field__hint">{{ Text::get("admin.items.fields.{$field}_hint") }}</span>
                                @error($field) <span class="field__error">{{ $message }}</span> @enderror
                            </div>
                        @endforeach
                    </div>

                    <div class="field" style="margin-top:var(--s-3)" @error('ammo_profile') data-invalid @enderror>
                        <span class="field__label">{{ Text::get('admin.items.fields.ammo_profile') }}</span>
                        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(130px,100%),1fr))">
                            @foreach ($damageTypes as $type => $label)
                                <label class="field" style="margin:0"><span class="field__label">{{ $label }}</span>
                                    <input class="input num" type="number" min="0" max="100" step="1" name="ammo_profile[{{ $type }}]"
                                           value="{{ $ammoProfile[$type] ?? '' }}"></label>
                            @endforeach
                        </div>
                        <span class="field__hint">{{ Text::get('admin.items.fields.ammo_profile_hint') }}</span>
                        @error('ammo_profile') <span class="field__error">{{ $message }}</span> @enderror
                    </div>

                    @include('admin.items._riders', ['name' => 'ammo_riders', 'current' => old('ammo_riders') ? [] : $item?->ammunition?->riders(), 'hint' => 'admin.items.fields.ammo_riders_hint'])
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.software') }}</span></div>
            <div class="panel__bd">
                <div class="field" @error('program_kind') data-invalid @enderror>
                    <label class="field__label" for="program_kind">{{ Text::get('admin.items.fields.program_kind') }}</label>
                    <select class="select" id="program_kind" name="program_kind" data-controls="program">
                        <option value="">{{ Text::get('admin.items.fields.program_kind_none') }}</option>
                        @foreach ($programKinds as $kind)
                            <option value="{{ $kind->value }}" @selected($programValue === $kind->value)>{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.program_kind_hint') }}</span>
                    @error('program_kind') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div data-when="program" @unless ($isProgram) hidden @endunless>
                    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));margin-top:var(--s-4)">
                        {{-- Two of these are spent only while it runs, and the
                             third is paid by owning it at all. That is why they
                             are three numbers and not one. --}}
                        @foreach (['qflops_cost', 'memory_cost', 'storage_size'] as $field)
                            <div class="field" @error($field) data-invalid @enderror>
                                <label class="field__label" for="{{ $field }}">{{ Text::get("admin.items.fields.{$field}") }}</label>
                                <input class="input num" id="{{ $field }}" name="{{ $field }}" type="number" min="0" step="0.1"
                                       value="{{ old($field, $item?->program?->{$field} ?? 0) }}">
                                <span class="field__hint">{{ Text::get("admin.items.fields.{$field}_hint") }}</span>
                                @error($field) <span class="field__error">{{ $message }}</span> @enderror
                            </div>
                        @endforeach
                    </div>

                    <label class="check" style="margin-top:var(--s-3)">
                        <input type="hidden" name="background_process" value="0">
                        <input type="checkbox" name="background_process" value="1"
                               @checked(old('background_process', $item?->program?->background_process ?? false))>
                        <span class="check__box"></span>
                        <span>{{ Text::get('admin.items.fields.background_process') }}</span>
                    </label>
                    <span class="field__hint">{{ Text::get('admin.items.fields.background_process_hint') }}</span>

                    {{-- A Script is spent by running: it lasts this long, and is gone. Blank is an ordinary program. --}}
                    <div class="field" style="margin-top:var(--s-3)" @error('lifetime_seconds') data-invalid @enderror>
                        <label class="field__label" for="lifetime_seconds">{{ Text::get('admin.items.fields.lifetime_seconds') }}</label>
                        <input class="input num" id="lifetime_seconds" name="lifetime_seconds" type="number" min="1" step="1"
                               value="{{ old('lifetime_seconds', $item?->program?->lifetime_seconds) }}">
                        <span class="field__hint">{{ Text::get('admin.items.fields.lifetime_seconds_hint') }}</span>
                        @error('lifetime_seconds') <span class="field__error">{{ $message }}</span> @enderror
                    </div>

                    {{-- Whether two copies may be pointed at two things at
                         once. A property of the SOFTWARE: how many a character
                         may run is the program_threads stat, and an icebreaker
                         fighting two pieces of ICE is not a thing skill should
                         be able to buy. --}}
                    <label class="check" style="margin-top:var(--s-3)">
                        <input type="hidden" name="multithreaded" value="0">
                        <input type="checkbox" name="multithreaded" value="1"
                               @checked(old('multithreaded', $item?->program?->multithreaded ?? false))>
                        <span class="check__box"></span>
                        <span>{{ Text::get('admin.items.fields.multithreaded') }}</span>
                    </label>
                    <span class="field__hint">{{ Text::get('admin.items.fields.multithreaded_hint') }}</span>

                    {{-- A Decryptor can be limited to some encryptions; ticking none means any. --}}
                    <fieldset class="field" style="margin-top:var(--s-3)">
                        <legend class="field__label">{{ Text::get('admin.items.fields.program_decrypts') }}</legend>
                        <div class="row row--wrap" style="--gap:var(--s-4)">
                            @foreach ($encryptions as $encryption)
                                <label class="check">
                                    <input type="checkbox" name="program_decrypts[]" value="{{ $encryption->slug }}"
                                           @checked(in_array($encryption->slug, (array) old('program_decrypts', $item?->program?->decrypts ?? []), true))>
                                    <span class="check__box"></span>
                                    <span>{{ $encryption->title }}</span>
                                </label>
                            @endforeach
                        </div>
                        <span class="field__hint">{{ Text::get('admin.items.fields.program_decrypts_hint') }}</span>
                    </fieldset>

                    {{-- Rows rather than the JSON this used to be. Validated
                         by BUILDING each one — see ItemRequest — because a
                         shape that parses but names an op that does not exist
                         is a modifier that throws on somebody else's screen. --}}
                    <x-modifier-rows name="program_modifiers"
                                     :rows="\App\Admin\ModifierField::rows($item?->program?->modifiers)"
                                     :legend="Text::get('admin.items.fields.program_modifiers')"
                                     :hint="Text::get('admin.items.fields.program_modifiers_hint')" />
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.hardware') }}</span></div>
            <div class="panel__bd">
                <div class="field" @error('hardware') data-invalid @enderror>
                    <label class="field__label" for="hardware">{{ Text::get('admin.items.fields.hardware') }}</label>
                    <select class="select" id="hardware" name="hardware" data-hardware-control>
                        <option value="">{{ Text::get('admin.items.fields.hardware_none') }}</option>
                        @foreach ($hardwareKinds as $kind)
                            <option value="{{ $kind->value }}" @selected($hardwareValue === $kind->value)>{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.hardware_hint') }}</span>
                    @error('hardware') <span class="field__error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        {{-- A deck's sockets. Thirteen counters, one per type, because that
             is the whole vocabulary a deck can be specified in — see
             App\Decks\SlotType. Zeroes are dropped on the way in, so the
             counters a non-deck item posts are not a request for anything. --}}
        <div class="panel" data-hardware="deck" @unless ($isDeck) hidden @endunless>
            <div class="panel__hd">
                <span class="micro">{{ Text::get('admin.items.sections.sockets') }}</span>
                <div class="spacer"></div>
                <span class="tag">{{ Text::plural('admin.items.sockets.total', $layout->total()) }}</span>
            </div>
            <div class="panel__bd stack" style="--gap:var(--s-4)">
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('admin.items.sockets.help') }}</p>

                @error('slots') <span class="field__error">{{ $message }}</span> @enderror

                <div class="field" style="max-width:min(240px,100%)" @error('onboard_storage') data-invalid @enderror>
                    <label class="field__label" for="onboard_storage">{{ Text::get('admin.items.onboard_storage.label') }}</label>
                    <input class="input num" id="onboard_storage" name="onboard_storage" type="number" min="0" step="0.1"
                           value="{{ old('onboard_storage', $item?->onboard_storage) }}"
                           placeholder="{{ config('decks.onboard_storage') }}">
                    <span class="field__hint">{{ Text::get('admin.items.onboard_storage.hint', ['fallback' => config('decks.onboard_storage')]) }}</span>
                    @error('onboard_storage') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr))">
                    @foreach ($slotTypes as $type)
                        <div class="field" @error('slots.'.$type->key()) data-invalid @enderror>
                            <label class="field__label" for="slot_{{ $type->key() }}">{{ $type->fullLabel() }}</label>
                            <input class="input num" id="slot_{{ $type->key() }}"
                                   name="slots[{{ $type->key() }}]" type="number"
                                   min="0" max="{{ $type->max() }}" value="{{ $slotCount($type) }}">
                            @if ($type->note())
                                <span class="field__hint">{{ $type->note() }}</span>
                            @endif
                            @error('slots.'.$type->key()) <span class="field__error">{{ $message }}</span> @enderror
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Where those sockets sit when someone looks at the deck. The same
             component the player's screen renders, with the drag handles on —
             an editor that draws the board differently from the screen is an
             editor that lies. --}}
        <div class="panel" data-hardware="deck" @unless ($isDeck) hidden @endunless>
            <div class="panel__hd">
                <span class="micro">{{ Text::get('admin.items.sections.board') }}</span>
                <div class="spacer"></div>
                @unless ($item?->board_layout)
                    <span class="tag">{{ Text::get('admin.items.board.automatic') }}</span>
                @endunless
            </div>
            <div class="panel__bd stack" style="--gap:var(--s-4)">
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('admin.items.board.help') }}</p>

                @error('board_layout') <span class="field__error">{{ $message }}</span> @enderror

                @if ($isDeck && $item?->isCyberdeck())
                    <x-deck-board :item="$item" editable field="#board_layout" />

                    <div class="row row--wrap" style="--gap:var(--s-3)">
                        <button type="button" class="btn btn--sm btn--ghost cut" data-board-reset="#board_layout">
                            {{ Text::get('admin.items.board.reset') }}
                        </button>
                        <span class="field__hint">{{ Text::get('admin.items.board.reset_hint') }}</span>
                    </div>

                    {{-- The board is arranged for the sockets as SAVED, not as
                         the counters above currently read: the ring that places
                         a new socket lives in PHP, and a second copy of it here
                         would be a second answer to one question. --}}
                    <p class="field__hint" style="margin:0">{{ Text::get('admin.items.board.note') }}</p>
                @else
                    <p class="field__hint" style="margin:0">{{ Text::get('admin.items.board.note') }}</p>
                @endif

                <input type="hidden" id="board_layout" name="board_layout"
                       value="{{ old('board_layout', $item?->board_layout ? json_encode($item->board_layout) : '') }}">
            </div>
        </div>

        {{-- A module's own row. Which fields apply follows from the kind, and
             every one that does not is prohibited rather than ignored — see
             App\Http\Requests\Admin\ItemRequest::moduleRules(). --}}
        <div class="panel" data-hardware="cpu npu ram nic expansion psu" @unless ($isModule) hidden @endunless>
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.sections.module') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr))">

                <div class="field" data-hardware="cpu ram expansion" @error('variant') data-invalid @enderror>
                    <label class="field__label" for="variant">{{ Text::get('admin.items.fields.socket') }}</label>
                    <select class="select" id="variant" name="variant">
                        <option value="">—</option>
                        @foreach ($hardwareKinds as $kind)
                            @foreach ($kind->variants() as $variant)
                                <option value="{{ $variant->value }}" data-hardware="{{ $kind->value }}"
                                        @selected($variantValue === $variant->value)>
                                    {{ $variant->label() }}@if ($variant->note()) — {{ $variant->note() }}@endif
                                </option>
                            @endforeach
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.socket_hint') }}</span>
                    @error('variant') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                @foreach ([
                    'qflops' => 'cpu npu',
                    'quantabits' => 'ram',
                    'bandwidth' => 'nic',
                    'watts' => 'psu',
                    'cooling' => 'thermal',
                    'storage' => 'storage',
                    'power_draw' => 'cpu npu ram nic expansion thermal storage',
                    // Every module but the radiator, which takes heat away
                    // rather than making it — chk_deck_modules_thermal.
                    'thermal_load' => 'cpu npu ram nic expansion psu storage',
                    // Every module, radiators included: a radiator in a deck
                    // hot enough will cook like anything else in it.
                    'thermal_warning' => 'cpu npu ram nic expansion psu thermal storage',
                    'thermal_limit' => 'cpu npu ram nic expansion psu thermal storage',
                ] as $field => $kinds)
                    <div class="field" data-hardware="{{ $kinds }}" @error($field) data-invalid @enderror>
                        <label class="field__label" for="{{ $field }}">{{ Text::get("admin.items.fields.{$field}") }}</label>
                        <input class="input num" id="{{ $field }}" name="{{ $field }}" type="number" min="0"
                               step="{{ in_array($field, ['qflops', 'quantabits', 'storage'], true) ? '0.1' : '1' }}"
                               value="{{ $moduleValue($field) }}">
                        <span class="field__hint">{{ Text::get("admin.items.fields.{$field}_hint") }}</span>
                        @error($field) <span class="field__error">{{ $message }}</span> @enderror
                    </div>
                @endforeach
            </div>
        </div>

        {{-- A DESIGN (CRAFTING.md): plain data with a recipe. Choosing a product is what makes the item one; blank rows are spare. --}}
        @php($design = $item?->design)
        @php($maxLines = (int) config('crafting.max_components', 8))
        @php($lines = old('design_lines', $design ? $design->components->map(fn ($c) => [
            'item_id' => $c->component_item_id, 'quantity' => $c->quantity, 'grade_min' => $c->grade_min, 'grade_max' => $c->grade_max,
            'os_slug' => $c->os_slug, 'owner_id' => $c->owner_id, 'system_type' => $c->system_type, 'ice_min' => $c->ice_min, 'ids_min' => $c->ids_min,
        ])->all() : []))
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.items.design.heading') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                <p class="field__hint" style="margin:0">{{ Text::get('admin.items.design.hint') }}</p>

                <div class="field" @error('design_product') data-invalid @enderror>
                    <label class="field__label" for="design_product">{{ Text::get('admin.items.design.product') }}</label>
                    <select class="input" id="design_product" name="design_product">
                        <option value="">{{ Text::get('admin.items.design.none') }}</option>
                        @foreach ($designProducts as $product)
                            <option value="{{ $product->id }}" @selected((string) old('design_product', $design?->product_item_id) === (string) $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                    @error('design_product') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr))">
                    <div class="field" @error('design_seconds') data-invalid @enderror>
                        <label class="field__label" for="design_seconds">{{ Text::get('admin.items.design.seconds') }}</label>
                        <input class="input num" id="design_seconds" name="design_seconds" type="number" min="1" step="1" value="{{ old('design_seconds', $design?->craft_seconds) }}">
                        <span class="field__hint">{{ Text::get('admin.items.design.seconds_hint') }}</span>
                        @error('design_seconds') <span class="field__error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" @error('design_skill_id') data-invalid @enderror>
                        <label class="field__label" for="design_skill_id">{{ Text::get('admin.items.design.skill') }}</label>
                        <select class="input" id="design_skill_id" name="design_skill_id">
                            <option value="">{{ Text::get('admin.items.design.no_skill') }}</option>
                            @foreach ($skills as $skill)
                                <option value="{{ $skill->id }}" @selected((string) old('design_skill_id', $design?->skill_id) === (string) $skill->id)>{{ $skill->title }}</option>
                            @endforeach
                        </select>
                        @error('design_skill_id') <span class="field__error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" @error('design_skill_level') data-invalid @enderror>
                        <label class="field__label" for="design_skill_level">{{ Text::get('admin.items.design.skill_level') }}</label>
                        <input class="input num" id="design_skill_level" name="design_skill_level" type="number" min="1" max="20" step="1" value="{{ old('design_skill_level', $design?->skill_level ?? 1) }}">
                        @error('design_skill_level') <span class="field__error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <label class="check">
                    <input type="hidden" name="design_single_use" value="0">
                    <input type="checkbox" name="design_single_use" value="1" @checked(old('design_single_use', $design?->single_use ?? false))>
                    <span class="check__box"></span>
                    <span>{{ Text::get('admin.items.design.single_use') }}</span>
                </label>
                <span class="field__hint">{{ Text::get('admin.items.design.single_use_hint') }}</span>
                @error('design_lines') <span class="field__error">{{ $message }}</span> @enderror

                @for ($i = 0; $i < $maxLines; $i++)
                    @php($line = $lines[$i] ?? [])
                    <div class="stack" style="--gap:var(--s-2);padding-top:var(--s-3);border-top:var(--hair) solid var(--line-0)">
                        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(160px,100%),1fr))">
                            <div class="field" @error("design_lines.$i.item_id") data-invalid @enderror>
                                <label class="field__label" for="design_lines_{{ $i }}_item_id">{{ Text::get('admin.items.design.line', ['n' => $i + 1]) }}</label>
                                <select class="input" id="design_lines_{{ $i }}_item_id" name="design_lines[{{ $i }}][item_id]">
                                    <option value="">&mdash;</option>
                                    @foreach ($designComponents as $component)
                                        <option value="{{ $component->id }}" @selected((string) ($line['item_id'] ?? '') === (string) $component->id)>{{ $component->name }}</option>
                                    @endforeach
                                </select>
                                @error("design_lines.$i.item_id") <span class="field__error">{{ $message }}</span> @enderror
                            </div>
                            <div class="field" @error("design_lines.$i.quantity") data-invalid @enderror>
                                <label class="field__label" for="design_lines_{{ $i }}_quantity">{{ Text::get('admin.items.design.quantity') }}</label>
                                <input class="input num" id="design_lines_{{ $i }}_quantity" name="design_lines[{{ $i }}][quantity]" type="number" min="1" step="1" value="{{ $line['quantity'] ?? '' }}">
                                @error("design_lines.$i.quantity") <span class="field__error">{{ $message }}</span> @enderror
                            </div>
                            @foreach (['grade_min' => [1, 9], 'grade_max' => [1, 9], 'ice_min' => [1, 20], 'ids_min' => [1, 100]] as $field => [$low, $high])
                                <div class="field" @error("design_lines.$i.$field") data-invalid @enderror>
                                    <label class="field__label" for="design_lines_{{ $i }}_{{ $field }}">{{ Text::get("admin.items.design.$field") }}</label>
                                    <input class="input num" id="design_lines_{{ $i }}_{{ $field }}" name="design_lines[{{ $i }}][{{ $field }}]" type="number" min="{{ $low }}" max="{{ $high }}" step="1" value="{{ $line[$field] ?? '' }}">
                                    @error("design_lines.$i.$field") <span class="field__error">{{ $message }}</span> @enderror
                                </div>
                            @endforeach
                            <div class="field">
                                <label class="field__label" for="design_lines_{{ $i }}_os_slug">{{ Text::get('admin.items.design.os') }}</label>
                                <select class="input" id="design_lines_{{ $i }}_os_slug" name="design_lines[{{ $i }}][os_slug]">
                                    <option value="">{{ Text::get('admin.items.design.any') }}</option>
                                    @foreach ($operatingSystems as $os)
                                        <option value="{{ $os->slug }}" @selected(($line['os_slug'] ?? '') === $os->slug)>{{ $os->title }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label class="field__label" for="design_lines_{{ $i }}_owner_id">{{ Text::get('admin.items.design.owner') }}</label>
                                <select class="input" id="design_lines_{{ $i }}_owner_id" name="design_lines[{{ $i }}][owner_id]">
                                    <option value="">{{ Text::get('admin.items.design.any') }}</option>
                                    @foreach ($owners as $owner)
                                        <option value="{{ $owner->id }}" @selected((string) ($line['owner_id'] ?? '') === (string) $owner->id)>{{ $owner->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label class="field__label" for="design_lines_{{ $i }}_system_type">{{ Text::get('admin.items.design.system_type') }}</label>
                                <select class="input" id="design_lines_{{ $i }}_system_type" name="design_lines[{{ $i }}][system_type]">
                                    <option value="">{{ Text::get('admin.items.design.any') }}</option>
                                    @foreach ($systemTypes as $type)
                                        <option value="{{ $type->value }}" @selected(($line['system_type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                @endfor
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

        // The hardware kind decides which panels and which measures apply.
        // One control, many targets, so it does not fit the pairwise wiring
        // above. Every element carrying data-hardware lists the kinds it
        // belongs to; a selection outside that list hides it.
        (function () {
            var kind = document.querySelector('[data-hardware-control]');
            if (!kind) return;

            var applies = function (el) {
                return kind.value !== '' && el.dataset.hardware.split(' ').indexOf(kind.value) !== -1;
            };

            var sync = function () {
                document.querySelectorAll('[data-hardware]').forEach(function (el) {
                    if (el.tagName === 'OPTION') return;
                    el.hidden = !applies(el);
                });

                // Socket options belong to one kind each. Hiding the ones that
                // do not apply is not enough on its own: the selection has to
                // leave with them, or a CPU keeps a QDRAM socket selected.
                var socket = document.getElementById('variant');
                if (!socket) return;

                var first = null;
                socket.querySelectorAll('option[data-hardware]').forEach(function (option) {
                    option.hidden = !applies(option);
                    if (!option.hidden && first === null) first = option.value;
                });

                var selected = socket.selectedOptions[0];
                if (selected && selected.hidden) socket.value = first === null ? '' : first;
            };

            kind.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush
