@use('App\Support\Text')
{{-- The encounters as a tree: each under its parent (display only), by title. Only the title is indented, so the columns stay aligned. --}}
<ul style="list-style:none;margin:0;padding:0">
    @foreach ($nodes as $encounter)
        <li>
            <div style="{{ $cols }};border-bottom:1px solid var(--line-1)">
                <span style="padding-left:calc(var(--s-5) * {{ $depth }})">
                    @if ($depth > 0)<span class="muted" aria-hidden="true">↳ </span>@endif
                    <a href="{{ route('admin.encounters.edit', $encounter) }}">{{ $encounter->title }}</a>
                </span>
                <span class="muted">{{ $encounter->starts_on->label() }}</span>
                <span class="num">{{ $encounter->nodes_count }}</span>
                <span class="muted">
                    w{{ $encounter->weight }}
                    @if ($encounter->once) · {{ Text::get('admin.encounters.once_tag') }} @endif
                    @if ($encounter->retired) · {{ Text::get('admin.encounters.retired_tag') }} @endif
                </span>
                <a class="btn btn--sm btn--ghost cut" href="{{ route('admin.encounters.edit', $encounter) }}">{{ Text::get('admin.encounters.edit') }}</a>
            </div>
            @if ($byParent->has($encounter->id))
                @include('admin.encounters._tree', ['nodes' => $byParent->get($encounter->id), 'byParent' => $byParent, 'depth' => $depth + 1, 'cols' => $cols])
            @endif
        </li>
    @endforeach
</ul>
