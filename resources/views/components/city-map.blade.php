@use('App\Support\Text')
@props([
    // list<array{key: string, label: string, x: int, y: int, href: ?string, here: bool}>
    'nodes',
    // list<array{0: string, 1: string}> — pairs of node keys, drawn as wires.
    'links' => [],
    'label',
])

{{-- The deck board's own classes, laid out the same way: percentage
     coordinates in a .frame, wires in one SVG behind. A node with an href is a
     link; one without is only a place on the map. --}}
@php($at = collect($nodes)->keyBy('key'))

<div class="board-wrap">
    <div class="frame scanlines brackets board" aria-label="{{ $label }}">
        <span class="frame__label">{{ $label }}</span>

        @if (count($nodes) === 0)
            <span class="frame__hud micro">{{ Text::get('city.map_empty') }}</span>
        @else
            <svg class="board__wires" viewBox="0 0 100 100" preserveAspectRatio="none"
                 aria-hidden="true" focusable="false">
                @foreach ($links as [$from, $to])
                    @if (isset($at[$from], $at[$to]))
                        <line class="board__wire"
                              x1="{{ $at[$from]['x'] }}" y1="{{ $at[$from]['y'] }}"
                              x2="{{ $at[$to]['x'] }}" y2="{{ $at[$to]['y'] }}"></line>
                    @endif
                @endforeach
            </svg>

            @foreach ($nodes as $node)
                <{{ $node['href'] ? 'a' : 'div' }}
                    @class(['board__node', 'board__node--here' => $node['here']])
                    @if ($node['href']) href="{{ $node['href'] }}" @endif
                    @if ($node['here']) aria-current="location" @endif
                    style="left:{{ $node['x'] }}%;top:{{ $node['y'] }}%">
                    <span class="board__box"><span class="board__name">{{ $node['label'] }}</span></span>
                </{{ $node['href'] ? 'a' : 'div' }}>
            @endforeach
        @endif
    </div>
</div>
