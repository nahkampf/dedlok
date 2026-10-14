@use('App\Support\Text')
@use('Carbon\CarbonInterval')
{{-- One district as the map's inspector shows it: hovered, the description and
     the known places; opened (a click or tap), the same plus the buttons, which
     [data-act] keeps out of the hover layer. Reads $district, $places, $quote. --}}
@php($here = $district->id === $character->district_id)
@php($span = fn (int $s): string => CarbonInterval::seconds($s)->cascade()->forHumans(['parts' => 2, 'short' => true]))

<div class="item-card" style="min-width:min(280px,100%)">
    <div class="item-card__hd stack" style="--gap:var(--s-2)">
        <span class="item-card__name">
            <span aria-hidden="true" style="color:{{ $district->color->token() }}">&#9672;</span> {{ $district->name }}
        </span>
        @if ($here)
            <span class="row row--wrap" style="--gap:var(--s-2)"><span class="tag">{{ Text::get('city.here') }}</span></span>
        @endif
    </div>

    <div class="stack" style="--gap:var(--s-3);padding:var(--s-3)">
        @if ($district->description)
            <p style="margin:0;font-size:var(--fs-sm)">{{ $district->description }}</p>
        @endif

        <div class="stack" style="--gap:var(--s-2)">
            <span class="micro">{{ Text::get('city.locations') }}</span>
            @forelse ($places as $place)
                <div class="stack" style="--gap:2px">
                    <span class="item-row__name">{{ $place->name }}</span>
                    @if ($place->features() !== [])
                        <span class="row row--wrap" style="--gap:var(--s-2)">
                            @foreach ($place->features() as $feature)
                                <span class="tag">{{ $feature->label() }}</span>
                            @endforeach
                        </span>
                    @endif
                </div>
            @empty
                <span class="muted" style="font-size:var(--fs-sm)">{{ Text::get('city.no_locations') }}</span>
            @endforelse
        </div>

        <div class="stack" style="--gap:var(--s-2)" data-act>
            @if ($activity)
                {{-- Something is under way: the map says so above, and there is nothing to start. --}}
            @elseif ($here && $exploreSeconds !== null)
                <form method="POST" action="{{ route('city.explore') }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                    @csrf
                    <button type="submit" class="btn cut">{{ Text::get('city.explore') }}</button>
                    <span class="micro" style="color:var(--ink-3)">{{ Text::get('city.takes', ['time' => $span($exploreSeconds)]) }}</span>
                </form>
            @elseif (is_array($quote))
                <form method="POST" action="{{ route('city.travel', $district) }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                    @csrf
                    <input type="hidden" name="mode" value="walk">
                    <button type="submit" class="btn cut">{{ Text::get('city.walk') }}</button>
                    <span class="micro" style="color:var(--ink-3)">{{ Text::get('city.takes', ['time' => $span($quote['walk'])]) }}</span>
                </form>
                <form method="POST" action="{{ route('city.travel', $district) }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                    @csrf
                    <input type="hidden" name="mode" value="pod">
                    <button type="submit" class="btn cut" @disabled($character->creds < $quote['fare'])>{{ Text::get('city.pod', ['fare' => number_format($quote['fare'])]) }}</button>
                    <span class="micro" style="color:var(--ink-3)">{{ Text::get('city.takes', ['time' => $span($quote['pod'])]) }}</span>
                </form>
            @elseif ($quote !== null)
                <span class="micro" style="color:var(--ink-3)">{{ $quote->message() }}</span>
            @endif

            <a class="btn btn--ghost btn--sm cut" href="{{ route('city.district', $district) }}">{{ Text::get('city.open_district') }}</a>
        </div>
    </div>
</div>
