@use('App\Support\Text')
@props(['address'])

{{-- AN ADDRESS, EVERYWHERE ONE IS SHOWN.

     A button rather than a link, because it acts instead of navigating: it puts
     the eight characters on the clipboard. Written once so that every screen
     showing an address behaves the same way — the here-panel, the read, and the
     bookmark list have no business each deciding what an address does.

     Harmless by construction. An address carries no position, so a player who
     copies one and hands it to somebody has given away a door and not a map,
     which is the same reason a system bookmark has no gate on it. --}}
<button type="button"
        class="copy"
        data-copy="{{ $address }}"
        data-copied="{{ Text::get('cyberspace.reading.copied') }}"
        data-copy-failed="{{ Text::get('cyberspace.reading.copy_failed') }}"
        title="{{ Text::get('cyberspace.reading.copy') }}"
        {{ $attributes }}>{{ $address }}</button>
