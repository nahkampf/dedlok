@props(['groups', 'selected' => null])

{{--
    <option> markup for a <select>, in optgroups.

    Four selects in the archetype editor need the identical list — a row and a
    template each, for skills and for items — so the options live here rather
    than four times over. The grouping itself is decided in the controller:
    Blade renders labels it is handed and does not know what a skillbook is.

    $groups is [group label => [['id' => int, 'label' => string], ...]].
--}}
@foreach ($groups as $group => $options)
    <optgroup label="{{ $group }}">
        @foreach ($options as $option)
            <option value="{{ $option['id'] }}" @selected((int) $selected === $option['id'])>
                {{ $option['label'] }}
            </option>
        @endforeach
    </optgroup>
@endforeach
