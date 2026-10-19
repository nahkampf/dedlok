@use('App\Support\Text')
@use('Illuminate\Support\Number')
@extends('layouts.app')
@section('title', Text::get('exchange.title'))

@section('content')
    <h1>{{ Text::get('exchange.title') }}</h1>

    {{-- WHO YOU ARE STANDING IN FRONT OF. An Exchange is the one system
         nothing can be done to, so this screen is not a briefing: it is a
         conversation, and the entity gets to introduce itself. --}}
    <div class="panel panel--snug" style="margin-top:var(--s-4)">
        <div class="panel__hd">
            <h2>{{ $entity?->name }}</h2>
            <div class="spacer"></div>
            <span class="tag">{{ $entity?->category->label() }}</span>
            <span class="tag">{{ Text::get('exchange.grade', ['grade' => $desk->grade]) }}</span>
            <span class="micro"><x-address :address="$system->address" /></span>
        </div>

        <div class="panel__bd stack" style="--gap:var(--s-3)">
            @if ($entity?->description)
                <p style="margin:0;max-width:70ch">{{ $entity->description }}</p>
            @endif

            <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                {{-- The agent is a person, not a department: a mission is
                     handed over by somebody, and this is who. --}}
                <span class="chip-stat">
                    <span class="micro">{{ Text::get('exchange.agent') }}</span>
                    <strong>{{ $desk->agent }}</strong>
                </span>

                <span class="chip-stat">
                    <span class="micro">{{ Text::get('entities.standing') }}</span>
                    <strong style="color:{{ $band->inkToken() }}">{{ $band->label() }}</strong>
                    <span class="num micro">{{ number_format($standing, 2) }}</span>
                </span>

                <span class="chip-stat">
                    <span class="micro">{{ Text::get('entities.loyalty') }}</span>
                    <strong class="num">{{ Number::format($loyalty) }}</strong>
                </span>
            </div>

            @php($bands = collect(config('standing.bands'))->pluck('at')->map(fn ($at) => (float) $at)->filter(fn ($at) => $at > -100))
            <div class="meter meter--lg meter--centred"
                 style="--v:{{ max(-1, min(1, $standing / 100)) }};--fill:{{ $band === \App\Standing\Band::Neutral ? 'var(--ink-3)' : ($standing < 0 ? 'var(--crit)' : 'var(--ok)') }}"
                 role="img" aria-label="{{ Text::get('entities.standing') }} {{ number_format($standing, 2) }}">
                <span class="meter__fill"></span>
                @foreach ($bands as $at)
                    <span class="meter__mark" style="--x:{{ ($at + 100) / 200 }};--mark:var(--ink-2)"></span>
                @endforeach
            </div>

            {{-- THE ONE GATE. The work and the shelf read the same column, so
                 an agent who will not deal with somebody does not sell to them
                 either — and the screen says what it would take. --}}
            @unless ($welcome)
                <div class="callout callout--warn">
                    <span class="dot dot--warn" style="margin-top:6px"></span>
                    <div class="stack" style="--gap:var(--s-2);min-width:0">
                        <strong style="font-size:var(--fs-sm)">{{ Text::get('exchange.unwelcome') }}</strong>
                        <span class="micro">{{ Text::get('exchange.unwelcome_hint', [
                            'standing' => number_format((float) $desk->min_standing, 2),
                            'band' => $desk->bandRequired()->label(),
                        ]) }}</span>
                    </div>
                </div>
            @endunless
        </div>
    </div>

    {{-- WHAT THIS DESK IS FOR. The work is real; the store is designed and
         not built, so it says so with a tag rather than being absent — the
         call the stash makes for an action that does nothing yet. --}}
    @if ($welcome)
        {{-- WORK IN PROGRESS FIRST. A player who is mid-job is here to hand it
             in or give it up, and a board above it would bury the one row they
             came for. --}}
        <div class="panel" style="margin-top:var(--s-4)">
            <div class="panel__hd">
                <span class="micro">{{ Text::get('missions.active') }}</span>
            </div>

            <div class="panel__bd">
                @if ($active === null)
                    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('missions.active_none') }}</p>
                @else
                    <x-mission-card :mission="$active" :active="true" :files="$files" :held="$heldAddresses" />
                @endif
            </div>
        </div>

        <details class="panel" open style="margin-top:var(--s-4)">
            <summary class="panel__hd">
                <span class="micro">{{ Text::get('missions.board') }}</span>
                <div class="spacer"></div>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('missions.refresh') }}</span>
            </summary>

            <div class="panel__bd">
                @if (count($board) === 0)
                    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('missions.board_empty') }}</p>
                @else
                    <div class="stack" style="--gap:var(--s-3)">
                        @foreach ($board as $offer)
                            <x-mission-card :mission="$offer" :active="false" :files="$files" />
                        @endforeach
                    </div>
                @endif
            </div>
        </details>
    @endif

    {{-- THE LOYALTY STORE. One currency and only one: nothing converts
         loyalty to creds or back, so a price here is never a number anybody
         can compare to the market's. What is on the shelf belongs to this
         entity at THIS grade — a grade 4 desk keeps things the grade 1 desk
         round the corner does not. --}}
    <details class="panel" style="margin-top:var(--s-4)">
        <summary class="panel__hd">
            <span class="micro">{{ Text::get('exchange.store') }}</span>
            <div class="spacer"></div>
            <span class="chip-stat num">{{ Text::get('exchange.store_have', ['loyalty' => Number::format($loyalty)]) }}</span>
        </summary>

        <div class="panel__bd">
            @if (! $welcome)
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('exchange.store_soon') }}</p>
            @elseif (count($shelf) === 0)
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('exchange.store_empty') }}</p>
            @else
                @include('market._grouped', ['entries' => $shelf, 'itemOf' => fn ($o) => $o->item, 'card' => 'exchange._offer', 'id' => 'offer-shelves'])
            @endif
        </div>
    </details>

    @if ($buying !== [])
        <div class="panel" style="margin-top:var(--s-4)">
            <div class="panel__hd"><span class="micro">{{ Text::get('exchange.they_buy') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                @foreach ($buying as $entry)
                    <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                        <span class="item-row__name" style="flex:1 1 160px;min-width:0">
                            {{ $entry['row']->item->displayName() }}
                            <span class="micro" style="color:var(--ink-3)">× {{ $entry['row']->quantity }}@if ($entry['left'] !== null) · {{ Text::get('city.shop.buy_left', ['count' => $entry['left']]) }}@endif</span>
                        </span>
                        <span class="num micro">{{ number_format($entry['unit']) }} ¤</span>
                        <form method="POST" action="{{ route('exchange.sell', $entry['row']->id) }}" class="row" style="--gap:var(--s-2)">
                            @csrf
                            <input class="input num" type="number" name="quantity" min="1" max="{{ $entry['row']->quantity }}" value="{{ min($entry['row']->quantity, $entry['left'] ?? $entry['row']->quantity) ?: 1 }}" style="width:5.5rem" aria-label="{{ Text::get('city.shop.quantity') }}">
                            <button type="submit" class="btn btn--sm cut" @disabled($entry['left'] === 0)>{{ Text::get('city.shop.sell') }}</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <p style="margin-top:var(--s-4)">
        <a class="btn btn--ghost btn--sm cut" href="{{ route('cyberspace.index') }}">
            &larr; {{ Text::get('cyberspace.map.title') }}
        </a>
    </p>
@endsection
