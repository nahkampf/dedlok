@use('App\Models\Character')
@use('App\Support\Asset')
@use('App\Support\Text')
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>

    {{-- Every face is self-hosted and declared inside ui-kit.css. No font request
         leaves this origin, so there is nothing to preconnect to. --}}
    <link rel="stylesheet" href="{{ Asset::url('assets/css/ui-kit.css') }}">
    @stack('head')
</head>
<body>

{{-- The character behind the HUD. Named apart from the $character a view may
     already have been given, so the layout can never shadow it. An admin who
     has never played has none, and the bar simply has nothing to show. --}}
@php($hud = auth()->user()?->character)
{{-- One query, and only for a signed-in character: the topbar's process
     count and the modal behind it read the same collection. --}}
@php($processes = $hud?->runningProcesses() ?? collect())

<div class="shell @auth shell--docked @endauth">

    @auth
        {{-- The rail. Icons at rest; hovering opens it on a pointer device and
             the toggle opens it everywhere else, which is the only way in on a
             phone. Every glyph below is from the set the gallery proves renders
             in the kit's font stack — see App\Models\Item::glyph(). --}}
        <nav class="dock" aria-label="{{ Text::get('ui.nav.sections') }}">
            <button type="button" class="dock__item" data-dock-toggle aria-expanded="false">
                <span class="dock__icon" aria-hidden="true">&#9776;</span>
                <span class="dock__label">{{ Text::get('ui.nav.menu') }}</span>
            </button>

            <a class="dock__item" href="{{ route('inventory.index') }}"
               @if (request()->routeIs('inventory.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#9636;</span>
                <span class="dock__label">{{ Text::get('ui.nav.stash') }}</span>
            </a>

            {{-- Not a row id: the player thinks "my deck", and deck.index is
                 what turns that into one — or explains that there is none. --}}
            <a class="dock__item" href="{{ route('deck.index') }}"
               @if (request()->routeIs('deck.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#9108;</span>
                <span class="dock__label">{{ Text::get('ui.nav.deck') }}</span>
            </a>

            <a class="dock__item" href="{{ route('skills.index') }}"
               @if (request()->routeIs('skills.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#10022;</span>
                <span class="dock__label">{{ Text::get('ui.nav.skills') }}</span>
            </a>

            {{-- Only ever rendered for an admin. The gate is the guard; this is
                 just the door being visible to the people who have one. --}}
            @can('admin')
                <a class="dock__item" href="{{ route('admin.index') }}"
                   @if (request()->routeIs('admin.*')) aria-current="page" @endif>
                    <span class="dock__icon" aria-hidden="true">&#9881;&#65038;</span>
                    <span class="dock__label">{{ Text::get('admin.nav') }}</span>
                </a>
            @endcan

            <div class="spacer"></div>
            <div class="dock__sep"></div>

            {{-- Leaving is a POST, so it is a button. It sits in the rail
                 rather than the bar because the bar is numbers only — which is
                 what makes all three of them fit on a 320px phone. --}}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="dock__item">
                    <span class="dock__icon" aria-hidden="true">&#9211;&#65038;</span>
                    <span class="dock__label">{{ Text::get('auth.logout') }}</span>
                </button>
            </form>
        </nav>
    @endauth

    <div class="shell__body">
        <header class="topbar topbar--stuck">
            {{-- The mark stays in the bar whoever is looking. The wordmark
                 beside it is what goes when there is no room for it — see
                 .brand__name, which is hidden below 560px so the two resources
                 and the balance keep their row on a phone. --}}
            <a href="{{ route('home') }}" class="brand" style="text-decoration:none">
                <span class="brand__mark"></span>
                <span class="brand__name">{{ config('app.name') }}</span>
            </a>

            @if ($hud)
                {{-- Always on screen, and only what you need at a glance.

                     The two resources are DIALS rather than bars. A bar is the
                     better shape wherever there is room for one, and this bar
                     is where there is not: three gauges at 104px each left no
                     room for anything else, and the status bar is where status
                     lives. A ring says the same ratio in 40px and puts the
                     value inside it.

                     The maxima are derived, never stored, so they are resolved
                     per request like every other stat. --}}
                @foreach (Character::RESOURCES as $resource => $statId)
                    @php($current = (int) $hud->{$resource})
                    @php($max = $hud->maximumFor($resource))
                    @php($label = Text::get("character.abbreviations.{$statId}"))
                    {{-- Once, not once per read: every maximum is derived, so
                         each one runs the resolver. --}}
                    @php($ratio = $hud->resourceRatio($resource))

                    {{-- data-hot-low, not a class decided here: ui-kit.js owns
                         what "low" is, so the threshold is one number in one
                         place — and a dial wears the same one a meter does. --}}
                    <div class="dial" role="meter" data-hot-low
                         aria-valuenow="{{ (int) round($ratio * 100) }}" aria-valuemin="0" aria-valuemax="100"
                         style="--v:{{ $ratio }};--fill:var(--ok)"
                         title="{{ $label }} {{ Text::get('ui.hud.ratio', ['current' => $current, 'max' => $max]) }}"
                         aria-label="{{ Text::get('ui.hud.aria', [
                             'label' => $label,
                             'current' => $current,
                             'max' => $max,
                         ]) }}">
                        <span class="dial__in" aria-hidden="true">
                            <span class="dial__label">{{ $label }}</span>
                            <span class="dial__value">{{ $current }}</span>
                        </span>
                    </div>
                @endforeach

                {{-- Money has no maximum, so it has no meter of any shape — see
                     Character::RESOURCES, which is why creds is not in it. The
                     glyph replaces the word to buy the width: U+00A4 is the
                     generic currency sign and sits in Latin-1, so the font
                     subset carries it by construction rather than by
                     measurement. --}}
                <span class="chip-stat" title="{{ Text::get('creds.name') }}">
                    <span class="chip-stat__glyph" aria-hidden="true">&#164;</span>
                    <span class="num">{{ number_format($hud->creds, 0, '.', ' ') }}</span>
                    <span class="sr-only">{{ Text::get('creds.name') }}</span>
                </span>

                {{-- What is running, as a count. The number is all the bar has
                     room to say and all it needs to; the modal says the rest.
                     U+25B6 is already proved in this stack — see the rail. --}}
                <button type="button" class="chip-stat @if ($processes->isEmpty()) chip-stat--idle @endif"
                        data-open="#processes" aria-haspopup="dialog"
                        title="{{ Text::plural('programs.processes', $processes->count(), ['count' => $processes->count()]) }}">
                    <span class="chip-stat__glyph" aria-hidden="true">&#9654;</span>
                    <span class="num">{{ $processes->count() }}</span>
                    <span class="sr-only">{{ Text::plural('programs.processes', $processes->count(), ['count' => $processes->count()]) }}</span>
                </button>
            @endif

            <div class="spacer"></div>

            @guest
                <a class="btn btn--sm" href="{{ route('login') }}">{{ Text::get('auth.login') }}</a>
            @endguest
        </header>

        @if (session('status'))
            <div style="max-width:1100px;margin:var(--s-4) auto 0;padding-inline:var(--s-4)">
                <div class="callout callout--ok">{{ session('status') }}</div>
            </div>
        @endif

        @if (session('error'))
            <div style="max-width:1100px;margin:var(--s-4) auto 0;padding-inline:var(--s-4)">
                <div class="callout callout--crit">{{ session('error') }}</div>
            </div>
        @endif

        <main style="max-width:1100px;margin:0 auto;padding:var(--s-5) var(--s-4) var(--s-8)">
            @yield('content')
        </main>
    </div>
</div>

@if ($hud)
    {{-- What is running, behind the count in the bar.

         Rendered out here rather than inside the topbar: a <dialog> goes to the
         top layer, and nesting one in a sticky, backdrop-filtered element is
         asking for a stacking-context argument nobody wins.

         A READOUT, not a control surface. Stopping a program happens on the
         deck screen, beside the compute and memory it is spending and beside
         everything else that could be started instead — which is the context
         the decision actually needs. This is the glance: it is on every page,
         and it is the only place a background process is visible at all once
         you have walked away from it. So it counts, and it points. --}}
    <dialog id="processes" class="modal">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('programs.running') }}</span>
            <button type="button" class="btn btn--sm btn--ghost" data-close>{{ Text::get('ui.action.cancel') }}</button>
        </div>

        <div class="panel__bd">
            @forelse ($processes as $process)
                @php($program = $process->item?->program)
                <div class="row" style="justify-content:space-between;gap:var(--s-3);align-items:flex-start;padding:var(--s-3) 0;border-top:var(--hair) solid var(--line-0)">
                    <div style="min-width:0">
                        <div style="color:var(--ink-0)">{{ $process->item?->displayName() }}</div>
                        <div class="micro" style="color:var(--ink-2)">
                            {{ $program?->kind->label() }}
                            @if ($process->started_at)
                                &middot; {{ $process->started_at->diffForHumans() }}
                            @endif
                        </div>
                    </div>
                    @if ($program)
                        <div class="micro num" style="text-align:right;color:var(--ink-2);white-space:nowrap">
                            <div>{{ Text::get('programs.costs.compute', ['value' => $program->qflops_cost]) }}</div>
                            <div>{{ Text::get('programs.costs.memory', ['value' => $program->memory_cost]) }}</div>
                        </div>
                    @endif
                </div>
            @empty
                <p class="muted" style="font-size:var(--fs-sm)">{{ Text::get('programs.running_none') }}</p>
            @endforelse
        </div>

        {{-- Where the doing happens. --}}
        <div class="panel__ft">
            <a class="btn btn--sm cut" href="{{ route('deck.index') }}">{{ Text::get('programs.open_deck') }}</a>
        </div>
    </dialog>
@endif

<script src="{{ Asset::url('assets/js/ui-kit.js') }}" defer></script>
@stack('scripts')
</body>
</html>
