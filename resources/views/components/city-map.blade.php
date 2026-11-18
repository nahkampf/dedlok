@use('App\Support\Text')
@props([
    // list<array{key: string, label: string, x: int, y: int, href: ?string, here: bool, hue?: ?string}>
    // `hue` is a CSS colour token such as `var(--jade)`: which district it is.
    // `glyph` is an HTML entity replacing the pin's diamond (U+25C7 is proved to render).
    // `pin` => true draws an icon with the name as a tooltip instead of a name box.
    'nodes',
    // list<array{0: string, 1: string}> — pairs of node keys, drawn as wires.
    'links' => [],
    'label',
    // A selector for a hidden field: the map becomes a drag editor (ui-kit.js's board editor) that writes every
    // node's position into it as JSON keyed by node key.
    'edit' => null,
    // A picture URL drawn behind the map (the city's backdrop); the boxes and wires get a 1px drop shadow to stand off it.
    'picture' => null,
    // array{from: string, to: string, remaining: int, total: int}: a blinking marker crossing the wire from one node key to
    // another over `remaining` of `total` seconds (ui-kit.js's [data-traveller]).
    'traveller' => null,
])

{{-- The deck board's own classes, laid out the same way: percentage
     coordinates in a .frame, wires in one SVG behind. A node with an href is a
     link; one without is only a place on the map. --}}
@php($at = collect($nodes)->keyBy('key'))

<div class="board-wrap">
    <div @class(['frame', 'scanlines', 'brackets', 'board', 'board--picture' => $picture]) @if ($picture) style="--picture:url('{{ $picture }}')" @endif @if ($edit) data-board-edit="{{ $edit }}" data-board-min="25" data-board-max="75" @endif role="group" aria-label="{{ $label }}">
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

            @if ($traveller && isset($at[$traveller['from']], $at[$traveller['to']]))
                <span class="board__traveller" data-traveller aria-hidden="true"
                      data-x1="{{ $at[$traveller['from']]['x'] }}" data-y1="{{ $at[$traveller['from']]['y'] }}"
                      data-x2="{{ $at[$traveller['to']]['x'] }}" data-y2="{{ $at[$traveller['to']]['y'] }}"
                      data-remaining="{{ $traveller['remaining'] }}" data-total="{{ $traveller['total'] }}"></span>
            @endif

            @foreach ($nodes as $node)
                @php($pin = ! empty($node['pin']))
                {{-- `inspect` names a <template>: hover floats it, a click opens
                     it as a modal (ui-kit.js's inspector), so the node is a
                     button and the card carries the way on. --}}
                @php($inspect = $node['inspect'] ?? null)
                @php($tag = $inspect ? 'button' : ($node['href'] ? 'a' : ($pin ? 'button' : 'div')))
                <{{ $tag }}
                    @class(['board__node', 'board__node--here' => $node['here']])
                    data-node="{{ $node['key'] }}"
                    @if ($edit) tabindex="0" @endif
                    @if ($inspect) type="button" data-inspect="{{ $inspect }}" aria-label="{{ $node['label'] }}" @endif
                    @if ($pin) @if ($tag === 'button') type="button" @endif data-tip="{{ $node['label'] }}" aria-label="{{ $node['label'] }}" @endif
                    @if ($node['href'] && ! $inspect) href="{{ $node['href'] }}" @endif
                    @if ($node['here']) aria-current="location" @endif
                    style="left:{{ $node['x'] }}%;top:{{ $node['y'] }}%@if (! empty($node['hue']));--hue:{{ $node['hue'] }}@endif">
                    <span class="board__box">
                        @if ($pin)
                            <span class="board__pin" aria-hidden="true">{!! $node['glyph'] ?? '&#9672;' !!}</span>
                        @else
                            <span class="board__name">@if ($node['here'])<span class="here-mark" aria-hidden="true">&#9670;</span> @endif{{ $node['label'] }}</span>
                        @endif
                    </span>
                </{{ $tag }}>
            @endforeach
        @endif
    </div>
    @if (collect($nodes)->contains(fn ($n) => $n['here'] && empty($n['pin'])))
        <p class="micro here-legend"><span class="here-mark" aria-hidden="true">&#9670;</span> {{ Text::get('city.you_are_here') }}</p>
    @endif
</div>
