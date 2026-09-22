@use('App\Support\Text')
@use('Illuminate\Support\Number')
@extends('layouts.app')
@section('title', Text::get('training.title'))

@section('content')
    <h1>{{ Text::get('character.title') }}</h1>

    <x-character-tabs current="skills" />

    <div class="tabpanel">
        <p class="lede" style="margin:0 0 var(--s-4);max-width:70ch">{{ Text::get('training.lede') }}</p>

    {{-- Three panels, because they answer three questions and only the middle
         one changes minute to minute. Everything on them is handed over by
         SkillsController — a Blade file that could ask these questions would
         ask them once per row. --}}

    {{-- WHAT IS TRAINING. Open by default and first, because it is the only
         thing on this screen that is happening rather than merely true. --}}
    <details class="panel" open style="margin-top:var(--s-5)">
        <summary class="panel__hd">
            <span class="micro">{{ Text::get('training.now') }}</span>
            <div class="spacer"></div>
            @if ($training !== null)
                {{-- The kit's own live dot. Ember, and one per screen, which is
                     exactly what the accent is rationed for: at most one entry
                     trains at a time. --}}
                <span class="row" style="--gap:var(--s-2);align-items:center">
                    <span class="dot dot--live" aria-hidden="true"></span>
                    <span class="micro">{{ Text::get('training.live') }}</span>
                </span>
            @endif
        </summary>

        <div class="panel__bd stack" style="--gap:var(--s-3)">
            @if ($training === null)
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('training.idle') }}</p>
            @else
                @php($banked = Text::get('training.banked', [
                    'points' => Number::format($training['points']),
                    'ceiling' => Number::format($training['ceiling']),
                ]))

                <span class="row row--wrap" style="--gap:var(--s-2);align-items:baseline">
                    <span style="font-size:var(--fs-md)">{{ $training['skill']->title }}</span>
                    <span class="micro" style="color:var(--ink-3)">
                        {{ Text::get('training.toward', ['level' => $training['target']]) }}
                    </span>
                </span>

                {{-- Progress through the level being trained, never from zero:
                     a skill going from 2 to 3 is not two thirds of the way
                     there. --}}
                <div class="meter"
                     style="--v:{{ $training['ratio'] }};--fill:var(--ok);width:min(320px,100%)"
                     role="img"
                     aria-label="{{ $banked }}">
                    <div class="meter__fill"></div>
                </div>

                <span class="row row--wrap micro num" style="--gap:var(--s-3);color:var(--ink-3)">
                    <span>{{ $banked }}</span>

                    @if ($training['rate'] > 0)
                        <span>{{ Text::get('training.rate', ['rate' => number_format($training['rate'], 2)]) }}</span>
                        @if ($training['finishes'] !== null)
                            <span>{{ Text::get('training.finishes', ['when' => $training['finishes']->diffForHumans()]) }}</span>
                        @endif
                    @endif
                </span>

                {{-- Every governing attribute resolves to zero, so no amount of
                     waiting finishes this. TrainingDeposit calls the same state
                     a stall and stops rather than looping. --}}
                @if ($training['rate'] <= 0)
                    <div class="callout callout--warn">{{ Text::get('training.stalled') }}</div>
                @endif
            @endif
        </div>
    </details>

    {{-- THE QUEUE. An accordion, because it is a thing you come to change
         rather than something to watch. --}}
    <details class="panel" style="margin-top:var(--s-4)">
        <summary class="panel__hd">
            <span class="micro">{{ Text::get('training.queue') }}</span>
            <div class="spacer"></div>
            <span class="micro num">{{ count($queue) }}/{{ $limit }}</span>
        </summary>

        <div class="panel__bd stack" style="--gap:var(--s-4)">
            <p class="micro" style="color:var(--ink-3);margin:0">
                {{ Text::get('training.queue_hint', ['limit' => $limit]) }}
            </p>

            {{-- What the validator found by walking the queue forward from what
                 the character knows now. Warnings and never refusals: a queue
                 is ALLOWED to describe training that cannot happen yet, which
                 is what lets a skill sit behind the one that unlocks it. --}}
            @if ($problems !== [])
                <div class="callout callout--warn">
                    <span class="stack" style="--gap:var(--s-2);min-width:0">
                        <strong>{{ Text::get('training.problems') }}</strong>
                        @foreach ($problems as $problem)
                            <span class="micro">{{ $problem }}</span>
                        @endforeach
                    </span>
                </div>
            @endif

            @if (count($queue) === 0)
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('training.queue_empty') }}</p>
            @else
                <div class="stack" style="--gap:2px">
                    @foreach ($queue as $entry)
                        {{-- Three children exactly: .item-row is a grid of
                             32px / minmax(0,1fr) / auto, so a fourth would drop
                             to a row of its own and no spacer is needed to push
                             the last one over. --}}
                        <div class="item-row">
                            <span class="micro num" style="color:var(--ink-3)">
                                {{ Text::get('training.position', ['position' => $entry->position]) }}
                            </span>

                            <span class="stack" style="--gap:2px;min-width:0">
                                <span class="item-row__name">{{ $entry->skill?->title }}</span>
                                <span class="micro" style="color:var(--ink-3)">
                                    {{ Text::get('training.target', ['level' => $entry->target_level]) }}
                                </span>
                            </span>

                            {{-- No confirmation: nothing is destroyed. Progress
                                 lives on character_skills and an entry is only
                                 intent, so taking one out and putting it back
                                 costs nothing. --}}
                            <form method="POST" action="{{ route('training.destroy', $entry) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn--sm btn--quiet cut">
                                    {{ Text::get('training.remove') }}
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (count($queue) >= $limit)
                <p class="micro" style="color:var(--ink-3);margin:0">{{ Text::get('training.full') }}</p>
            @elseif ($queueable === [])
                <p class="micro" style="color:var(--ink-3);margin:0">
                    {{ Text::get('training.nothing_queueable', ['max' => $max]) }}
                </p>
            @else
                {{-- A skill and never a level: the next one up is the only entry
                     the queue could accept, so there is nothing to choose. --}}
                <form method="POST" action="{{ route('training.store') }}"
                      class="row row--wrap" style="--gap:var(--s-3);align-items:flex-end">
                    @csrf
                    <label class="field" style="flex:1 1 min(240px,100%);min-width:0">
                        <span class="micro">{{ Text::get('training.pick') }}</span>
                        <select name="skill" class="select">
                            <x-grouped-options :groups="$queueable" />
                        </select>
                    </label>

                    <button type="submit" class="btn btn--sm btn--go cut">{{ Text::get('training.add') }}</button>
                </form>

                {{-- THE SAME QUEUE, FILLED IN FROM THE OTHER END. Above is one
                     level of one skill; this is "I want Codebreaking III" and
                     the nine entries that reach it, in an order the player
                     would otherwise work out on paper.

                     Not a second filled button: `btn--go` is the one signal
                     button this screen is allowed, and two of them beside each
                     other would say neither. --}}
                @if ($plannable !== [])
                    <div class="stack" style="--gap:var(--s-2)">
                        <span class="micro">{{ Text::get('training.plan') }}</span>
                        <span class="micro" style="color:var(--ink-3)">
                            {{ Text::get('training.plan_hint', ['limit' => $limit]) }}
                        </span>
                    </div>

                    <form method="POST" action="{{ route('training.plan') }}"
                          class="row row--wrap" style="--gap:var(--s-3);align-items:flex-end">
                        @csrf
                        <label class="field" style="flex:1 1 min(240px,100%);min-width:0">
                            <span class="micro">{{ Text::get('training.plan_pick') }}</span>
                            <select name="skill" class="select">
                                <x-grouped-options :groups="$plannable" />
                            </select>
                        </label>

                        {{-- A level, because a route has a destination. The
                             queue itself still never takes one: what this
                             produces is presses of the button above. --}}
                        <label class="field" style="flex:0 1 min(140px,100%);min-width:0">
                            <span class="micro">{{ Text::get('training.plan_level') }}</span>
                            <select name="level" class="select">
                                @for ($level = 1; $level <= $max; $level++)
                                    <option value="{{ $level }}" @selected($level === $max)>
                                        {{ Text::get('training.plan_option', ['level' => $level]) }}
                                    </option>
                                @endfor
                            </select>
                        </label>

                        <button type="submit" class="btn btn--sm cut">{{ Text::get('training.plan_add') }}</button>
                    </form>
                @endif
            @endif
        </div>
    </details>

    {{-- WHAT THEY KNOW. Last, because it is the least urgent of the three and
         the longest. Only the rows they actually have: a skill nobody has
         injected is the skill EDITOR's business, not this screen's. --}}
    <details class="panel" open style="margin-top:var(--s-4)">
        <summary class="panel__hd">
            <span class="micro">{{ Text::get('training.mine') }}</span>
            <div class="spacer"></div>
            <span class="micro num">{{ Text::plural('training.count', count($skills)) }}</span>
        </summary>

        <div class="panel__bd">
            @if (count($skills) === 0)
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('training.none') }}</p>
            @else
                <div class="stack" style="--gap:2px">
                    @foreach ($skills as $skill)
                        @php($level = $levels[$skill->id]['level'] ?? 0)
                        @php($points = $levels[$skill->id]['points'] ?? 0)
                        @php($meterLabel = Text::get('training.level_meter', [
                            'level' => $level,
                            'max' => $max,
                            'points' => Number::format($points),
                        ]))

                        <div class="item-row">
                            <span class="slot__icon" aria-hidden="true">&#9670;</span>

                            <span class="stack" style="--gap:2px;min-width:0">
                                <span class="item-row__name">{{ $skill->title }}</span>

                                {{-- The level as a segmented meter. Inside the
                                     stack rather than in the third column
                                     because 140px of meter beside a name leaves
                                     about fifty for the name on a 320px
                                     phone. --}}
                                <div class="meter meter--seg"
                                     style="--segs:{{ $max }};--v:{{ $max > 0 ? $level / $max : 0 }};--fill:var(--ok);width:min(140px,100%);margin-block:2px"
                                     role="img"
                                     aria-label="{{ $meterLabel }}"
                                     title="{{ $meterLabel }}">
                                    <div class="meter__fill"></div>
                                </div>

                                <span class="micro" style="color:var(--ink-3)">
                                    {{ Text::get('training.governed_by', [
                                        'primary' => $skill->primary_attribute,
                                        'secondary' => $skill->secondary_attribute,
                                    ]) }}
                                </span>
                            </span>

                            <span class="item-row__meta num">{{ $level }}/{{ $max }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </details>
    </div>
@endsection
