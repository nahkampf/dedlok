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

    {{-- Tied to the LOGO's box rather than to the content column, so the two
         read as one block: the wordmark is 560px at most and centred, and this
         sits on its right edge. --}}
    <div style="width:min(560px,100%);margin:calc(var(--s-5) * -1) auto var(--s-5);text-align:right">
        {{-- The version is the way back into the patch notes, once there are
             any to read. A link-styled button: it opens a dialog, it goes
             nowhere, so an <a href> would be a lie. --}}
        @if ($patchNotes === [])
            <span class="micro" style="color:var(--ink-3)">
                {{ Text::get('home.version', ['version' => config('version.app')]) }}
            </span>
        @else
            <button type="button" class="link micro" data-open="#patch-notes"
                    title="{{ Text::get('home.patch_notes.open') }}">
                {{ Text::get('home.version', ['version' => config('version.app')]) }}
            </button>
        @endif

        {{-- Under the version, the same size and edge: dark grey, the figures green. --}}
        <div class="micro" style="color:var(--ink-3)">
            {{ Text::get('home.players_total') }} <span style="color:var(--ok)">{{ number_format($players['total']) }}</span>
        </div>
        <div class="micro" style="color:var(--ink-3)">
            {{ Text::get('home.players_active') }} <span style="color:var(--ok)">{{ number_format($players['active']) }}</span>
        </div>
        <a class="link micro" href="{{ route('supporters.index') }}">{{ Text::get('supporters.title') }}</a>
    </div>

    @if ($patchNotes !== [])
        <x-patch-notes id="patch-notes" :notes="$patchNotes" :title="Text::get('home.patch_notes.all')" />
    @endif

    @if ($unseenNotes !== [])
        <x-patch-notes id="patch-notes-new" :notes="$unseenNotes" :title="Text::get('home.patch_notes.new')" unseen />
    @endif

    {{-- An ordinary link, not the vendor's widget. The widget is a
         third-party script that draws its own button with its own radius and
         its own magenta, and rule 3 is that radius is 0-3px and character
         comes from the notched corner. Same destination, nothing leaves the
         origin, and it works with JavaScript off.

         EMBER, and the only ember on the page — an outline that fills on
         hover, which is the form rule 2 allows it. It is the one thing here
         asking for something rather than telling you something. --}}
    <p style="text-align:center;margin:0 0 var(--s-5)">
        <a class="btn btn--sm btn--danger cut" href="https://ko-fi.com/dedlokgame"
           target="_blank" rel="noopener">{{ Text::get('home.support') }}</a>
    </p>

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
