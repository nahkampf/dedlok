@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('city.title'))

@section('content')
    <h1>{{ Text::get('city.title') }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:70ch">{{ Text::get('city.lede') }}</p>

    <div class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        <x-city-map :label="Text::get('city.map')"
            :links="collect($links)->map(fn ($pair) => ['d'.$pair[0], 'd'.$pair[1]])->all()"
            :nodes="$districts->map(fn ($district) => [
                'key' => 'd'.$district->id,
                'label' => $district->name,
                'x' => $district->map_x,
                'y' => $district->map_y,
                'href' => route('city.district', $district),
                'hue' => $district->color->token(),
                'here' => $district->id === $character->district_id,
            ])->all()" />

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('city.districts') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                @foreach ($districts as $district)
                    <a class="item-row" href="{{ route('city.district', $district) }}" style="--tier:{{ $district->color->token() }}">
                        <span class="slot__icon" aria-hidden="true" style="color:var(--tier)">&#9672;</span>
                        <span class="stack" style="--gap:2px;min-width:0">
                            <span class="item-row__name">{{ $district->name }}</span>
                            @if ($district->description)
                                <span class="muted" style="font-size:var(--fs-sm)">{{ $district->description }}</span>
                            @endif
                        </span>
                        @if ($district->id === $character->district_id)
                            <span class="item-row__meta tag">{{ Text::get('city.here') }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endsection
