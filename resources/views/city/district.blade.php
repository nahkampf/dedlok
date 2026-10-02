@use('App\Support\Text')
@use('Carbon\CarbonInterval')
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
        @if ($activity)
            <x-city-activity :activity="$activity" :remaining="$remaining" :route="$route" :in-pass="$inPass" />
        @elseif ($here && $exploreSeconds !== null)
            <form method="POST" action="{{ route('city.explore') }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                @csrf
                <button type="submit" class="btn cut">{{ Text::get('city.explore') }}</button>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('city.takes', ['time' => CarbonInterval::seconds($exploreSeconds)->cascade()->forHumans(['parts' => 2, 'short' => true])]) }}</span>
            </form>
        @elseif (is_array($quote))
            <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                <form method="POST" action="{{ route('city.travel', $district) }}">
                    @csrf
                    <input type="hidden" name="mode" value="walk">
                    <button type="submit" class="btn cut">{{ Text::get('city.walk') }}</button>
                </form>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('city.takes', ['time' => CarbonInterval::seconds($quote['walk'])->cascade()->forHumans(['parts' => 2, 'short' => true])]) }}</span>

                <form method="POST" action="{{ route('city.travel', $district) }}">
                    @csrf
                    <input type="hidden" name="mode" value="pod">
                    <button type="submit" class="btn cut" @disabled($character->creds < $quote['fare'])>{{ Text::get('city.pod', ['fare' => number_format($quote['fare'])]) }}</button>
                </form>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('city.takes', ['time' => CarbonInterval::seconds($quote['pod'])->cascade()->forHumans(['parts' => 2, 'short' => true])]) }}</span>
            </div>
        @endif

        <x-city-map :label="Text::get('city.district_map')"
            :nodes="$locations->map(fn ($location) => [
                'key' => 'l'.$location->id,
                'label' => $location->name.($location->features() === [] ? '' : ' · '.implode(', ', array_map(fn ($f) => $f->label(), $location->features()))),
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
                        <a class="item-row__name" href="{{ route('city.place', $location->slug) }}">{{ $location->name }}</a>
                        @if ($location->features() !== [])
                            <span class="row row--wrap" style="--gap:var(--s-2)">
                                @foreach ($location->features() as $feature)
                                    <span class="tag">{{ $feature->label() }}</span>
                                @endforeach
                            </span>
                        @endif
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
