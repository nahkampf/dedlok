@use('App\Support\Text')
@use('App\Cyberspace\BookmarkKind')
@extends('layouts.app')
@section('title', Text::get('cyberspace.map.title'))

@section('content')
    <h1>{{ Text::get('cyberspace.map.title') }}</h1>
    <p class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.map.lede') }}</p>

    {{-- Where you are, and the way in or out. One row, wrapping, because on a
         320px screen these are four things that will not sit side by side. --}}
    <div class="panel" style="margin-top:var(--s-4)">
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
                            <span class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.scan.no_analyser') }}</span>
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
                            <button type="button" class="chip-stat" data-reading-open="{{ $here->address }}">
                                <span class="micro">{{ Text::get('cyberspace.scan.reading') }}</span>
                                <span class="meter" style="--v:{{ round($scan->progress(), 3) }};width:80px"
                                      title="{{ Text::get('cyberspace.scan.ready_in', ['when' => $scan->pass_ready_at->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE])]) }}">
                                    <span class="meter__fill"></span>
                                </span>
                                <span class="micro">{{ $scan->pass_ready_at->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</span>
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

                {{-- One press starts one pass, which takes MINUTES and is
                     spent whether or not it finds anything. Offered only where
                     there is something to read: the portal answers nothing and
                     empty ground is not a machine. --}}
                <form method="POST" action="{{ route('cyberspace.scan') }}">
                    @csrf
                    <button type="submit" class="btn btn--sm cut"
                            @disabled(! $here || ! $canScan || $scan?->isRunning() || $scan?->isComplete() || $scan?->passes >= $scanPasses)>
                        {{ Text::get('cyberspace.scan.action') }}
                    </button>
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
    <form method="POST" action="{{ route('cyberspace.step') }}" style="margin-top:var(--s-4)">
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
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(280px,100%),1fr));gap:var(--s-4);margin-top:var(--s-5)">
        <section class="panel">
            <div class="panel__hd"><h2>{{ Text::get('cyberspace.map.jump') }}</h2></div>

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
        </section>

        <section class="panel">
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

{{-- THE READ, AS A SCREEN YOU WATCH.
     A pass takes minutes, so what a player wants while one runs is: what am I
     pointed at, with what, how far in, and what has it told me so far. It
     polls `cyberspace.reading`, which settles a matured pass on the way past —
     so the answers fill in the moment a pass finishes rather than at the next
     reload. --}}
<dialog class="modal" id="reading" data-reading
        data-reading-url="{{ route('cyberspace.reading') }}"
        data-reading-pass="{{ Text::get('cyberspace.reading.pass_of', ['pass' => ':pass', 'total' => ':total']) }}"
        data-reading-unread="{{ Text::get('cyberspace.reading.unread') }}"
        data-reading-complete="{{ Text::get('cyberspace.reading.complete') }}"
        data-reading-idle="{{ Text::get('cyberspace.reading.idle') }}"
        data-reading-exhausted="{{ Text::get('cyberspace.reading.exhausted') }}"
        data-reading-gone="{{ Text::get('cyberspace.scan.voided') }}"
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

    <div class="panel__ft">
        <button type="button" class="btn btn--sm cut" data-close>{{ Text::get('cyberspace.reading.close') }}</button>
    </div>
</dialog>

@endsection
