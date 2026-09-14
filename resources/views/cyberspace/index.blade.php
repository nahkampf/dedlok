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
                        <span class="micro" style="font-family:var(--font-mono)">{{ $here->address }}</span>
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
    <form method="POST" action="{{ route('cyberspace.step') }}" style="margin-top:var(--s-4)">
        @csrf
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
                                $title = $cell->contact->name.' — '.$title;
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
                                    aria-label="{{ Text::get('cyberspace.map.step') }} {{ $title }}">{!! $cell->contact ? '&#9670;' : '' !!}</button>
                        @else
                            <div class="{{ $classes }}" title="{{ $title }}"
                                 @if ($cell->isYou) aria-label="{{ Text::get('cyberspace.map.here') }}" @endif>{!! $cell->isYou ? '&#9679;' : ($cell->contact ? '&#9670;' : '') !!}</div>
                        @endif
                    @endforeach
                @endforeach
            </div>
        </div>
    </form>

    <p class="micro" style="color:var(--ink-2);margin-top:var(--s-2)">{{ Text::get('cyberspace.map.keys') }}</p>

    <div class="gridmap__legend" style="margin-top:var(--s-3)">
        @foreach ([
            ['cell--you', Text::get('cyberspace.map.here')],
            ['cell--system', Text::get('cyberspace.map.address')],
            ['cell--portal', Text::get('cyberspace.types.portal')],
            ['cell--empty', Text::get('cyberspace.map.empty')],
            ['cell--unswept', Text::get('cyberspace.map.unswept')],
            ['cell--system cell--new', Text::get('cyberspace.map.new')],
        ] as [$class, $label])
            <span class="gridmap__key">
                <span class="gridmap__swatch cell {{ $class }}" aria-hidden="true"></span>
                <span class="micro">{{ $label }}</span>
            </span>
        @endforeach
    </div>

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
                        <div>{{ $mark->displayLabel() ?? $mark->address }}</div>
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
@endsection
