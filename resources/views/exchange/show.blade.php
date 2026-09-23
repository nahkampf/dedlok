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

    {{-- The two halves of what a desk is for. Both are designed and neither is
         built, so each says so with a tag rather than being absent — the call
         the stash makes for an action that does nothing yet. --}}
    <div class="grid grid--pair" style="margin-top:var(--s-4)">
        <div class="panel">
            <div class="panel__hd">
                <span class="micro">{{ Text::get('exchange.missions') }}</span>
                <div class="spacer"></div>
                <span class="tag">{{ Text::get('exchange.not_built') }}</span>
            </div>
            <div class="panel__bd">
                <p class="muted" style="font-size:var(--fs-sm);margin:0">
                    {{ Text::get('exchange.missions_soon', [
                        'low' => $desk->targetGrades()[0],
                        'high' => $desk->targetGrades()[1],
                    ]) }}
                </p>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd">
                <span class="micro">{{ Text::get('exchange.store') }}</span>
                <div class="spacer"></div>
                <span class="tag">{{ Text::get('exchange.not_built') }}</span>
            </div>
            <div class="panel__bd">
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('exchange.store_soon') }}</p>
            </div>
        </div>
    </div>

    <p style="margin-top:var(--s-4)">
        <a class="btn btn--ghost btn--sm cut" href="{{ route('cyberspace.index') }}">
            &larr; {{ Text::get('cyberspace.title') }}
        </a>
    </p>
@endsection
