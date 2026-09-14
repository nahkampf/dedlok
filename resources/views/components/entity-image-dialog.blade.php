@use('App\Support\Image')
@use('App\Support\Text')

@props([
    // The DOM id the picture's button points at.
    'for',
    // Either the resolved URL (which x-entity-image already has in hand), or
    // the kind and id to resolve it from — which is what a listing rendering
    // this half on its own is holding.
    'src' => null,
    'kind' => null,
    'id' => null,
    'alt',
])

@php($src = $src ?? (($kind !== null && $id !== null) ? Image::full($kind, $id) : null))

{{-- The enlarged picture, on its own.

     Written once and used from both sides: <x-entity-image> renders it inline
     when it can, and a listing whose cards get cloned renders it beside the
     listing instead — see the `dialog` prop there. No full version means no
     dialog at all, which is the signal the opener reads too. --}}
@if ($src)
    {{-- The full version has no fixed shape: it is the picture rather than
         a slot to fill, so the dialog sizes to it and caps at the viewport. --}}
    <dialog class="modal modal--image" id="{{ $for }}">
        <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" decoding="async">
        <button type="button" class="btn btn--sm btn--ghost modal--image__close" data-close
                aria-label="{{ Text::get('inventory.close') }}">✕</button>
    </dialog>
@endif
