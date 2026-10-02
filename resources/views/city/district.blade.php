@use('App\Support\Text')
@extends('layouts.app')
@section('title', $district->name)

@section('content')
    <p style="margin:0"><a href="{{ route('city.index') }}">{{ Text::get('city.back') }}</a></p>
    <h1 style="margin-top:var(--s-2)"><span aria-hidden="true" style="color:{{ $district->color->token() }}">&#9672;</span> {{ $district->name }}</h1>
    @if ($district->description)
        <p class="lede" style="margin-top:var(--s-3);max-width:70ch">{{ $district->description }}</p>
    @endif
    @if ($district->id === $character->district_id)
        <p style="margin-top:var(--s-3)"><span class="tag">{{ Text::get('city.here') }}</span></p>
    @endif

    <div class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        <x-city-map :label="Text::get('city.district_map')"
            :nodes="$locations->map(fn ($location) => [
                'key' => 'l'.$location->id,
                'label' => $location->name,
                'x' => $location->map_x,
                'y' => $location->map_y,
                'href' => null,
                'here' => false,
                'pin' => true,
                'hue' => $district->color->token(),
            ])->all()" />

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('city.locations') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                @forelse ($locations as $location)
                    <div class="stack" style="--gap:2px">
                        <span class="item-row__name">{{ $location->name }}</span>
                        @if ($location->description)
                            <span class="muted" style="font-size:var(--fs-sm)">{{ $location->description }}</span>
                        @endif
                    </div>
                @empty
                    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('city.no_locations') }}</p>
                @endforelse
            </div>
        </div>
    </div>
@endsection
