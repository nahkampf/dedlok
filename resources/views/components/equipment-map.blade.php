@use('App\Support\Asset')
@use('App\Support\Text')

{{--
    The Equipment screen's wireframe body: the same figure and device as <x-body-map>, with this screen's seven
    places marked on it. The viewBox is 0 0 200 260 and the picture (403x521) is laid across all of it, so one unit
    is about two pixels of it. The figure's RIGHT hand is on the viewer's LEFT, which is why the primary weapon's
    marker is there.

    $regions is what EquipmentController::regions() builds; only `key`, `label`, `capacity` and the number of
    `rows` are read.
--}}
@props(['regions'])

<svg class="bodymap__figure" viewBox="0 0 200 260" role="img"
     aria-label="{{ Text::get('equipment.title') }}">

    <image class="bodymap__photo" href="{{ Asset::url('images/body.gif') }}"
           x="0" y="0" width="200" height="260" />

    @php
        // Marker (x, y) on the figure, and where the label hangs. Labels sit in two columns so none crosses another.
        $places = [
            'head'      => ['x' => 101, 'y' => 26,  'lx' => 12,  'ly' => 20,  'anchor' => 'start'],
            'torso'     => ['x' => 101, 'y' => 75,  'lx' => 188, 'ly' => 40,  'anchor' => 'end'],
            'arms'      => ['x' => 152, 'y' => 80,  'lx' => 188, 'ly' => 66,  'anchor' => 'end'],
            'belt'      => ['x' => 101, 'y' => 114, 'lx' => 12,  'ly' => 96,  'anchor' => 'start'],
            'primary'   => ['x' => 17,  'y' => 120, 'lx' => 12,  'ly' => 150, 'anchor' => 'start'],
            'secondary' => ['x' => 185, 'y' => 119, 'lx' => 188, 'ly' => 150, 'anchor' => 'end'],
            'legs'      => ['x' => 80,  'y' => 190, 'lx' => 12,  'ly' => 235, 'anchor' => 'start'],
        ];
    @endphp

    @foreach ($regions as $region)
        @php
            $place = $places[$region['key']];
            $used = count($region['rows']);
            $total = $region['capacity'];
            $state = $used === 0 ? '' : ($used >= $total ? ' bodymap__node--full' : ' bodymap__node--some');
        @endphp

        <line class="bodymap__wire"
              x1="{{ $place['anchor'] === 'start' ? $place['lx'] + 2 : $place['lx'] - 2 }}"
              y1="{{ $place['ly'] - 3 }}"
              x2="{{ $place['x'] }}" y2="{{ $place['y'] }}" />

        <rect class="bodymap__node{{ $state }}"
              x="{{ $place['x'] - 5 }}" y="{{ $place['y'] - 5 }}" width="10" height="10">
            <title>{{ $region['label'] }} — {{ Text::get('implants.capacity', ['used' => $used, 'total' => $total]) }}</title>
        </rect>

        <text class="bodymap__label" x="{{ $place['lx'] }}" y="{{ $place['ly'] }}"
              text-anchor="{{ $place['anchor'] }}">{{ $region['label'] }} {{ $used }}/{{ $total }}</text>
    @endforeach
</svg>
