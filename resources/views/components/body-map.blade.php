@use('App\Implants\BodyRegion')
@use('App\Support\Asset')
@use('App\Support\Text')

{{--
    A wireframe body, with each implant region marked on it.

    The same device as <x-deck-board>: a picture of the thing, with the places
    something goes marked on it and a leader drawn to each. The figure is
    public/images/body.gif (403×521), laid across the whole viewBox; the markers
    and labels are SVG over it, so they theme with the kit.

    The viewBox is 0 0 200 260 and every coordinate below is inside it, so the
    whole thing scales with its container and never needs a media query of its
    own. One viewBox unit is about two pixels of the picture.
--}}
@props(['body'])

<svg class="bodymap__figure" viewBox="0 0 200 260" role="img"
     aria-label="{{ Text::get('implants.title') }}">

    <image class="bodymap__photo" href="{{ Asset::url('images/body.gif') }}"
           x="0" y="0" width="200" height="260" />

    @php
        // Where each region's marker sits on the figure, and where its label
        // hangs. Percent-free because the viewBox already fixes the scale.
        $places = [
            'head'  => ['x' => 101, 'y' => 28,  'lx' => 12,  'ly' => 20,  'anchor' => 'start'],
            'spine' => ['x' => 101, 'y' => 65,  'lx' => 188, 'ly' => 40,  'anchor' => 'end'],
            'body'  => ['x' => 101, 'y' => 100, 'lx' => 12,  'ly' => 150, 'anchor' => 'start'],
            'arms'  => ['x' => 164, 'y' => 99,  'lx' => 188, 'ly' => 150, 'anchor' => 'end'],
            'legs'  => ['x' => 80,  'y' => 189, 'lx' => 12,  'ly' => 235, 'anchor' => 'start'],
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
