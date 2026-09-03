@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.archetypes.editing', ['archetype' => $archetype->slug]))

@php
    $value = fn (string $field, $fallback = null) => old($field, $archetype->{$field} ?? $fallback);

    // Grants come back from old() on a rejected submission, already normalised
    // by the request; otherwise from the rows themselves, sorted by the name
    // they are shown under rather than by insertion.
    $skillRows = old('skills', $grantedSkills->map(fn ($grant) => [
        'skill_id' => $grant->skill_id,
        'level' => $grant->level,
    ])->all());

    $itemRows = old('items', $grantedItems->map(fn ($grant) => [
        'item_id' => $grant->item_id,
        'quantity' => $grant->quantity,
    ])->all());
@endphp

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li><a href="{{ route('admin.archetypes.index') }}">{{ Text::get('admin.archetypes.title') }}</a></li>
        <li aria-current="page">{{ $archetype->slug }}</li>
    </ol>

    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.archetypes.editing', ['archetype' => $archetype->slug]) }}</h1>

    <x-auth-errors />

    <form method="POST" action="{{ route('admin.archetypes.update', $archetype) }}"
          class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @csrf
        @method('PUT')

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.archetypes.sections.identity') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">

                <div class="field">
                    <label class="field__label" for="slug">{{ Text::get('admin.archetypes.fields.slug') }}</label>
                    <input class="input input--mono" id="slug" value="{{ $archetype->slug }}" disabled>
                    <span class="field__hint">{{ Text::get('admin.archetypes.fields.slug_locked') }}</span>
                </div>

                <div class="field" @error('name') data-invalid @enderror>
                    <label class="field__label" for="name">{{ Text::get('admin.archetypes.fields.name') }}</label>
                    <input class="input" id="name" name="name" value="{{ $value('name') }}" required>
                    <span class="field__hint">{{ Text::get('admin.archetypes.fields.name_hint') }}</span>
                    @error('name') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('role') data-invalid @enderror>
                    <label class="field__label" for="role">{{ Text::get('admin.archetypes.fields.role') }}</label>
                    <input class="input" id="role" name="role" value="{{ $value('role') }}" maxlength="60" required>
                    <span class="field__hint">{{ Text::get('admin.archetypes.fields.role_hint') }}</span>
                    @error('role') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" style="grid-column:1/-1" @error('description') data-invalid @enderror>
                    <label class="field__label" for="description">{{ Text::get('admin.archetypes.fields.description') }}</label>
                    <textarea class="textarea" id="description" name="description" required>{{ $value('description') }}</textarea>
                    <span class="field__hint">{{ Text::get('admin.archetypes.fields.description_hint') }}</span>
                    @error('description') <span class="field__error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.archetypes.sections.loadout') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr))">

                <div class="field" @error('starting_creds') data-invalid @enderror>
                    <label class="field__label" for="starting_creds">{{ Text::get('admin.archetypes.fields.starting_creds') }}</label>
                    <input class="input num" id="starting_creds" name="starting_creds" type="number" min="0"
                           value="{{ $value('starting_creds', 0) }}" required>
                    <span class="field__hint">{{ Text::get('admin.archetypes.fields.starting_creds_hint') }}</span>
                    @error('starting_creds') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <span class="field__label">{{ Text::get('admin.archetypes.fields.playable') }}</span>
                    <label class="switch">
                        <input type="checkbox" name="playable" value="1" @checked($value('playable', false))>
                        <span class="switch__track"></span>
                        <span>{{ Text::get('admin.archetypes.fields.playable') }}</span>
                    </label>
                    <span class="field__hint">{{ Text::get('admin.archetypes.fields.playable_hint') }}</span>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.archetypes.sections.skills') }}</span></div>
            <div class="panel__bd stack">
                <p class="muted" style="font-size:var(--fs-sm)">{{ Text::get('admin.archetypes.skills.help') }}</p>

                @error('skills')
                    <div class="callout callout--crit">{{ $message }}</div>
                @enderror

                <div class="stack" data-rows="skills">
                    @foreach ($skillRows as $index => $row)
                        <div class="row row--wrap" data-row style="--gap:var(--s-3)">
                            <div class="field" style="flex:1 1 min(220px, 100%)">
                                <label class="field__label">{{ Text::get('admin.archetypes.skills.skill') }}</label>
                                <select class="select" name="skills[{{ $index }}][skill_id]">
                                    <x-grouped-options :groups="$skills" :selected="$row['skill_id'] ?? null" />
                                </select>
                            </div>

                            <div class="field" style="flex:0 1 min(180px, 100%)">
                                <label class="field__label">{{ Text::get('admin.archetypes.skills.level') }}</label>
                                <select class="select" name="skills[{{ $index }}][level]">
                                    @for ($level = 0; $level <= $maxLevel; $level++)
                                        <option value="{{ $level }}" @selected((int) ($row['level'] ?? 0) === $level)>
                                            {{ $level === 0 ? Text::get('admin.archetypes.skills.level_zero') : $level }}
                                        </option>
                                    @endfor
                                </select>
                            </div>

                            <div class="row" style="align-items:flex-end;padding-bottom:2px">
                                <button type="button" class="btn btn--sm btn--ghost" data-remove-row>
                                    {{ Text::get('admin.actions.remove') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($skills === [])
                    <p class="micro">{{ Text::get('admin.archetypes.skills.empty_catalogue') }}</p>
                @else
                    @if ($skillRows === [])
                        <p class="micro">{{ Text::get('admin.archetypes.skills.none') }}</p>
                    @endif
                    <div>
                        <button type="button" class="btn btn--sm" data-add-row="skills">
                            {{ Text::get('admin.actions.add_skill') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.archetypes.sections.items') }}</span></div>
            <div class="panel__bd stack">
                <p class="muted" style="font-size:var(--fs-sm)">{{ Text::get('admin.archetypes.items.help') }}</p>

                @error('items')
                    <div class="callout callout--crit">{{ $message }}</div>
                @enderror

                <div class="stack" data-rows="items">
                    @foreach ($itemRows as $index => $row)
                        <div class="row row--wrap" data-row style="--gap:var(--s-3)">
                            <div class="field" style="flex:1 1 min(220px, 100%)">
                                <label class="field__label">{{ Text::get('admin.archetypes.items.item') }}</label>
                                <select class="select" name="items[{{ $index }}][item_id]">
                                    <x-grouped-options :groups="$items" :selected="$row['item_id'] ?? null" />
                                </select>
                            </div>

                            <div class="field" style="flex:0 1 min(140px, 100%)">
                                <label class="field__label">{{ Text::get('admin.archetypes.items.quantity') }}</label>
                                <input class="input num" type="number" min="1" max="999"
                                       name="items[{{ $index }}][quantity]" value="{{ (int) ($row['quantity'] ?? 1) }}">
                            </div>

                            <div class="row" style="align-items:flex-end;padding-bottom:2px">
                                <button type="button" class="btn btn--sm btn--ghost" data-remove-row>
                                    {{ Text::get('admin.actions.remove') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($items === [])
                    <p class="micro">{{ Text::get('admin.archetypes.items.empty_catalogue') }}</p>
                @else
                    @if ($itemRows === [])
                        <p class="micro">{{ Text::get('admin.archetypes.items.none') }}</p>
                    @endif
                    <div>
                        <button type="button" class="btn btn--sm" data-add-row="items">
                            {{ Text::get('admin.actions.add_item') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>

        <div class="row row--wrap">
            <button type="submit" class="btn btn--primary cut">{{ Text::get('admin.actions.save') }}</button>
            <a class="btn btn--ghost" href="{{ route('admin.archetypes.index') }}">{{ Text::get('admin.actions.cancel') }}</a>
        </div>
    </form>

    {{-- Perks are read-only here: a live perk is a list of modifiers with a
         target shape of its own, which is an editor in its own right. --}}
    <div class="panel" style="margin-top:var(--s-6)">
        <div class="panel__hd"><span class="micro">{{ Text::get('admin.archetypes.sections.perks') }}</span></div>
        <div class="panel__bd stack">
            <p class="muted" style="font-size:var(--fs-sm)">{{ Text::get('admin.archetypes.perks.help') }}</p>

            @forelse ($archetype->perks as $perk)
                <div class="row row--wrap" style="--gap:var(--s-3)">
                    <span>{{ $perk->displayLabel() }}</span>
                    <div class="spacer"></div>
                    <span class="tag">
                        {{ $perk->isLive()
                            ? Text::get('admin.archetypes.perks.live')
                            : Text::get('admin.archetypes.perks.display_only') }}
                    </span>
                </div>
            @empty
                <p class="micro">{{ Text::get('admin.archetypes.perks.none') }}</p>
            @endforelse
        </div>
    </div>
@endsection

@push('scripts')
    {{-- Two repeaters, one script. Rows are indexed only so the browser sends
         an array; the request reindexes and drops the blanks, so a gap left by
         a removal is harmless. --}}
    <template id="row-skills">
        <div class="row row--wrap" data-row style="--gap:var(--s-3)">
            <div class="field" style="flex:1 1 min(220px, 100%)">
                <label class="field__label">{{ Text::get('admin.archetypes.skills.skill') }}</label>
                <select class="select" name="skills[__INDEX__][skill_id]">
                    <x-grouped-options :groups="$skills" />
                </select>
            </div>
            <div class="field" style="flex:0 1 min(180px, 100%)">
                <label class="field__label">{{ Text::get('admin.archetypes.skills.level') }}</label>
                <select class="select" name="skills[__INDEX__][level]">
                    @for ($level = 0; $level <= $maxLevel; $level++)
                        <option value="{{ $level }}">
                            {{ $level === 0 ? Text::get('admin.archetypes.skills.level_zero') : $level }}
                        </option>
                    @endfor
                </select>
            </div>
            <div class="row" style="align-items:flex-end;padding-bottom:2px">
                <button type="button" class="btn btn--sm btn--ghost" data-remove-row>
                    {{ Text::get('admin.actions.remove') }}
                </button>
            </div>
        </div>
    </template>

    <template id="row-items">
        <div class="row row--wrap" data-row style="--gap:var(--s-3)">
            <div class="field" style="flex:1 1 min(220px, 100%)">
                <label class="field__label">{{ Text::get('admin.archetypes.items.item') }}</label>
                <select class="select" name="items[__INDEX__][item_id]">
                    <x-grouped-options :groups="$items" />
                </select>
            </div>
            <div class="field" style="flex:0 1 min(140px, 100%)">
                <label class="field__label">{{ Text::get('admin.archetypes.items.quantity') }}</label>
                <input class="input num" type="number" min="1" max="999" name="items[__INDEX__][quantity]" value="1">
            </div>
            <div class="row" style="align-items:flex-end;padding-bottom:2px">
                <button type="button" class="btn btn--sm btn--ghost" data-remove-row>
                    {{ Text::get('admin.actions.remove') }}
                </button>
            </div>
        </div>
    </template>

    <script>
        (function () {
            document.querySelectorAll('[data-add-row]').forEach(function (add) {
                var name = add.getAttribute('data-add-row');
                var list = document.querySelector('[data-rows="' + name + '"]');
                var template = document.getElementById('row-' + name);
                if (!list || !template) return;

                var next = list.querySelectorAll('[data-row]').length;

                add.addEventListener('click', function () {
                    list.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(next++)));
                });

                list.addEventListener('click', function (event) {
                    var button = event.target.closest('[data-remove-row]');
                    if (button) button.closest('[data-row]').remove();
                });
            });
        })();
    </script>
@endpush
