@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('help.title'))

@section('content')
    <h1>{{ Text::get('help.title') }}</h1>

    {{-- Every group and topic has an id, so /help#cyberspace and /help#scanning both work: a topic's accordion opens when it is the target. --}}
    <nav class="row row--wrap" style="--gap:var(--s-3);margin-top:var(--s-4)" aria-label="{{ Text::get('help.contents') }}">
        @foreach ($help['groups'] as $group)
            <a class="btn btn--sm cut" href="#{{ $group['id'] }}">{{ $group['title'] }}</a>
        @endforeach
        <a class="btn btn--sm cut" href="#{{ $help['glossary']['id'] }}">{{ $help['glossary']['title'] }}</a>
    </nav>

    @foreach ($help['groups'] as $group)
        <section id="{{ $group['id'] }}" style="margin-top:var(--s-6);scroll-margin-top:var(--s-8)">
            <h2 class="h2">{{ $group['title'] }}</h2>
            <div class="accordion" style="margin-top:var(--s-3)">
                @foreach ($group['topics'] as $topic)
                    <details id="{{ $topic['id'] }}" style="scroll-margin-top:var(--s-8)">
                        <summary>{{ $topic['title'] }}</summary>
                        <div class="stack" style="--gap:var(--s-3)">
                            @foreach ($topic['body'] as $block)
                                @if (is_array($block))
                                    <ul class="stack" style="--gap:var(--s-1);margin:0;padding-left:var(--s-5)">
                                        @foreach ($block as $line)
                                            <li>{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p style="margin:0">{{ $block }}</p>
                                @endif
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        </section>
    @endforeach

    <section id="{{ $help['glossary']['id'] }}" style="margin-top:var(--s-6);scroll-margin-top:var(--s-8)">
        <h2 class="h2">{{ $help['glossary']['title'] }}</h2>
        <div class="panel" style="margin-top:var(--s-3)">
            <dl class="panel__bd stack" style="--gap:var(--s-2);margin:0">
                @foreach ($help['glossary']['terms'] as $term => $meaning)
                    <div class="row row--wrap" style="--gap:var(--s-3)">
                        <dt class="micro" style="flex:0 0 min(8rem,100%)">{{ $term }}</dt>
                        <dd style="margin:0;flex:1 1 14rem;min-width:0;font-size:var(--fs-sm)">{{ $meaning }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        // Open the accordion a link points at (or contains), then bring it into view.
        function openHelpTarget() {
            var el = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
            if (!el) return;
            var d = el.tagName === 'DETAILS' ? el : null;
            if (d) { d.open = true; d.scrollIntoView(); }
        }
        window.addEventListener('hashchange', openHelpTarget);
        openHelpTarget();
    </script>
@endpush
