@use('App\Support\Image')
@use('App\Support\Text')

@props([
    // Which folder under public/images, and which file in it. See App\Support\Image.
    // The kind may be a path — `items/cpu` — one subdirectory per type of thing.
    'kind',
    'id',
    // What a screen reader is told the picture is OF. Never decorative: these
    // pictures are the thing, not a flourish beside it.
    'alt',
    // The id of the dialog this picture opens, when that dialog is rendered
    // somewhere else — by x-entity-image-dialog, beside whatever lists the
    // thing. Needed wherever the picture sits in a card ui-kit.js CLONES: a
    // <dialog> cloned along with the card is a second copy of itself in the
    // top layer, which is the same reason x-item-destroy is not in the card
    // either. Null means the dialog is rendered here, which is the ordinary case.
    'dialog' => null,
    // A 128px SQUARE rather than a 16:9 slot, for a picture that is a badge —
    // see .entity-image--sq. The intrinsic size goes with it, because the
    // attributes are what stop the page jumping before the file has loaded.
    'square' => false,
    // A 3:4 PORTRAIT at 300px, for a picture that is of a PERSON rather than
    // of a thing — see .entity-image--portrait. A face in a 16:9 slot is a
    // face with its head cropped.
    'portrait' => false,
])

@php($shape = $square ? ' entity-image--sq' : ($portrait ? ' entity-image--portrait' : ''))
@php($w = $square ? 128 : ($portrait ? 300 : 640))
@php($h = $square ? 128 : ($portrait ? 400 : 360))

@php($card = Image::card($kind, $id))
@php($full = Image::full($kind, $id))
{{-- A kind is a path and an id may be anything, so neither is a DOM id until
     the slashes are gone. --}}
@php($target = $dialog ?? 'image-'.str_replace('/', '-', $kind).'-'.$id)

{{-- Nothing at all when there is no picture and no default. A screen that
     renders a missing file into an <img> shows a torn page, which is worse
     than showing nothing. --}}
@if ($card)
    @if ($full)
        {{-- A button, because it opens something. data-open is the kit's, and
             it now stops the default action — this sits inside a <label> on the
             creation screen, and viewing a picture must not also pick the
             archetype it belongs to. --}}
        <button type="button" class="entity-image entity-image--open{{ $shape }}" data-open="#{{ $target }}"
                aria-label="{{ Text::get('images.enlarge', ['subject' => $alt]) }}">
            <img src="{{ $card }}" alt="{{ $alt }}" loading="lazy" decoding="async" width="{{ $w }}" height="{{ $h }}">
        </button>

        @if ($dialog === null)
            <x-entity-image-dialog :for="$target" :src="$full" :alt="$alt" />
        @endif
    @else
        <span class="entity-image{{ $shape }}">
            <img src="{{ $card }}" alt="{{ $alt }}" loading="lazy" decoding="async" width="{{ $w }}" height="{{ $h }}">
        </span>
    @endif
@endif
