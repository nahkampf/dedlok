@use('App\Support\Asset')
@use('App\Support\Text')
@extends('layouts.app')
@section('title', config('app.name'))

@section('content')
    {{-- One wide card over two. .grid--pair is two columns that become one
         below 640px, and a class rather than inline tracks because an inline
         track would beat the media query. --}}
    <div class="grid grid--pair">
        {{-- An accordion, closed to begin with. Its summary is NOTICE.md's own
             first heading and the start of what follows it, so a closed
             notice still says what it is; the teaser goes once it is open. --}}
        <details class="panel grid__wide">
            <summary class="panel__hd">
                <span class="stack" style="--gap:var(--s-2);flex:1;min-width:0">
                    <span class="card__title">{{ $title ?? Text::get('home.notice') }}</span>
                    @if ($teaser !== null)
                        <span class="panel__teaser">{{ $teaser }}</span>
                    @endif
                </span>
            </summary>

            @if ($notice !== null)
                {{-- Rendered from NOTICE.md with raw HTML stripped, which is
                     what makes printing it unescaped safe. --}}
                <div class="panel__bd prose">{!! $notice !!}</div>
            @endif
        </details>

        @foreach ($places as $place)
            <div class="card">
                <div class="card__hd">
                    <span class="card__title">{{ $place['label'] }}</span>
                </div>
                @if ($place['image'] !== null)
                    <div class="card__bd">
                        <img src="{{ Asset::url($place['image']) }}" alt="{{ $place['label'] }}"
                             style="display:block;width:min(300px,100%);height:auto">
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endsection
