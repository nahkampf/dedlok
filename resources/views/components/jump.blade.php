@use('App\Support\Text')
@props(['address'])

{{-- A JUMP BUTTON BESIDE AN ADDRESS. Drawn only while a Jumper runs on the equipped deck and the body is on the grid
     (App\Cyberspace\Jumper); the server asks the same question, so a hand-made post is refused. A landed jump opens the map. --}}
@if (\App\Cyberspace\Jumper::available(auth()->user()?->character))
    <form method="POST" action="{{ route('cyberspace.jump') }}" style="display:inline">
        @csrf
        <input type="hidden" name="address" value="{{ $address }}" {{ $attributes }}>
        <input type="hidden" name="via" value="jumper">
        <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('missions.jump') }}</button>
    </form>
@endif
