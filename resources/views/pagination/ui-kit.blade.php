{{--
    The paginator, in the kit's .pager.

    Laravel's stock views are Tailwind and Bootstrap; this project has neither
    and no build step, so the markup is ours. Links rather than buttons,
    because a page IS a URL — the gallery demo uses buttons only because it has
    nowhere to go.
--}}
@if ($paginator->hasPages())
    <nav class="pager" role="navigation">
        @if ($paginator->onFirstPage())
            <span class="pager__item" aria-disabled="true">‹</span>
        @else
            <a class="pager__item" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous">‹</a>
        @endif

        @foreach ($elements ?? [] as $element)
            @if (is_string($element))
                <span class="dim">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pager__item" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pager__item" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="pager__item" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next">›</a>
        @else
            <span class="pager__item" aria-disabled="true">›</span>
        @endif
    </nav>
@endif
