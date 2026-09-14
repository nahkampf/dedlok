@use('App\Implants\BodyRegion')
@use('App\Support\Text')

{{--
    A Vitruvian figure, with each implant region boxed off it.

    The same device as <x-deck-board>: a picture of the thing, with the places
    something goes marked on it and a leader drawn to each. Drawn in SVG rather
    than shipped as an image so it themes with the kit, scales to any width and
    carries no asset — the figure is hairlines, like everything structural here.

    The viewBox is 0 0 200 260 and every coordinate below is inside it, so the
    whole thing scales with its container and never needs a media query of its
    own.
--}}
@props(['body'])

<svg class="bodymap__figure" viewBox="0 0 200 260" role="img"
     aria-label="{{ Text::get('implants.title') }}">

    {{-- Vitruvius' two frames: the circle and the square the figure is
         inscribed in. Decoration, and the reason the drawing reads as what it
         is rather than as a stick figure. --}}
    <circle class="bodymap__frame" cx="100" cy="132" r="86" />
    <rect class="bodymap__frame" x="30" y="62" width="140" height="140" />

    <g class="bodymap__ink" stroke-linecap="round">
        {{-- Head --}}
        <circle cx="100" cy="46" r="16" />
        {{-- Spine, neck to pelvis --}}
        <path d="M100 62 L100 150" />
        {{-- Shoulders and the level arms of the square pose --}}
        <path d="M62 82 L138 82" />
        {{-- Arms: level, and the raised pair that make the circle --}}
        <path d="M62 82 L34 104 M138 82 L166 104" />
        <path d="M66 78 L40 52 M134 78 L160 52" />
        {{-- Ribs and hips --}}
        <path d="M74 88 L74 132 M126 88 L126 132" />
        <path d="M78 150 L122 150" />
        {{-- Legs: together, and the parted pair --}}
        <path d="M92 150 L88 214 M108 150 L112 214" />
        <path d="M92 150 L60 200 M108 150 L140 200" />
    </g>

    @php
        // Where each region's marker sits on the figure, and where its label
        // hangs. Percent-free because the viewBox already fixes the scale.
        $places = [
            'head'  => ['x' => 100, 'y' => 46,  'lx' => 12,  'ly' => 30,  'anchor' => 'start'],
            'spine' => ['x' => 100, 'y' => 110, 'lx' => 188, 'ly' => 100, 'anchor' => 'end'],
            'body'  => ['x' => 100, 'y' => 138, 'lx' => 12,  'ly' => 152, 'anchor' => 'start'],
            'arms'  => ['x' => 150, 'y' => 93,  'lx' => 188, 'ly' => 66,  'anchor' => 'end'],
            'legs'  => ['x' => 100, 'y' => 196, 'lx' => 12,  'ly' => 224, 'anchor' => 'start'],
        ];
    @endphp

    @foreach (BodyRegion::cases() as $region)
        @php
            $place = $places[$region->value];
            $used = $body[$region->value]->count();
            $total = $region->capacity();
            $state = $used === 0 ? '' : ($used >= $total ? ' bodymap__node--full' : ' bodymap__node--some');
        @endphp

        {{-- The leader, from the label to the part of the body it names. --}}
        <line class="bodymap__wire"
              x1="{{ $place['anchor'] === 'start' ? $place['lx'] + 2 : $place['lx'] - 2 }}"
              y1="{{ $place['ly'] - 3 }}"
              x2="{{ $place['x'] }}" y2="{{ $place['y'] }}" />

        {{-- The marker. Hollow with room, filled when the region is full. --}}
        <rect class="bodymap__node{{ $state }}"
              x="{{ $place['x'] - 5 }}" y="{{ $place['y'] - 5 }}" width="10" height="10">
            <title>{{ $region->label() }} — {{ Text::get('implants.capacity', ['used' => $used, 'total' => $total]) }}</title>
        </rect>

        <text class="bodymap__label" x="{{ $place['lx'] }}" y="{{ $place['ly'] }}"
              text-anchor="{{ $place['anchor'] }}">{{ $region->label() }} {{ $used }}/{{ $total }}</text>
    @endforeach
</svg>
