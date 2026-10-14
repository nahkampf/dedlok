@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('city.title'))

@section('content')
    <h1>{{ Text::get('city.title') }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:70ch">{{ Text::get('city.lede') }}</p>

    <div class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @if ($activity)
            <x-city-activity :activity="$activity" :remaining="$remaining" :route="$route" :in-pass="$inPass" />
        @endif

        <x-city-map :label="Text::get('city.map')"
            :links="collect($links)->map(fn ($pair) => ['d'.$pair[0], 'd'.$pair[1]])->all()"
            :nodes="$districts->map(fn ($district) => [
                'key' => 'd'.$district->id,
                'label' => $district->name,
                'x' => $district->map_x,
                'y' => $district->map_y,
                'href' => route('city.district', $district),
                'inspect' => '#district-'.$district->id,
                'hue' => $district->color->token(),
                'here' => $district->id === $character->district_id,
            ])->all()" />

        @foreach ($districts as $district)
            <template id="district-{{ $district->id }}">
                @include('city._district-card', ['district' => $district, 'places' => $locations[$district->id] ?? collect(), 'quote' => $quotes[$district->id] ?? null])
            </template>
        @endforeach
    </div>
@endsection
