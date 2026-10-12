@use('App\Support\Text')
@use('App\Cyberspace\BookmarkKind')
@extends('layouts.app')
@section('title', Text::get('cyberspace.map.title'))
{{-- Room for the map and its boxes side by side on a big screen: see layouts/app. --}}
@section('wide', '1')

@section('content')
    <h1>{{ Text::get('cyberspace.map.title') }}</h1>
    <p class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.map.lede') }}</p>

    {{-- Where you are, and the way in or out. One row, wrapping, because on a
         320px screen these are four things that will not sit side by side. --}}
    <div class="panel panel--snug" style="margin-top:var(--s-4)">
        <div style="display:flex;flex-wrap:wrap;gap:var(--s-3);align-items:center;justify-content:space-between">
            <div style="display:flex;flex-wrap:wrap;gap:var(--s-4);align-items:center">
                @if ($map->jackedIn)
                    <span class="chip-stat">
                        <span class="micro">{{ Text::get('cyberspace.map.position') }}</span>
                        <strong>{{ $character->gridPosition() }}</strong>
                    </span>
                    @if ($here)
                        {{-- The name says what it is and who answers for it —
                             a trace one day costs you standing with them — so
                             the owner is not a second element beside it. What
                             is drawn is the memory's name, not the machine's:
                             the two can differ, and the difference is the
                             point of a contact. --}}
                        <span class="tag">{{ $here->name }}</span>
                        <span class="micro"><x-address :address="$here->address" /></span>

                        {{-- And what they make of you. Only for an entity
                             somebody can deal with: the rest of the grid
                             belongs to companies with no opinion to have. --}}
                        <x-standing-tag :entity="$target?->owner" :standing="$standing" />

                        {{-- What this machine has given up so far. A grade is
                             the number a scan exists to discover, so it is
                             shown as soon as it is known and as "unread" until
                             then — never as a blank, which reads as a machine
                             with nothing to hide. --}}
                        @if ($scan?->grade !== null)
                            <span class="tag">{{ Text::get('cyberspace.scan.grade') }} {{ \App\Cyberspace\Grade::roman($scan->grade) }}</span>
                        @else
                            <span class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.scan.unknown') }}</span>
                        @endif

                        @if ($scan?->operatingSystem)
                            <span class="micro">{{ $scan->operatingSystem->title }}</span>
                        @endif

                        {{-- Reading a machine takes software. Said here rather
                             than only in the refusal, because a disabled button
                             with no reason beside it is a screen that looks
                             broken. --}}
                        @unless ($canScan)
                            @if (! $analyserRunning)
                                <span class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.scan.no_analyser') }}</span>
                            @elseif (! $scan?->isRunning())
                                {{-- Running, but every instance is reading some other machine. A read on THIS one
                                     says nothing: the box beside it already offers to show it. --}}
                                <span class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.scan.all_busy') }}</span>
                            @endif
                        @endunless

                        {{-- A pass in progress. The bar is the kit's meter with
                             the elapsed fraction in --v, so it reads as
                             something happening rather than as a frozen button
                             — and the figure beside it is what a player
                             actually wants, which is when to come back. --}}
                        @if ($scan?->isRunning())
                            {{-- The way back into the read. A pass runs for
                                 minutes and a player who closed the screen
                                 needs a door back to it. --}}
                            {{-- Seconds remaining and in all, from the server: the bar and the time are ticked by
                                 `ui-kit.js` between loads, so a pass visibly moves. --}}
                            <button type="button" class="chip-stat" data-reading-open="{{ $here->address }}"
                                    data-pass-clock
                                    data-remaining="{{ max(0, (int) now()->diffInSeconds($scan->pass_ready_at, false)) }}"
                                    data-total="{{ max(1, (int) $scan->pass_started_at?->diffInSeconds($scan->pass_ready_at, false)) }}">
                                <span class="micro">{{ Text::get('cyberspace.scan.reading') }}</span>
                                <span class="meter" data-pass-bar style="--v:{{ round($scan->progress(), 3) }};width:80px"
                                      title="{{ Text::get('cyberspace.scan.ready_in', ['when' => $scan->pass_ready_at->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE])]) }}">
                                    <span class="meter__fill"></span>
                                </span>
                                <span class="micro" data-pass-left>{{ $scan->pass_ready_at->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</span>
                            </button>
                        @endif
                    @endif
                @else
                    <span class="micro">{{ Text::get('cyberspace.map.out') }}</span>
                @endif

                <span class="chip-stat">
                    <span class="micro">{{ Text::get('cyberspace.map.swept') }}</span>
                    <strong>{{ Text::get('cyberspace.map.swept_count', ['count' => $swept]) }}</strong>
                </span>
            </div>

            {{-- The one filled button on the screen. .btn--go means BEGIN, and
                 jacking in is the only thing here that begins anything. --}}
            @if ($map->jackedIn)
                <form method="POST" action="{{ route('cyberspace.jack_out') }}">
                    @csrf
                    {{-- A plain button, not --warn. Jacking out undoes no work:
                         terrain, contacts and bookmarks all survive it. --}}
                    <button type="submit" class="btn cut">{{ Text::get('cyberspace.map.jack_out') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('cyberspace.jack_in') }}">
                    @csrf
                    <button type="submit" class="btn btn--go cut">{{ Text::get('cyberspace.map.jack_in') }}</button>
                </form>
            @endif
        </div>

        @unless ($map->jackedIn)
            <p class="micro" style="color:var(--ink-2);margin-top:var(--s-3)">
                {{ Text::get('cyberspace.map.out_hint') }}
            </p>
        @endunless
    </div>

    {{-- The map. One form around the whole grid: every step is the same POST
         with a different target, so 400 cells do not need 400 forms. --}}
    {{-- The map and the boxes that act on it (jump, going in, thresholds, routing chain, bookmarks). On a wide
         screen the boxes stand to the RIGHT of the map; narrower, they sit under it as they always did. The width
         that decides is the room this block actually has, not the window's (`.map-split`, a container query),
         because the page's own column and the rail change it. --}}
    <div class="map-split">
    <div class="map-split__body">
    <div class="map-main">
    <form method="POST" action="{{ route('cyberspace.step') }}">
        @csrf
    <div class="gridmap-frame">
        <div class="gridmap" style="--span:{{ $map->span() }}">
            <div class="gridmap__grid">
                @foreach ($map->rows as $row)
                    @foreach ($row as $cell)
                        @php
                            $classes = 'cell '.$cell->classes().($cell->bookmark ? ' cell--marked' : '');
                            $title = $cell->onGrid ? (string) $cell->position : Text::get('cyberspace.map.void');
                            if ($cell->isNew) {
                                $title = Text::get('cyberspace.map.new').' — '.$title;
                            }
                            if ($cell->contact) {
                                $title = $cell->contact->name
                                    .($cell->gradeRatio() !== null
                                        ? ' · '.Text::get('cyberspace.scan.grade').' '.$cell->grade
                                        : '')
                                    .' — '.$title;
                            } elseif (! $cell->swept && $cell->onGrid) {
                                $title = Text::get('cyberspace.map.unswept').' — '.$title;
                            }
                        @endphp

                        @if ($cell->isStep)
                            {{-- The eight. Their offset travels in data-step so the
                                 keyboard can reach the same button a thumb does. --}}
                            <button type="submit" name="to"
                                    value="{{ $cell->position->x }},{{ $cell->position->y }}"
                                    class="{{ $classes }} cell--step"
                                    data-step="{{ $cell->position->x - $map->centre->x }},{{ $cell->position->y - $map->centre->y }}"
                                    title="{{ Text::get('cyberspace.map.step') }} — {{ $title }}"
                                    @if ($cell->gradeRatio() !== null) style="--g:{{ $cell->gradeRatio() }}" @endif
                                    aria-label="{{ Text::get('cyberspace.map.step') }} {{ $title }}">{{ $cell->glyph() }}</button>
                        @else
                            <div class="{{ $classes }}" title="{{ $title }}"
                                 @if ($cell->gradeRatio() !== null) style="--g:{{ $cell->gradeRatio() }}" @endif
                                 @if ($cell->isYou) aria-label="{{ Text::get('cyberspace.map.here') }}" @endif>{{ $cell->glyph() }}</div>
                        @endif
                    @endforeach
                @endforeach
            </div>
        </div>

        {{-- The key sits beside the map rather than under it: the map scrolls
             and this is reference material, so it gets a column instead of a
             strip of dead space. It overlays the map's right edge when it
             opens, the way the rail overlays the page — a column that pushed
             the map narrower on hover would move the ground under the cursor,
             and the map re-centres on scroll. --}}
        <div class="maplegend" data-legend>
        {{-- The way in on a phone, and the way to pin it open on a desktop.
             Hovering opens it where a pointer exists; this is everywhere. --}}
        <button type="button" class="maplegend__toggle" data-legend-toggle aria-expanded="false">
            <span class="maplegend__icon" aria-hidden="true">&#9776;</span>
            <span class="maplegend__label">{{ Text::get('cyberspace.map.legend') }}</span>
        </button>

        <div class="maplegend__keys">
            {{-- The swatches are real .cell elements wearing the classes they
                 explain, so the key cannot drift from the map beside it. Half
                 of them carry a glyph for the same reason: the shape IS the
                 entry. --}}
            @foreach ([
                ['cell--you', Text::get('cyberspace.map.here'), '●'],
                ['cell--system cell--silo', Text::get('cyberspace.types.silo'), '▤'],
                ['cell--system cell--compute', Text::get('cyberspace.types.compute'), '⌬'],
                ['cell--system cell--relay', Text::get('cyberspace.types.relay'), '☰'],
                ['cell--portal', Text::get('cyberspace.types.portal'), '◍'],
                ['cell--system cell--exchange', Text::get('cyberspace.types.exchange'), '⚖'],
                ['cell--empty', Text::get('cyberspace.map.empty'), ''],
                ['cell--unswept', Text::get('cyberspace.map.unswept'), ''],
                ['cell--system cell--silo cell--new', Text::get('cyberspace.map.new'), '▤'],
                ['cell--system cell--compute cell--backdoored', Text::get('cyberspace.map.backdoored'), '⌬'],
                ['cell--system cell--relay cell--rebooting', Text::get('cyberspace.map.rebooting'), '☰'],
                ['cell--system cell--silo cell--scanned', Text::get('cyberspace.map.scanned'), '▤'],
                ['cell--empty cell--marked', Text::get('cyberspace.map.marked'), ''],
            ] as [$class, $label, $glyph])
                <span class="maplegend__key">
                    <span class="gridmap__swatch cell {{ $class }}" aria-hidden="true">{{ $glyph }}</span>
                    <span class="maplegend__label">{{ $label }}</span>
                </span>
            @endforeach

            <span class="maplegend__key">
                <span class="gridmap__swatch cell cell--system cell--silo" style="--g:.55" aria-hidden="true">▤</span>
                <span class="maplegend__label">{{ Text::get('cyberspace.map.grade_key') }}</span>
            </span>
        </div>
        </div>
    </div>
    </form>

    <p class="micro" style="color:var(--ink-2);margin-top:var(--s-2)">{{ Text::get('cyberspace.map.keys') }}</p>

    {{-- Getting somewhere without walking: an address anyone can hand you, and
         the places you have saved. --}}
    </div>

    <div class="map-side">
        {{-- An accordion like the thresholds, closed until wanted: the jump, saving an address and browsing what is known. --}}
        <details class="panel panel--snug">
            <summary class="panel__hd"><h2>{{ Text::get('cyberspace.map.jump') }}</h2></summary>

            <form method="POST" action="{{ route('cyberspace.jump') }}">
                @csrf
                <label class="micro" for="address">{{ Text::get('cyberspace.map.address') }}</label>
                <div style="display:flex;flex-wrap:wrap;gap:var(--s-2);margin-top:var(--s-1)">
                    <input class="input" id="address" name="address" maxlength="32"
                           style="flex:1;min-width:min(160px,100%);font-family:var(--font-mono)"
                           placeholder="A3F91C08" autocomplete="off">
                    <button type="submit" class="btn cut" @unless ($map->jackedIn) disabled @endunless>
                        {{ Text::get('cyberspace.map.jump') }}
                    </button>
                </div>
                <p class="micro" style="color:var(--ink-2);margin-top:var(--s-2)">{{ Text::get('cyberspace.map.jump_hint') }}</p>
            </form>

            {{-- Every system you have seen that is still where you saw it and open, filterable: a fragment in a modal. --}}
            <button type="button" class="btn btn--sm cut" data-open="#known-systems" style="margin-top:var(--s-3)">
                {{ Text::get('cyberspace.known.browse') }}
            </button>

            {{-- Saving an address is not travelling to it: you may keep one you
                 have never been to, which is what a traded address is for. --}}
            <form method="POST" action="{{ route('cyberspace.bookmark') }}" style="margin-top:var(--s-4)">
                @csrf
                <input type="hidden" name="kind" value="{{ BookmarkKind::System->value }}">
                <label class="micro" for="save-address">{{ Text::get('cyberspace.bookmarks.mark_address') }}</label>
                <div style="display:flex;flex-wrap:wrap;gap:var(--s-2);margin-top:var(--s-1)">
                    <input class="input" id="save-address" name="address" maxlength="32"
                           style="flex:1;min-width:min(160px,100%);font-family:var(--font-mono)"
                           autocomplete="off">
                    <input class="input" name="label" maxlength="48"
                           style="flex:1;min-width:min(120px,100%)"
                           placeholder="{{ Text::get('cyberspace.bookmarks.label') }}" autocomplete="off">
                    <button type="submit" class="btn btn--sm cut">{{ Text::get('cyberspace.bookmarks.save') }}</button>
                </div>
            </form>
        </details>

        {{-- GOING IN.

             The decision itself lives on its own screen, because everything
             about it is permanent — the purpose, a backdoor's kind, the route
             — and because what a player needs before committing is a briefing
             rather than a button: what they have read off the machine, what
             their deck is about to do to itself, and what is running. This is
             the way to it and deliberately not a second copy of it. --}}
        @if ($target)
            <section class="panel panel--snug">
                <div class="panel__hd">
                    <h2>{{ Text::get('cyberspace.run.setup.heading') }}</h2>
                    <div class="spacer"></div>
                    <span class="tag">{{ $target->displayName() }}</span>
                </div>

                <div style="padding:var(--s-3) 0">
                    {{-- A DESK IS NOT A TARGET. Nothing can be run against an
                         Exchange, so the one thing to do here is the thing
                         somebody walked out to it for. --}}
                    @if ($target->type->isExchange())
                        <a class="btn cut" href="{{ route('exchange.show') }}">
                            {{ Text::get('exchange.visit') }}
                        </a>
                    @else
                        {{-- Two instructions, in the order a player does them. "Plan a run" says by
                             its colour whether the read is whole: green when everything is known,
                             brass when it is not (the run is still allowed; it is only blind).
                             The scan beside it is a plain button, so there is never a second filled
                             one next to it. --}}
                        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                            @if ($canRun)
                                <a class="btn {{ $scan?->isComplete() ? 'btn--go' : 'btn--warn' }} cut" href="{{ route('run.setup') }}">
                                    {{ Text::get('cyberspace.run.setup.open') }}
                                </a>
                            @else
                                <p class="micro" style="color:var(--ink-2);margin:0">
                                    {{ $target->isLockedDown()
                                        ? Text::get('cyberspace.run.refused.locked_down')
                                        : Text::get('cyberspace.run.refused.not_intrudable') }}
                                </p>
                            @endif

                            {{-- One press starts one pass, which takes MINUTES and is spent whether or not
                                 it finds anything. Dead while a read is running or whole, and live again
                                 once the file has gone stale, because a stale file is no longer `$scan`
                                 at all. An interrupted read can be started again. --}}
                            @if ($target->type->isScannable())
                                @if ($scan?->isRunning())
                                    {{-- A read is going: the way back into it replaces the button that
                                         would start one, since there is nothing to start. --}}
                                    <button type="button" class="btn cut" data-reading-open="{{ $target->address }}">
                                        {{ Text::get('cyberspace.scan.show') }}
                                    </button>
                                @else
                                    <form method="POST" action="{{ route('cyberspace.scan') }}">
                                        @csrf
                                        <button type="submit" class="btn cut"
                                                @disabled(! $here || ! $canScan || $scan?->isComplete())>
                                            {{ Text::get('cyberspace.scan.action') }}
                                        </button>
                                    </form>
                                @endif
                            @endif
                        </div>

                        {{-- WHAT YOU ALREADY HOLD HERE: a backdoor of yours, and which kind it is. The same
                             label-left, tags-right row the facts below use. Access you were given for an entity's
                             job leaves no row of yours, so it is not shown. --}}
                        @if ($heldBackdoor)
                            <div class="card__stats" style="padding:0;border:0;margin-top:var(--s-3)">
                                <div class="card__stat">
                                    <span>{{ Text::get('cyberspace.interact.installed_here') }}</span>
                                    <span class="row row--wrap" style="--gap:var(--s-2)">
                                        <span class="tag tag--ok">{{ Text::get('cyberspace.interact.backdoor') }}</span>
                                        <span class="tag tag--ok" title="{{ Text::get('backdoor.hint.'.$heldBackdoor->kind->value) }}">{{ $heldBackdoor->kind->label() }}</span>
                                    </span>
                                </div>
                            </div>
                        @endif

                        {{-- What the read has found out so far, in the rows and boxes the scanner window and
                             the briefing use (<x-scan-facts>): every fact either way, so a blank says "not
                             read yet" instead of looking like nothing to find. Not a count of anything hidden. --}}
                        @if ($target->type->isScannable())
                            <div style="margin-top:var(--s-3)">
                                <x-scan-facts :facts="($scan ?? new \App\Models\SystemScan)->facts($character)" />
                            </div>
                        @endif
                    @endif

                    {{-- AUTHORED ENCOUNTERS PINNED TO THIS SYSTEM (the spot door). Not rolled: one button each while its
                         conditions hold and its cooldown has run out. --}}
                    @foreach ($spots as $spot)
                        <form method="POST" action="{{ route('cyberspace.spot', $spot->id) }}" style="margin-top:var(--s-3)">
                            @csrf
                            <button type="submit" class="btn cut">{{ $spot->title }}</button>
                        </form>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- THE TWO STANDING INSTRUCTIONS.

             An accordion because they are set once and left alone — a play
             style rather than a per-run decision, and a screen that asked every
             time would train everybody to ignore it. Folded away by default for
             the same reason: `<details>` already knows how to do this, keyboard
             and screen reader included, and needs no script. --}}
        <details class="panel panel--snug">
            <summary class="panel__hd">
                <h2>{{ Text::get('cyberspace.run.thresholds.heading') }}</h2>
                <div class="spacer"></div>
                {{-- Two tags rather than one line of text, so the values read at a glance when the
                     panel is folded; each says in its title which threshold it is. --}}
                <span class="tag num" title="{{ Text::get('cyberspace.run.thresholds.trace') }}">{{ $character->egress_trace_at }}%</span>
                <span class="tag num" title="{{ Text::get('cyberspace.run.thresholds.si') }}">{{ $character->egress_si_at }}%</span>
            </summary>

            <form method="POST" action="{{ route('cyberspace.thresholds') }}" style="padding:var(--s-3) 0">
                @csrf

                <p class="micro" style="color:var(--ink-2);margin:0 0 var(--s-3)">
                    {{ Text::get('cyberspace.run.thresholds.hint') }}
                </p>

                <div class="field">
                    <label class="field__label" for="egress_trace_at">
                        {{ Text::get('cyberspace.run.thresholds.trace') }}
                    </label>
                    <div class="row" style="--gap:var(--s-3);align-items:center">
                        <input type="range" id="egress_trace_at" name="egress_trace_at" min="0" max="100" step="5"
                               value="{{ old('egress_trace_at', $character->egress_trace_at) }}"
                               oninput="this.nextElementSibling.textContent = this.value + '%'"
                               style="flex:1;min-width:min(160px,100%)">
                        <span class="num" style="min-width:3.5ch">{{ $character->egress_trace_at }}%</span>
                    </div>
                </div>

                <div class="field" style="margin-top:var(--s-3)">
                    <label class="field__label" for="egress_si_at">
                        {{ Text::get('cyberspace.run.thresholds.si') }}
                    </label>
                    <div class="row" style="--gap:var(--s-3);align-items:center">
                        <input type="range" id="egress_si_at" name="egress_si_at" min="0" max="100" step="5"
                               value="{{ old('egress_si_at', $character->egress_si_at) }}"
                               oninput="this.nextElementSibling.textContent = this.value + '%'"
                               style="flex:1;min-width:min(160px,100%)">
                        <span class="num" style="min-width:3.5ch">{{ $character->egress_si_at }}%</span>
                    </div>
                </div>

                <div class="row row--wrap" style="--gap:var(--s-2);align-items:center;margin-top:var(--s-3)">
                    <button type="submit" class="btn btn--sm cut">{{ Text::get('cyberspace.run.thresholds.save') }}</button>
                    <span class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.run.thresholds.off') }}</span>
                </div>
            </form>
        </details>

        {{-- THE LOCATOR (App\Cyberspace\Finder): drawn only while the program runs. The nearest system you have met that fits, with
             its distance and which of eight directions it lies in. --}}
        @if ($locator !== null)
            <section class="panel panel--snug" id="locator">
                <div class="panel__hd">
                    <h2>{{ Text::get('cyberspace.locate.heading') }}</h2>
                    <div class="spacer"></div>
                    @if ($locator['name'])
                        <span class="tag">{{ $locator['name'] }}</span>
                    @endif
                </div>
                <form method="GET" action="{{ route('cyberspace.index') }}#locator" class="stack" style="--gap:var(--s-2);margin-top:var(--s-3)">
                    <div style="display:flex;flex-wrap:wrap;gap:var(--s-2)">
                        <input class="input" id="find" name="find" value="{{ $locator['raw'] }}" maxlength="100"
                               style="flex:1;min-width:min(160px,100%)" autocomplete="off"
                               placeholder="{{ Text::get('cyberspace.locate.placeholder') }}">
                        <button type="submit" class="btn btn--sm cut">{{ Text::get('cyberspace.locate.go') }}</button>
                    </div>
                    <span class="field__hint">{{ Text::get('cyberspace.locate.hint') }}</span>
                </form>

                @if ($locator['query'] !== null)
                    @php($found = $locator['result'])
                    <div class="stack" style="--gap:var(--s-2);margin-top:var(--s-3);padding-top:var(--s-3);border-top:var(--hair) solid var(--line-0)">
                        @if ($found === null)
                            <p class="micro" style="color:var(--ink-2);margin:0">{{ Text::get('cyberspace.locate.none') }}</p>
                        @else
                            <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                                <span aria-hidden="true" style="font-size:var(--fs-xl);line-height:1">{{ $found->arrow() }}</span>
                                <div style="min-width:0">
                                    <div class="item-row__name">{{ $found->contact->name }}</div>
                                    <div class="micro" style="color:var(--ink-2)">
                                        {{ Text::get('cyberspace.locate.distance', ['steps' => $found->steps]) }}
                                    </div>
                                </div>
                            </div>
                            <div class="row row--wrap micro" style="--gap:var(--s-2);align-items:center">
                                <x-address :address="$found->contact->address" />
                                <span class="tag">{{ $found->contact->type->label() }}</span>
                                @if ($found->contact->owner)
                                    <span class="tag">{{ $found->contact->owner }}</span>
                                @endif
                                @if ($found->grade !== null)
                                    <span class="tag">{{ Text::get('cyberspace.locate.grade', ['grade' => $found->grade]) }}</span>
                                @endif
                            </div>
                            @if ($found->fromPortal)
                                <span class="field__hint">{{ Text::get('cyberspace.locate.from_portal') }}</span>
                            @elseif ($locator['canTraverse'])
                                <div>
                                    <button type="button" class="btn btn--sm cut" data-autotraverse="traverse-dialog"
                                            data-url="{{ route('cyberspace.traverse') }}" data-x="{{ $found->contact->x }}" data-y="{{ $found->contact->y }}"
                                            data-steps="{{ $found->steps }}" data-step-ms="10000">{{ Text::get('cyberspace.locate.autotraverse') }}</button>
                                </div>
                                {{-- Over the whole screen: the dialog's backdrop is the yellow half-transparent overlay. --}}
                                <dialog class="modal modal--traverse" id="traverse-dialog">
                                    <div class="panel__hd"><span class="micro">{{ Text::get('cyberspace.locate.traversing') }}</span></div>
                                    <div class="panel__bd stack" style="--gap:var(--s-4)">
                                        <div class="item-row__name" data-traverse-left></div>
                                        <div class="meter" style="--v:0" data-traverse-meter><div class="meter__fill"></div></div>
                                        <button type="button" class="btn btn--block cut" data-traverse-cancel>{{ Text::get('cyberspace.locate.traverse_cancel') }}</button>
                                    </div>
                                </dialog>
                            @endif
                        @endif
                    </div>
                @endif
            </section>
        @endif

        {{-- THE ROUTING CHAIN, as the next run would build it (App\Cyberspace\ChainBuilder): every system you hold a
             routing backdoor on, highest grade first, up to the most your Routing skills allow. Nothing to pick, so
             nothing to press. --}}
        <details class="panel panel--snug">
            <summary class="panel__hd">
                <h2>{{ Text::get('cyberspace.chain.heading') }}</h2>
                <div class="spacer"></div>
                <span class="micro num" title="{{ Text::get('cyberspace.chain.hops_tip', ['used' => $chain->count(), 'cap' => $chainCap]) }}">{{ Text::get('cyberspace.run.setup.chain_hops', ['used' => $chain->count(), 'cap' => $chainCap]) }}</span>
            </summary>

            @if ($chain->isEmpty())
                <p class="micro" style="color:var(--ink-2);margin:var(--s-3) 0 0">
                    {{ $chainCap < 1 ? Text::get('cyberspace.run.setup.chain_untrained') : Text::get('cyberspace.run.setup.chain_none') }}
                </p>
            @else
                <ol class="stack" style="--gap:var(--s-2);margin:var(--s-3) 0 0;padding:0;list-style:none">
                    @foreach ($chain as $hop)
                        <li class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                            <span class="micro num" style="color:var(--ink-3)">{{ $loop->iteration }}</span>
                            <span class="item-row__name" style="min-width:0">{{ $hop->displayName() }}</span>
                            <span class="micro"><x-address :address="$hop->address" /></span>
                            <span class="tag">{{ Text::get('cyberspace.run.setup.hop', ['grade' => $hop->grade]) }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </details>

        <section class="panel panel--snug">
            <div class="panel__hd"><h2>{{ Text::get('cyberspace.bookmarks.heading') }}</h2></div>

            @if ($map->jackedIn)
                <form method="POST" action="{{ route('cyberspace.bookmark') }}" style="margin-bottom:var(--s-3)">
                    @csrf
                    <input type="hidden" name="kind" value="{{ BookmarkKind::Waypoint->value }}">
                    <input type="hidden" name="at" value="{{ $character->grid_x }},{{ $character->grid_y }}">
                    <div style="display:flex;flex-wrap:wrap;gap:var(--s-2)">
                        <input class="input" name="label" maxlength="48"
                               style="flex:1;min-width:min(140px,100%)"
                               placeholder="{{ Text::get('cyberspace.bookmarks.label') }}" autocomplete="off">
                        <button type="submit" class="btn btn--sm cut">{{ Text::get('cyberspace.bookmarks.mark_here') }}</button>
                    </div>
                </form>
            @endif

            @forelse ($bookmarks as $mark)
                @php($standing = $mark->resolve())
                <div style="display:flex;flex-wrap:wrap;gap:var(--s-2);align-items:center;justify-content:space-between;padding:var(--s-2) 0;border-top:var(--hair) solid var(--line-0)">
                    <div style="min-width:0">
                        <div>
                            @if ($mark->displayLabel() !== null)
                                {{ $mark->displayLabel() }}
                            @else
                                <x-address :address="$mark->address" />
                            @endif
                        </div>
                        <span class="tag tag--{{ $mark->isWaypoint() ? 'info' : 'warn' }}">{{ $mark->kind->label() }}</span>
                        @if (! $mark->isWaypoint() && $standing === null)
                            <span class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.bookmarks.dead') }}</span>
                        @endif
                    </div>

                    <div style="display:flex;flex-wrap:wrap;gap:var(--s-2)">
                        <form method="POST" action="{{ route('cyberspace.recall', $mark) }}">
                            @csrf
                            <button type="submit" class="btn btn--sm cut" @unless ($map->jackedIn) disabled @endunless>
                                {{ Text::get('cyberspace.bookmarks.recall') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('cyberspace.forget', $mark) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('cyberspace.bookmarks.forget') }}</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.bookmarks.none') }}</p>
            @endforelse
        </section>
    </div>
    </div>
    </div>

    {{-- BROWSE KNOWN SYSTEMS. The body is the fragment `cyberspace.systems`, fetched each time this opens and again when a
         filter changes, so it is never older than the click. --}}
    <dialog id="known-systems" class="modal">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('cyberspace.known.title') }}</span>
            <button type="button" class="btn btn--sm btn--ghost" data-close>{{ Text::get('ui.action.cancel') }}</button>
        </div>
        <div class="panel__bd" data-fragment="{{ route('cyberspace.systems') }}">
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('processes.loading') }}</p>
        </div>
    </dialog>
@endsection
