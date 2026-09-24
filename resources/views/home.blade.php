@use('App\Support\Asset')
@use('App\Support\Text')
@extends('layouts.app')
@section('title', config('app.name'))

@section('content')
    {{-- The wordmark, above everything. It is the page's title in a picture,
         so the alt text is the name and nothing else.

         Four widths, and the browser picks: it is never drawn wider than 560
         CSS pixels, so 1120 is the retina copy and the top of the ladder.
         images/logo.png is the master and is not served — regenerate the four
         from it, transparency and all, with:

           for w in 320 560 840 1120; do convert logo.png -resize ${w}x \
             -strip -define png:compression-level=9 logo-${w}.png; done --}}
    <img src="{{ Asset::url('images/logo-560.png') }}" alt="{{ config('app.name') }}"
         srcset="{{ Asset::url('images/logo-320.png') }} 320w,
                 {{ Asset::url('images/logo-560.png') }} 560w,
                 {{ Asset::url('images/logo-840.png') }} 840w,
                 {{ Asset::url('images/logo-1120.png') }} 1120w"
         sizes="(max-width: 640px) 100vw, 560px"
         width="1120" height="448"
         style="display:block;width:min(560px,100%);height:auto;margin:0 auto var(--s-5)">

    <br>
    <script type='text/javascript' src='https://storage.ko-fi.com/cdn/widget/Widget_2.js'></script><script type='text/javascript'>kofiwidget2.init('Become a supporter', '#a8146f', 'P2P527GQ4Z');kofiwidget2.draw();</script> 
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
