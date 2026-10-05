@use('App\Support\Text')
@props(['name', 'index', 'row' => [], 'extras' => [], 'ops' => [], 'targets' => []])

@php
    $field = fn (string $key): string => "{$name}[{$index}][{$key}]";
    $carried = $row['carried'] ?? [];
@endphp

{{--
    One modifier: how it applies, by how much, to what.

    Wraps rather than scrolling, because three selects and a number do not sit
    side by side on a phone and an admin screen is not exempt from that.
--}}
<div class="row" style="display:flex;flex-wrap:wrap;gap:var(--s-3);align-items:flex-end;margin-bottom:var(--s-3)" data-modifier-row>
    <div class="field" style="flex:1 1 min(180px,100%)">
        <label class="field__label">{{ Text::get('admin.modifiers.op') }}</label>
        <select class="select" name="{{ $field('op') }}" data-modifier-field="op">
            @foreach ($ops as $value => $label)
                <option value="{{ $value }}" @selected(($row['op'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="field" style="flex:0 1 min(120px,100%)">
        <label class="field__label">{{ Text::get('admin.modifiers.amount') }}</label>
        <input class="input num" type="number" step="any" name="{{ $field('amount') }}"
               value="{{ $row['amount'] ?? '' }}" data-modifier-field="amount">
    </div>

    <div class="field" style="flex:2 1 min(240px,100%)">
        <label class="field__label">{{ Text::get('admin.modifiers.target') }}</label>
        <select class="select" name="{{ $field('target') }}" data-modifier-field="target">
            <option value="">{{ Text::get('admin.modifiers.choose_target') }}</option>
            @foreach ($targets as $group => $options)
                <optgroup label="{{ $group }}">
                    @foreach ($options as $value => $label)
                        <option value="{{ $value }}" @selected(($row['target'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </div>

    @foreach ($extras as $extra)
        <div class="field" style="flex:0 1 min(120px,100%)">
            <label class="field__label">{{ Text::get("admin.modifiers.{$extra}") }}</label>
            <input class="input num" type="number" step="1" min="0" name="{{ $field($extra) }}"
                   value="{{ $row[$extra] ?? '' }}" data-modifier-field="{{ $extra }}">
        </div>
    @endforeach

    <button type="button" class="btn btn--sm btn--ghost cut" data-modifier-duplicate
            title="{{ Text::get('admin.modifiers.duplicate') }}" aria-label="{{ Text::get('admin.modifiers.duplicate') }}">+</button>

    <button type="button" class="btn btn--sm btn--ghost cut" data-modifier-remove>
        {{ Text::get('admin.modifiers.remove') }}
    </button>

    {{-- A condition, a priority or a source: real keys this editor does not
         take apart. They travel back exactly as they arrived rather than being
         dropped on save, which is the kind of loss nobody notices until a drug
         stops wearing off. --}}
    <input type="hidden" name="{{ $field('carried') }}" value="{{ $carried === [] ? '' : json_encode($carried) }}">

    @if ($carried !== [])
        <p class="field__hint" style="flex:1 1 100%;margin:0">
            {{ Text::get('admin.modifiers.carried', ['keys' => implode(', ', array_keys($carried))]) }}
        </p>
    @endif
</div>
