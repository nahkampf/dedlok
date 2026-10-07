@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('tasks.title'))

@section('content')
    <h1>{{ Text::get('tasks.title') }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:70ch">{{ Text::get('tasks.lede') }}</p>

    {{-- MISSIONS: the exchange jobs taken and not yet finished. The same card the desk draws, without its buttons: a job is
         handed in at the desk it came from. --}}
    <section class="stack" style="--gap:var(--s-3);margin-top:var(--s-5)">
        <h2>{{ Text::get('tasks.missions') }}</h2>
        <p class="micro" style="color:var(--ink-3);margin:0;max-width:70ch">{{ Text::get('tasks.missions_hint') }}</p>

        @if ($missions->isEmpty())
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('tasks.missions_none') }}</p>
        @else
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr));--gap:var(--s-4)">
                @foreach ($missions as $mission)
                    <x-mission-card :mission="$mission" :active="true" :files="$files" :held="$heldAddresses" :actions="false" :desk="true" :abandon="true" />
                @endforeach
            </div>
        @endif
    </section>

    <section class="stack" style="--gap:var(--s-2);margin-top:var(--s-6)">
        <h2>{{ Text::get('tasks.journal') }}</h2>
        <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('tasks.not_yet') }}</p>
    </section>

    <section class="stack" style="--gap:var(--s-2);margin-top:var(--s-6)">
        <h2>{{ Text::get('tasks.tasks') }}</h2>
        <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('tasks.not_yet') }}</p>
    </section>
@endsection
