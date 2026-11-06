@use('App\Support\Text')
@extends('layouts.app')
@section('title', $encounter->title)

@section('content')

{{-- THE ONLY SCREEN A PLAYER IN AN ENCOUNTER CAN REACH. EnsureEncounterIsResolved
     sends every other request here. The words are all on rows (the title, the node's
     body, each button); only the chrome is in strings.city.encounter. --}}
<p class="micro" style="margin:0;color:var(--ink-3)">{{ Text::get('city.encounter.heading') }}</p>
<h1 style="margin-top:var(--s-2)">{{ $encounter->title }}</h1>

<div class="stack" style="--gap:var(--s-5);margin-top:var(--s-4)">
    @if ($encounter->intro && empty($live->log))
        <p class="lede" style="margin:0;max-width:70ch">{{ $encounter->intro }}</p>
    @endif

    <div class="panel">
        <div class="panel__bd stack" style="--gap:var(--s-4)">
            <p style="margin:0;max-width:70ch;white-space:pre-line">{{ $node->body }}</p>

            @if ($node->ends)
                <form method="POST" action="{{ route('encounter.leave') }}">
                    @csrf
                    <button type="submit" class="btn cut">{{ Text::get('city.encounter.continue') }}</button>
                </form>
            @else
                <div class="stack" style="--gap:var(--s-2)">
                    @foreach ($choices as $row)
                        <form method="POST" action="{{ route('encounter.choose') }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                            @csrf
                            <input type="hidden" name="choice" value="{{ $row['choice']->id }}">
                            <button type="submit" class="btn cut" @disabled(! $row['affordable'])>{{ $row['choice']->label }}</button>
                            @if ($row['choice']->cost > 0)
                                <span class="micro" style="color:var(--ink-3)">{{ Text::get('city.encounter.cost', ['cost' => number_format($row['choice']->cost)]) }}</span>
                            @endif
                            @if ($row['choice']->isFight())
                                <span class="tag tag--crit">{{ Text::get('city.encounter.fight_tag') }}</span>
                            @endif
                            @if ($row['band'] !== null)
                                <span class="tag" title="{{ $row['percent'] }}%">{{ $row['band'] }}</span>
                            @endif
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- What has happened so far, oldest first, the way a transcript reads. --}}
    @if (! empty($live->log))
        <div class="panel panel--snug">
            <div class="panel__hd"><span class="micro">{{ Text::get('city.encounter.log_heading') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-2)">
                @foreach ($live->log as $entry)
                    <div>
                        @if ($entry['label'] !== '')
                            <p class="micro" style="margin:0;color:var(--ink-2)">{{ $entry['label'] }}</p>
                        @endif
                        @foreach ($entry['lines'] as $line)
                            <p class="micro" style="margin:0;color:var(--ink-3)">{{ $line }}</p>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
