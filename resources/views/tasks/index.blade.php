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
                    <x-mission-card :mission="$mission" :active="true" :files="$files" :held="$heldAddresses" :actions="false" :desk="true" :abandon="true" :jump="true" />
                @endforeach
            </div>
        @endif

        @if ($recent->isNotEmpty())
            <h3 class="micro" style="margin:var(--s-3) 0 0">{{ Text::get('tasks.recent') }}</h3>
            <ul class="stack" style="--gap:var(--s-2);list-style:none;margin:0;padding:0">
                @foreach ($recent as $exchange)
                    <li class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                        <span class="item-row__name" style="min-width:0">{{ $exchange->system?->displayName() }}</span>
                        <span class="tag">{{ Text::get('tasks.grade', ['grade' => $exchange->grade]) }}</span>
                        <span class="micro"><x-address :address="$exchange->system?->address" />@if ($exchange->system) <x-jump :address="$exchange->system->address" />@endif</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- THE JOURNAL: notes (headings) the character has not finished, newest first, each with its lines newest first. A line
         that is done is struck through; a note that is done is not here. Written by encounters (App\Journal\Journal). --}}
    <section class="stack" style="--gap:var(--s-3);margin-top:var(--s-6)">
        <h2>{{ Text::get('tasks.journal') }}</h2>

        @if ($journal->isEmpty())
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('tasks.journal_none') }}</p>
        @else
            <ul class="stack" style="--gap:var(--s-4);list-style:none;margin:0;padding:0">
                @foreach ($journal as $note)
                    <li class="stack" style="--gap:var(--s-2)">
                        <h3 style="margin:0">{{ $note->title }}</h3>

                        @if ($note->entries->isNotEmpty())
                            <ul class="stack" style="--gap:var(--s-1);margin:0;padding-left:var(--s-5)">
                                @foreach ($note->entries as $entry)
                                    <li @class(['muted' => $entry->completed])>
                                        @if ($entry->completed)
                                            <s>{{ $entry->body }}</s>
                                        @else
                                            {{ $entry->body }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="stack" style="--gap:var(--s-2);margin-top:var(--s-6)">
        <h2>{{ Text::get('tasks.tasks') }}</h2>
        @forelse ($leads as $lead)
            @php([$heading, $text] = $lead->entry())
            <div class="stack" style="--gap:var(--s-2)">
                <h3 style="margin:0">{{ $heading }}</h3>
                <p style="margin:0">{{ $text }}</p>
                <form method="POST" action="{{ route('lead.forget', $lead) }}" class="row row--wrap" style="--gap:var(--s-3)">
                    @csrf
                    <button type="submit" class="btn btn--sm btn--ghost cut" data-confirm="{{ Text::get('ui.confirm') }}">{{ Text::get('safehouse.lead.forget') }}</button>
                </form>
            </div>
        @empty
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('tasks.not_yet') }}</p>
        @endforelse
    </section>
@endsection
