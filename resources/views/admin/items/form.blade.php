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

                {{-- Where in a body it goes, if it goes in one at all. The
                     column is the ONLY place a region is written down, so an
                     implant that cannot be authored here is one that has to
                     arrive by migration. --}}
                <div class="field" @error('implant_region') data-invalid @enderror>
                    <label class="field__label" for="implant_region">{{ Text::get('admin.items.fields.implant_region') }}</label>
                    <select class="select" id="implant_region" name="implant_region">
                        <option value="">{{ Text::get('admin.items.fields.implant_region_none') }}</option>
                        @foreach (\App\Implants\BodyRegion::cases() as $region)
                            <option value="{{ $region->value }}" @selected($value('implant_region') === $region->value)>
                                {{ $region->label() }}
                            </option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.items.fields.implant_region_hint') }}</span>
                    @error('implant_region') <span class="field__error">{{ $message }}</span> @enderror
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

        {{-- Drugs. The third thing an item can also be, and the only one that
             is physical: software is data and deck hardware is a component,
             while this is a thing you swallow.

             WHAT IT DOES is the modifiers panel above, like an implant's. What
             it COSTS is here — the family decides which books a dose moves,
             and the two figures are how fast it moves them. --}}
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
                                <input class="input num" id="{{ $field }}" name="{{ $field }}" type="number" min="0"
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
                        <span>{{ Text::get('admin.items.fields.background_process') }}</span>
                    </label>
                    <span class="field__hint">{{ Text::get('admin.items.fields.background_process_hint') }}</span>

                    {{-- Whether two copies may be pointed at two things at
                         once. A property of the SOFTWARE: how many a character
                         may run is the program_threads stat, and an icebreaker
                         fighting two pieces of ICE is not a thing skill should
                         be able to buy. --}}
                    <label class="check" style="margin-top:var(--s-3)">
                        <input type="hidden" name="multithreaded" value="0">
                        <input type="checkbox" name="multithreaded" value="1"
                               @checked(old('multithreaded', $item?->program?->multithreaded ?? false))>
                        <span>{{ Text::get('admin.items.fields.multithreaded') }}</span>
                    </label>
                    <span class="field__hint">{{ Text::get('admin.items.fields.multithreaded_hint') }}</span>

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
                               value="{{ $moduleValue($field) }}">
                        <span class="field__hint">{{ Text::get("admin.items.fields.{$field}_hint") }}</span>
                        @error($field) <span class="field__error">{{ $message }}</span> @enderror
                    </div>
                @endforeach
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
