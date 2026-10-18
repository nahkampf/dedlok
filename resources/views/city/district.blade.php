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

        {{-- Where you live. "Your safehouse" if it stands here, otherwise the ones to rent; renting gives up the old one. --}}
        @php($options = $safehouses->where('id', '!=', $home->id))
        @if ($here && $safehouses->isNotEmpty())
            @if ($home->district_id === $district->getKey())
                <p style="margin:0"><a class="btn cut" href="{{ route('safehouse.show') }}">{{ Text::get('safehouse.yours') }}</a></p>
            @endif
            @if ($options->isNotEmpty())
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('safehouse.get') }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-3)">
                    @php($needed = (int) config('safehouse.passes_needed'))
                    @foreach ($options as $option)
                        <div class="stack" style="--gap:2px">
                            <span class="item-row__name">{{ $option->title }}</span>
                            <span class="row row--wrap" style="--gap:var(--s-2)">
                                <span class="tag">{{ $option->rent > 0 ? Text::get('safehouse.rent', ['rent' => number_format($option->rent)]) : Text::get('safehouse.free') }}</span>
                                <span class="tag">{{ Text::get('safehouse.rest_rate', ['rate' => rtrim(rtrim(number_format($option->rest_rate, 2), '0'), '.')]) }}</span>
                            </span>
                            @if ($option->description)
                                <span class="muted" style="font-size:var(--fs-sm)">{{ $option->description }}</span>
                            @endif
                            @if ($passes < $needed && $option->slug !== config('safehouse.fallback'))
                                <span class="micro" style="color:var(--ink-3)">{{ Text::get('safehouse.familiarity', ['district' => $district->name, 'passes' => $passes, 'needed' => $needed]) }}</span>
                            @else
                                <form method="POST" action="{{ route('safehouse.rent', $option) }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                                    @csrf
                                    <button type="submit" class="btn cut" @disabled($character->creds < $option->rent)>{{ Text::get('safehouse.take') }}</button>
                                    <span class="micro" style="color:var(--ink-3)">{{ Text::get('safehouse.replaces') }}</span>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            @endif
        @endif

        @if ($here && $leads->isNotEmpty())
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('safehouse.lead.heading') }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-2)">
                    @foreach ($leads as $lead)
                        <a class="btn cut" href="{{ route('lead.show', $lead) }}">{{ $lead->name() }}</a>
                    @endforeach
                </div>
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
