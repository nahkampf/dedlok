@use('App\Support\Text')

@props([
    // Which tab is the page being drawn: character, skills, implants or
    // achievements. It names a ROUTE rather than a panel — see below.
    'current',
])

{{--
    The character window's tab strip.

    A NAV OF LINKS AND NOT role="tab". Each tab is its own URL with its own
    controller, so the strip navigates rather than switching a panel — and
    aria-current is what says which page you are on. Promising a tablist and
    then reloading the page is a lie a screen reader acts on.

    They stayed separate routes because this is the screen a player opens most:
    one request draws one panel, where a client-side window would run four
    screens' worth of queries to show one of them.
--}}
<nav class="tabs" aria-label="{{ Text::get('character.tabs.label') }}" style="margin-top:var(--s-4)">
    @foreach ([
        'character' => 'character.index',
        'skills' => 'skills.index',
        'implants' => 'implants.index',
        'achievements' => 'achievements.index',
    ] as $tab => $route)
        <a class="tab" href="{{ route($route) }}"
           @if ($current === $tab) aria-current="page" @endif>
            {{ Text::get("character.tabs.{$tab}") }}
        </a>
    @endforeach
</nav>
