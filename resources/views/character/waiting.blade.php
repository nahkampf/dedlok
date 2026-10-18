@use('App\Cyberspace\DefeatTag')
@use('App\Support\Text')

@extends('layouts.app')
@section('title', Text::get('character.waiting.title'))

@section('content')

{{-- THE FLOOR UNDER EVERY DEFEAT.

     Read by somebody who has just lost and cannot go anywhere else, so it says
     what happened rather than commiserating. The wait is not the punishment —
     what losing cost is whatever the defeat tags carried — and the screen puts
     those first for that reason. --}}
{{-- Everything BEHIND the box is stripes: bone and ember at 45 degrees, 16px wide (`.floor-overlay`). The box sits
     above it. --}}
<div class="floor-overlay" aria-hidden="true"></div>

<div class="panel panel--snug" style="margin-top:var(--s-5);max-width:min(560px,100%);margin-inline:auto">
    {{-- The ember hazard stripe across the top of the box, the hunt screen's own: being finished is what the
         accent is for, and the box says so before a word of it is read. --}}
    <div class="hazard-stripe" aria-hidden="true"></div>

    <div class="panel__hd">
        {{-- From the geometric set the kit has proved, like every other glyph
             in the game — and the one place the accent is spent on this screen,
             because being finished is exactly what ember is for. --}}
        <span aria-hidden="true" style="color:var(--crit);font-size:var(--fs-lg)">&#9760;</span>
        <h2>{{ Text::get($cause?->reason === 'collapse' ? 'character.waiting.heading_collapse' : 'character.waiting.heading') }}</h2>
    </div>

    <div class="panel__bd">
        <div class="stack" style="--gap:var(--s-4)">

            {{-- The clock, as a bar that fills. `--v` is set on the server and
                 the page reloads itself when the minute is up: a progress bar
                 driven by a timer nobody is watching would be a lie, and the
                 middleware is what actually releases anybody. --}}
            <div>
                <p class="micro" style="margin:0 0 var(--s-1)">
                    {{ Text::get('character.waiting.back_in', ['when' => $until->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE])]) }}
                </p>
                <div class="progress"><div class="progress__bar" data-waiting-bar style="--v:0"></div></div>
            </div>

            {{-- What put them here. Every line is optional, because the causes
                 are not alike: a readiness loss knows nothing about ICE and a
                 defeat in a run knows everything. What a cause cannot say, it
                 leaves out — the screen shows less rather than "unknown". --}}
            @if ($cause)
                <div>
                    <p style="margin:0;color:var(--ink-0)">
                        {{ Text::get('character.waiting.reason.'.$cause->reason) }}
                    </p>

                    <div class="row row--wrap" style="--gap:var(--s-3);margin-top:var(--s-2)">
                        @if ($cause->system)
                            <span class="micro">{{ Text::get('character.waiting.on_system', ['system' => $cause->system]) }}</span>
                        @endif

                        @if ($cause->address)
                            <span class="micro"><x-address :address="$cause->address" /></span>
                        @endif

                        @if ($cause->ice)
                            <span class="tag tag--crit">{{ Text::get('character.waiting.by_ice', ['ice' => $cause->ice]) }}</span>
                        @endif

                        @if ($cause->damage !== null && $cause->resource)
                            <span class="micro num">{{ Text::get('character.waiting.took', [
                                'damage' => $cause->damage,
                                'resource' => Text::get('character.waiting.resource.'.$cause->resource),
                            ]) }}</span>
                        @endif
                    </div>
                </div>

                {{-- WHAT IT ACTUALLY DID, and this is the part that cost
                     something. `effects` is App\Cyberspace\DefeatResolver's
                     own account — points taken, modules burned, creds gone —
                     which is why it is shown in preference to the bare tag
                     names: "Flatline" is a word and "2 points of your ceiling,
                     gone for good" is a fact. An empty list is said out loud
                     rather than left blank: "it carried nothing" is the good
                     news, and a blank space is not news at all. --}}
                <div>
                    <span class="micro">{{ Text::get('character.waiting.tags') }}</span>

                    @if ($cause->effects !== [])
                        <ul class="stack" style="--gap:var(--s-1);margin:var(--s-1) 0 0;padding:0;list-style:none">
                            @foreach ($cause->effects as $effect)
                                <li class="micro" style="color:var(--ink-0)">{{ $effect }}</li>
                            @endforeach
                        </ul>
                    @elseif ($cause->tags === [])
                        <p class="micro" style="color:var(--ink-2);margin:var(--s-1) 0 0">
                            {{ Text::get('character.waiting.no_tags') }}
                        </p>
                    @else
                        <div class="row row--wrap" style="--gap:var(--s-2);margin-top:var(--s-1)">
                            @foreach ($cause->tags as $tag)
                                <span class="tag tag--crit">{{ DefeatTag::tryFrom($tag)?->value ?? $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="panel__ft">
        {{-- Not a button that ends the wait: the middleware does that on the
             next request, which is why this is a plain reload. Offering an
             action that only works once the clock has run would be offering a
             button that usually does nothing. --}}
        <a class="btn btn--sm cut" href="{{ route('waiting.show') }}">{{ Text::get('character.waiting.up') }}</a>
        <div class="spacer"></div>
        <span class="micro" style="color:var(--ink-2)">{{ Text::get($cause?->reason === 'collapse' ? 'character.waiting.up_hint_collapse' : 'character.waiting.up_hint') }}</span>
    </div>
</div>

@push('scripts')
<script>
/* The bar fills over the minute the server already decided on, and the page
   reloads itself when it is done. The CLOCK is the column and not this — if a
   browser sleeps through the minute, the reload finds the middleware has
   already let them up. */
(function () {
    const bar = document.querySelector('[data-waiting-bar]');
    if (!bar) return;

    const until = {{ $until->getTimestamp() }} * 1000;
    const started = Date.now();
    const total = Math.max(1, until - started);

    const tick = () => {
        const done = Math.min(1, (Date.now() - started) / total);
        bar.style.setProperty('--v', done);
        if (done >= 1) { window.location.reload(); return; }
        requestAnimationFrame(tick);
    };

    tick();
})();
</script>
@endpush

@endsection
