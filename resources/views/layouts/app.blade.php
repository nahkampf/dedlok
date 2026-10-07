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
    @include('layouts.social-meta')

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
{{-- What the rail's badge counts. One indexed count for a signed-in user, and
     nothing for a guest: the inbox belongs to the ACCOUNT, so this asks the
     user rather than the character — the bar has a character to draw and this
     does not need one. --}}
@php($unread = auth()->check() ? (new \App\Notifications\Notifier)->unreadFor(auth()->user()) : 0)
@php($latestMessage = auth()->check() ? (new \App\Notifications\Notifier)->latestIdFor(auth()->user()) : 0)
{{-- One query, and only for a signed-in character: the topbar's process
     count and the modal behind it read the same collection. --}}
@php($processes = $hud?->runningProcesses() ?? collect())
{{-- Which of those processes is reading a machine, keyed by the process row.
     One query for the lot rather than one per process: a scan points at the
     instance doing it (`system_scans.reading_process_id`), so the answer is a
     pluck rather than a join, and a deck full of analysers still costs one. --}}
@php($reading = $hud === null || $processes->isEmpty() ? collect() : \App\Models\SystemScan::query()
    ->where('character_id', $hud->id)
    ->whereIn('reading_process_id', $processes->modelKeys())
    ->live()
    ->pluck('address', 'reading_process_id'))

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

            {{-- Who you are, rather than what you are carrying. Skills,
                 implants and achievements are tabs of this window now, so one
                 rail item stands where four did — and it is current for any of
                 the four, because they are one screen to a player.

                 A chess pawn: a figure, and from the same Miscellaneous
                 Symbols block every other glyph in this rail comes from, with
                 U+FE0E so it is drawn as text rather than handed to an emoji
                 font. --}}
            <a class="dock__item" href="{{ route('character.index') }}"
               @if (request()->routeIs('character.index', 'skills.*', 'implants.*', 'equipment.*', 'standing.*', 'achievements.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#9823;&#65038;</span>
                <span class="dock__label">{{ Text::get('ui.nav.character') }}</span>
            </a>

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


            {{-- Cyberspace. Always here, jacked in or not: the way IN is on the
                 map, so a rail item that appeared only once you were already
                 inside would be one nobody could find. Same rule as the deck
                 link. --}}
            {{-- The City. U+25C8, a glyph already proved to render (the Work
                 category's), because the rail may only draw from that set. --}}
            <a class="dock__item" href="{{ route('city.index') }}"
               @if (request()->routeIs('city.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#9672;</span>
                <span class="dock__label">{{ Text::get('ui.nav.city') }}</span>
            </a>

            <a class="dock__item" href="{{ route('cyberspace.index') }}"
               @if (request()->routeIs('cyberspace.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#9638;</span>
                <span class="dock__label">{{ Text::get('ui.nav.cyberspace') }}</span>
            </a>


            {{-- Tasks & Missions: the jobs you have taken (and, later, the journal and quests). U+25C7, the hollow diamond,
                 a glyph already proved to render (the admin's empty states and the player-message category use it). --}}
            <a class="dock__item" href="{{ route('tasks.index') }}"
               @if (request()->routeIs('tasks.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#9671;</span>
                <span class="dock__label">{{ Text::get('ui.nav.tasks') }}</span>
            </a>

            {{-- Somewhere you go, so it is in the rail rather than on a card
                 somewhere. U+2696 is the scales, from the same Miscellaneous
                 Symbols block as the star, the wheel and the cog above, with
                 U+FE0E so it is drawn as text rather than handed to an emoji
                 font. --}}
            <a class="dock__item" href="{{ route('market.index') }}"
               @if (request()->routeIs('market.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#9878;&#65038;</span>
                <span class="dock__label">{{ Text::get('ui.nav.market') }}</span>
            </a>

            {{-- The wallet. The currency sign is the glyph the topbar's money chip already draws. --}}
            <a class="dock__item" href="{{ route('wallet.index') }}"
               @if (request()->routeIs('wallet.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#164;</span>
                <span class="dock__label">{{ Text::get('ui.nav.wallet') }}</span>
            </a>

            {{-- The inbox, beside the other page that belongs to the account
                 rather than the character. An envelope from the same
                 Miscellaneous Symbols block as the star above and the cog
                 below, with U+FE0E on it so it is drawn as text rather than
                 handed to an emoji font — the admin cog's own trick.

                 THE COUNT IS ONLY THERE WHEN THERE IS ONE. An ember pip that
                 was permanent furniture would spend the accent on nothing. --}}
            {{-- `data-inbox` is what ui-kit.js polls with: where to ask, the newest message this page knows of, and
                 the sound for a new one. --}}
            <a class="dock__item" href="{{ route('notifications.index') }}"
               data-inbox="{{ route('notifications.unread') }}" data-inbox-latest="{{ $latestMessage }}"
               data-inbox-sound="{{ Asset::url('assets/sound/message.wav') }}"
               @if (request()->routeIs('notifications.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#9993;&#65038;</span>
                @if ($unread > 0)
                    <span class="dock__count">{{ $unread > 99 ? '99+' : $unread }}</span>
                @endif
                <span class="dock__label">{{ Text::get('ui.nav.messages') }}</span>
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
                     per request like every other stat — from ONE sheet, held
                     for the loop: every page draws this bar, and a sheet per
                     read was four sheets a page. --}}
                @php($hudSheet = $hud->sheet())
                @foreach (Character::RESOURCES as $resource => $statId)
                    @php($current = (int) $hud->{$resource})
                    @php($max = $hud->maximumFor($resource, $hudSheet))
                    @php($label = Text::get("character.abbreviations.{$statId}"))
                    @php($ratio = $hud->resourceRatio($resource, $hudSheet))

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
                    {{-- data-creds so a purchase that does not reload the page
                         can say what is left; the separator is this chip's own. --}}
                    <span class="num" data-creds data-creds-sep=" ">{{ number_format($hud->creds, 0, '.', ' ') }}</span>
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

        {{-- Where flashes land: the session's after a redirect, and UI.flash()'s
             after an action that did not reload the page (buying on the
             market). Always present, because the second kind has nowhere else
             to go. Each one glitches in, holds and leaves by itself. --}}
        <div class="stack" data-flash-host role="status"
             style="--gap:var(--s-2);max-width:1100px;margin:var(--s-4) auto 0;padding-inline:var(--s-4)">
            @if (session('status'))
                <div class="callout callout--ok callout--flash">{{ session('status') }}</div>
            @endif

            @if (session('error'))
                <div class="callout callout--crit callout--flash">{{ session('error') }}</div>
            @endif
        </div>

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
                <div class="row row--wrap" style="justify-content:space-between;gap:var(--s-3);align-items:flex-start;padding:var(--s-3) 0;border-top:var(--hair) solid var(--line-0)">
                    <div style="min-width:0">
                        <div style="color:var(--ink-0)">{{ $process->item?->displayName() }}</div>
                        <div class="micro" style="color:var(--ink-2)">
                            {{-- WHAT IT IS DOING BEATS WHAT IT IS. An analyser
                                 pointed at a machine is a running scan, and
                                 that is the thing a player walked away from
                                 and wants back — so the row says so and opens
                                 the read, which is why that dialog lives in
                                 this layout and not on the map. --}}
                            @if ($reading->has($process->getKey()))
                                {{ Text::get('cyberspace.reading.running_label') }}
                            @else
                                {{ $program?->kind->label() }}
                            @endif
                            @if ($process->started_at)
                                &middot; {{ $process->started_at->diffForHumans() }}
                            @endif
                        </div>

                        @if ($reading->has($process->getKey()))
                            @php($address = $reading->get($process->getKey()))
                            <div class="row row--wrap" style="--gap:var(--s-2);margin-top:var(--s-2)">
                                <button type="button" class="btn btn--sm btn--ghost cut"
                                        data-reading-open="{{ $address }}">
                                    {{ Text::get('cyberspace.reading.open') }}
                                </button>
                                <form method="POST" action="{{ route('cyberspace.abandon') }}">
                                    @csrf @method('DELETE')
                                    <input type="hidden" name="address" value="{{ $address }}">
                                    <button type="submit" class="btn btn--sm btn--ghost cut">
                                        {{ Text::get('cyberspace.reading.abandon') }}
                                    </button>
                                </form>
                            </div>
                        @endif
                    </div>
                    <div class="stack" style="--gap:var(--s-2);align-items:flex-end;flex:none">
                        @if ($program)
                            <div class="micro num" style="text-align:right;color:var(--ink-2);white-space:nowrap">
                                <div>{{ Text::get('programs.costs.compute', ['value' => $program->qflops_cost]) }}</div>
                                <div>{{ Text::get('programs.costs.memory', ['value' => $program->memory_cost]) }}</div>
                            </div>
                        @endif

                        {{-- Stopping destroys nothing: the program goes back to
                             storage, merging into the pile it came out of. Same
                             route the deck screen's Stop button posts to — one
                             writer, `ProgramRunner::stop()`, whichever screen
                             asks. --}}
                        <form method="POST" action="{{ route('inventory.run', $process) }}">
                            @csrf
                            <button type="submit" class="btn btn--sm btn--ghost cut">
                                {{ Text::get('programs.action.stop') }}
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="muted" style="font-size:var(--fs-sm)">{{ Text::get('programs.running_none') }}</p>
            @endforelse

            {{-- What the deck could start, fetched when this opens (`ui-kit.js`): working it out costs a
                 look at the deck's totals, which is not worth doing on every page. --}}
            <div class="micro" style="margin-top:var(--s-4);color:var(--ink-2)">{{ Text::get('programs.startable') }}</div>
            <div data-startable="{{ route('programs.startable') }}">
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('programs.startable_loading') }}</p>
            </div>
        </div>

        {{-- Where the doing happens. --}}
        <div class="panel__ft">
            <a class="btn btn--sm cut" href="{{ route('deck.index') }}">{{ Text::get('programs.open_deck') }}</a>
        </div>
    </dialog>

    {{-- THE READ, AS A SCREEN YOU WATCH.

         IN THE LAYOUT RATHER THAN ON THE MAP, and for the same reason the running
         processes are: a read is a background process, it outlives its owner
         walking away from the machine, and once you have left the map this is the
         only place it is visible at all. It depends on nothing the map knows —
         every value in it arrives from the polling endpoint — so being here costs
         one dialog on every page and buys the screen being reachable from the bar.
         A pass takes minutes, so what a player wants while one runs is: what am I
         pointed at, with what, how far in, and what has it told me so far. It
         polls `cyberspace.reading`, which settles a matured pass on the way past —
         so the answers fill in the moment a pass finishes rather than at the next
         reload. --}}
    <dialog class="modal" id="reading" data-reading
            data-reading-url="{{ route('cyberspace.reading') }}"
            data-reading-pass="{{ Text::get('cyberspace.reading.pass_of', ['pass' => ':pass']) }}"
            data-reading-unread="{{ Text::get('cyberspace.reading.unread') }}"
            data-reading-complete="{{ Text::get('cyberspace.reading.complete') }}"
            data-reading-filename="{{ Text::get('cyberspace.reading.filename') }}"
            data-reading-idle="{{ Text::get('cyberspace.reading.idle') }}"
            data-reading-gone="{{ Text::get('cyberspace.reading.no_file') }}"
            data-reading-os-known="{{ Text::get('cyberspace.reading.os_known') }}"
            data-reading-os-unknown="{{ Text::get('cyberspace.reading.os_unknown') }}"
            @if (session('reading')) data-reading-open="{{ session('reading') }}" @endif>
        <div class="panel__hd">
            <span class="micro">{{ Text::get('cyberspace.reading.title') }}</span>
            <div class="spacer"></div>
            <span class="spinner" data-reading-spinner hidden aria-hidden="true"></span>
        </div>

        <div class="panel__bd">
            <div class="stack" style="--gap:var(--s-3)">
                <div>
                    <span class="micro">{{ Text::get('cyberspace.reading.target') }}</span>
                    <p style="margin:0"><strong data-reading-name>—</strong></p>
                    {{-- WHERE, THEN WHAT. The glyph is the old symbol for the
                         globe and it marks the coordinates as a PLACE — the one
                         line on this screen that is not a property of the machine.
                         From the geometric set the kit has proved, like every
                         other glyph in the game. --}}
                    <p class="micro" style="margin:0;color:var(--ink-2)">
                        <span aria-hidden="true">&#x2295;</span>
                        <span data-reading-position>—</span>
                        ·
                        <x-address address="—" data-reading-address />
                    </p>
                </div>

                <div>
                    <span class="micro">{{ Text::get('cyberspace.reading.instrument') }}</span>
                    <p style="margin:0" data-reading-instrument>—</p>
                </div>

                {{-- How far in. The bar is the kit's own, driven by --v, and it is
                     the same fraction the cell on the map is drawing. --}}
                <div>
                    <p class="micro" style="margin:0 0 var(--s-1)">
                        <span data-reading-pass>—</span>
                        <span data-reading-ready style="color:var(--ink-2)"></span>
                    </p>
                    <div class="progress"><div class="progress__bar" data-reading-bar style="--v:0"></div></div>
                    <p class="micro" style="margin:var(--s-1) 0 0;color:var(--ink-2)" data-reading-note></p>
                </div>

                {{-- Everything a scan asks, including the four nothing can answer
                     yet: a screen that grew rows as features landed would be a
                     screen nobody learns. --}}
                {{-- The kit's own stat rows, which is what these are: a label on
                     the left and what the machine gave up on the right. --}}
                <div class="card__stats" style="padding:0;border:0" data-reading-facts>
                    @foreach ((new \App\Models\SystemScan)->facts() as $fact)
                        <div class="card__stat" data-fact="{{ $fact['key'] }}">
                            <span>{{ $fact['label'] }}</span>
                            {{-- BOTH BLANKS WEAR THE SAME BOX. "Nobody can read
                                 this yet" and "you have not read it yet" are
                                 different facts and neither is an answer, so the
                                 screen does not ask the player to tell two kinds
                                 of nothing apart. What separates them is that one
                                 can change: a discoverable row is the one that
                                 turns ember when the instrument comes back. --}}
                            @if ($fact['discoverable'])
                                <span class="tag" data-fact-value>{{ Text::get('cyberspace.reading.unread') }}</span>
                            @else
                                <span class="tag">{{ Text::get('cyberspace.reading.not_yet') }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- TWO WAYS OUT WHILE IT RUNS, AND NEITHER IS "CLOSE".

             A read carries on whether or not anybody is looking at it, so a button
             called Close would be naming the window instead of the thing in it.
             One button says what continues and the other says what stops — and
             what stops is worth confirming nothing, because the pass is already
             spent either way and the file keeps what it found.

             A FINISHED READ IS THE OTHER CASE. Nothing continues and nothing is
             left to abandon, so both of those become lies and the only honest
             button is the one that names the window. The poll does the swap. --}}
        <div class="panel__ft">
            <form method="POST" action="{{ route('cyberspace.abandon') }}" data-reading-abandon>
                @csrf @method('DELETE')
                <input type="hidden" name="address" data-reading-abandon-address value="">
                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('cyberspace.reading.abandon') }}</button>
            </form>
            <div class="spacer"></div>
            <button type="button" class="btn btn--sm cut" data-close data-reading-background>{{ Text::get('cyberspace.reading.background') }}</button>
            {{-- What a finished read is FOR. It spends the read, so it is a
                 press and never something the last pass does on its own — see
                 App\Cyberspace\ScanExporter. The cost is on the button's own
                 title rather than behind a confirmation: nothing is destroyed,
                 the read simply becomes the thing you can sell. --}}
            <form method="POST" action="{{ route('cyberspace.export') }}" data-reading-export hidden>
                @csrf
                <input type="hidden" name="address" data-reading-export-address value="">
                <button type="submit" class="btn btn--sm cut"
                        title="{{ Text::get('cyberspace.reading.export_hint') }}">
                    {{ Text::get('cyberspace.reading.export') }}
                </button>
            </form>
            <button type="button" class="btn btn--sm btn--ghost cut" data-close data-reading-done hidden>{{ Text::get('cyberspace.reading.close') }}</button>
        </div>
    </dialog>
@endif



{{-- Once, to an account that has not closed it: the invitation to the Discord. --}}
@if (auth()->check() && auth()->user()->seen_discord_at === null)
    <x-discord-invite />
@endif

<script src="{{ Asset::url('assets/js/ui-kit.js') }}" defer></script>
@stack('scripts')
</body>
</html>
