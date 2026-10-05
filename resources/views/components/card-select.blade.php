@props(['name', 'groups', 'selected' => null, 'label' => null])

{{--
    A <select> whose options are small cards: a picture, a bold title and a line
    of plain text under it.

    The browser does the work. `appearance: base-select` (see .select--cards) lets
    an <option> hold markup, so there is no script and no ARIA to keep honest — it
    is still a real <select> that submits a real value. Elsewhere the markup falls
    back to an ordinary select reading the option's text, which is why the parts
    are <img> and <span> only: those are what an older parser drops cleanly, and
    <div> would not be.

    $groups is [group label => [['id' => int, 'label' => string,
    'image' => ?string, 'text' => ?string], ...]]. `image` and `text` are both
    optional, option by option — an option with neither is just its title.

    The leading <button><selectedcontent> is what shows when the list is closed;
    the CSS hides the picture and the text there so a row stays one line tall.
--}}
<select name="{{ $name }}" aria-label="{{ $label }}" {{ $attributes->class('select select--cards') }}>
    <button type="button"><selectedcontent></selectedcontent></button>
    <option value=""><span class="opt__title"></span></option>
    @foreach ($groups as $group => $options)
        <optgroup label="{{ $group }}">
            @foreach ($options as $option)
                <option value="{{ $option['id'] }}" @selected((int) $selected === $option['id'])>
                    @if (! empty($option['image']))
                        <img class="opt__img" src="{{ $option['image'] }}" alt="" loading="lazy" decoding="async">
                    @endif
                    <span class="opt__title">{{ $option['label'] }}</span>
                    @if (! empty($option['text']))
                        <span class="opt__text">{{ $option['text'] }}</span>
                    @endif
                </option>
            @endforeach
        </optgroup>
    @endforeach
</select>
