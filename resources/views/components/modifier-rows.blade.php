@use('App\Admin\ModifierField')
@use('App\Support\Text')
@props([
    'name',
    'rows' => [],
    'extras' => [],
    'legend' => null,
    'hint' => null,
])

@php
    $targets = ModifierField::targets();
    $ops = ModifierField::ops();
    // old() wins, so a refused save comes back with what was typed rather than
    // with what is stored — the rule every other field on these forms follows.
    $rows = old($name) !== null ? ModifierField::rows(ModifierField::normalise(old($name), $extras), $extras) : $rows;
@endphp

{{--
    A list of modifiers, edited as rows.

    The same markup wherever one is stored — an item, the software on it, a
    skill, and a perk when it gets an editor — because it is the same JSON in
    four tables. What differs per screen is the field name and the extra keys
    that table carries, and both are props.

    Rows are added by cloning the template below, which is markup rather than a
    string in JavaScript: a row is a dozen elements with labels and a whole
    picker in it, and building that by concatenation is how a form ends up with
    two versions of itself that drift.

    The indexes are rewritten on every add and remove, so a removed row leaves
    no hole for PHP to read as a gap.
--}}
<fieldset class="panel" style="margin-top:var(--s-5)" data-modifiers @error($name) data-invalid @enderror>
    <legend class="panel__hd">
        <span class="micro">{{ $legend ?? Text::get('admin.modifiers.legend') }}</span>
    </legend>

    <div class="panel__bd">
        <p class="field__hint" style="margin-top:0">{{ $hint ?? Text::get('admin.modifiers.hint') }}</p>

        <div data-modifier-rows>
            @foreach ($rows as $index => $row)
                <x-modifier-row :name="$name" :index="$index" :row="$row" :extras="$extras" :ops="$ops" :targets="$targets" />
            @endforeach
        </div>

        <p class="field__hint" data-modifier-empty @if ($rows !== []) hidden @endif>
            {{ Text::get('admin.modifiers.empty') }}
        </p>

        <button type="button" class="btn btn--sm cut" style="margin-top:var(--s-3)" data-modifier-add>
            {{ Text::get('admin.modifiers.add') }}
        </button>

        @error($name) <span class="field__error">{{ $message }}</span> @enderror

        {{-- The blueprint. Kept out of the form with a <template>, so its
             inputs are never posted and never focusable. --}}
        <template data-modifier-template>
            <x-modifier-row :name="$name" index="__index__" :row="[]" :extras="$extras" :ops="$ops" :targets="$targets" />
        </template>
    </div>
</fieldset>
