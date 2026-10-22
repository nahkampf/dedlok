@use('App\Support\Text')
@use('Carbon\CarbonInterval')
@extends('layouts.app')
@section('title', Text::get('safehouse.title'))

@section('content')
    <h1>{{ $home->title }}</h1>
    <p class="muted" style="margin:var(--s-2) 0 0">{{ Text::get('safehouse.where', ['district' => $home->district->name]) }}</p>
    @if ($home->description)
        <p class="lede" style="margin-top:var(--s-3);max-width:70ch">{{ $home->description }}</p>
    @endif

    <div class="row row--wrap" style="--gap:var(--s-2);margin-top:var(--s-3)">
        <span class="tag">{{ $home->rent > 0 ? Text::get('safehouse.rent', ['rent' => number_format($home->rent)]) : Text::get('safehouse.free') }}</span>
        <span class="tag">{{ Text::get('safehouse.rest_rate', ['rate' => rtrim(rtrim(number_format($home->rest_rate, 2), '0'), '.')]) }}</span>
    </div>
    @if ($paysRent)
        <p class="micro" style="margin-top:var(--s-2);color:var(--ink-3)">
            {{ Text::get('safehouse.rent_next', [
                'rent' => number_format($home->rent),
                'time' => CarbonInterval::seconds(max(0, (int) now()->diffInSeconds($character->rent_due_at, false)))->cascade()->forHumans(['parts' => 2, 'short' => true]),
            ]) }}
        </p>
    @endif

    <div class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @foreach ($waiting as $placeId => $rows)
            @php($place = $places[$placeId])
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('safehouse.storage.waiting', ['place' => $place->title.' ['.$place->district->name.']']) }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-2)">
                    @foreach ($rows as $row)
                        <span>{{ $row->item->name }} <span class="num">×{{ $row->quantity }}</span></span>
                    @endforeach
                    <p class="micro" style="margin:0;color:var(--ink-3)">{{ Text::get('safehouse.storage.waiting_hint', ['time' => $rows->first()->storage_expires_at->format('Y-m-d H:i').' UTC']) }}</p>
                    <form method="POST" action="{{ route('safehouse.collect', $placeId) }}">
                        @csrf
                        <button type="submit" class="btn cut">{{ Text::get('safehouse.storage.collect') }}</button>
                    </form>
                </div>
            </div>
        @endforeach

        @if (! $here)
            <p class="callout" style="margin:0">
                {{ Text::get('safehouse.away', ['district' => $home->district->name]) }}
                <a href="{{ route('city.district', $home->district) }}">{{ $home->district->name }}</a>
            </p>
        @else
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('safehouse.actions_heading') }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-3)">
                    <img class="banner-img" src="{{ \App\Support\Asset::url('images/locations/safehouse.png') }}" alt="">
                    @forelse ($rows as $row)
                        @php($action = $row['action'])
                        {{-- A table of rows: the button on the left, what it does on the right. Wraps under the button on a phone. --}}
                        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                            <div style="flex:0 0 min(22ch,100%)">
                                @if ($session === $action->value)
                                    <form method="POST" action="{{ route('safehouse.rest.stop') }}">
                                        @csrf
                                        <button type="submit" class="btn cut">{{ Text::get($action === \App\Safehouses\Action::Rest ? 'safehouse.stop_resting' : 'safehouse.stop.'.$action->value) }}</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('safehouse.act', $action->value) }}">
                                        @csrf
                                        <button type="submit" class="btn cut" @disabled($session !== null || $row['cooldown'] !== null || ! $row['useful'] || ($action === \App\Safehouses\Action::Rest && $wired))>{{ $action->label() }}</button>
                                    </form>
                                @endif
                            </div>
                            <div class="stack" style="--gap:2px;flex:1 1 min(24ch,100%)">
                                <span>{{ Text::get('safehouse.blurb.'.$action->value) }}</span>
                                @if ($session === $action->value)
                                    <span class="micro" style="color:var(--ink-3)">{{ Text::get($action === \App\Safehouses\Action::Rest ? 'safehouse.resting' : 'safehouse.doing.'.$action->value) }}</span>
                                @else
                                    @if ($action === \App\Safehouses\Action::Rest && $wired)
                                        <span class="micro" style="color:var(--ink-3)">{{ \App\Safehouses\Refusal::Wired->message() }}</span>
                                    @endif
                                    @if ($action === \App\Safehouses\Action::Simstim)
                                        <span class="micro" style="color:var(--ink-3)">{{ Text::get('safehouse.simstim_left', ['time' => CarbonInterval::seconds($simstimLeft)->cascade()->forHumans(['parts' => 2, 'short' => true])]) }}</span>
                                    @endif
                                    @if ($action === \App\Safehouses\Action::Eat)
                                        <span class="micro" style="color:var(--ink-3)">{{ Text::get('safehouse.meal', ['fullness' => rtrim(rtrim(number_format($home->meal_fullness, 2), '0'), '.'), 'fatigue' => rtrim(rtrim(number_format($home->meal_fatigue, 2), '0'), '.')]) }}</span>
                                    @endif
                                    @if ($row['cooldown'] !== null)
                                        <span class="micro" style="color:var(--ink-3)">{{ Text::get('safehouse.ready_in', ['time' => CarbonInterval::seconds(max(1, (int) now()->diffInSeconds($row['cooldown'], false)))->cascade()->forHumans(['parts' => 2, 'short' => true])]) }}</span>
                                    @endif
                                @endif
                                {{-- How near the next point: the meter's 0 to 100, from `HomeActions::gainProgress()`. --}}
                                @if (in_array($action->value, ['workout', 'study'], true))
                                    @php($progress = (float) $character->{$action->value.'_progress'})
                                    <div class="meter meter--sm" style="--v:{{ round(min(100, max(0, $progress)) / 100, 3) }};--fill:var(--ok);max-width:min(96px,100%)"
                                         data-tip="{{ Text::get('safehouse.progress', ['percent' => (int) floor($progress)]) }}" tabindex="0" role="img"
                                         aria-label="{{ Text::get('safehouse.progress', ['percent' => (int) floor($progress)]) }}"><div class="meter__fill"></div></div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('safehouse.none_offered') }}</p>
                    @endforelse
                </div>
            </div>

            @if ($home->storage_slots > 0)
                <div class="panel">
                    <div class="panel__hd">
                        <span class="micro">{{ Text::get('safehouse.storage.heading') }}</span>
                        <div class="spacer"></div>
                        <span class="tag">{{ $slotsUsed }} / {{ $home->storage_slots }}</span>
                    </div>
                    <div class="panel__bd stack" style="--gap:var(--s-3)">
                        <p class="micro" style="margin:0;color:var(--ink-3)">{{ Text::get('safehouse.storage.slots', ['used' => $slotsUsed, 'max' => $home->storage_slots]) }}</p>
                        @forelse ($storedRows as $row)
                            <form method="POST" action="{{ route('safehouse.take', $row) }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                                @csrf
                                <span>{{ $row->item->name }} <span class="num">×{{ $row->quantity }}</span></span>
                                @if ($row->quantity > 1)
                                    <input class="input num" type="number" name="quantity" min="1" max="{{ $row->quantity }}" value="{{ $row->quantity }}" style="width:min(6ch,100%)">
                                @endif
                                <button type="submit" class="btn btn--sm cut">{{ Text::get('safehouse.storage.take') }}</button>
                            </form>
                        @empty
                            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('safehouse.storage.empty') }}</p>
                        @endforelse

                        @if ($stash->isNotEmpty())
                            <details>
                            <summary class="micro" style="cursor:pointer">{{ Text::get('safehouse.storage.carrying') }} ({{ $stash->count() }})</summary>
                            <div class="stack" style="--gap:var(--s-3);margin-top:var(--s-3)">
                            @foreach ($stash as $row)
                                <form method="POST" action="{{ route('safehouse.store', $row) }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                                    @csrf
                                    <span>{{ $row->item->name }} <span class="num">×{{ $row->quantity }}</span></span>
                                    @if ($row->quantity > 1)
                                        <input class="input num" type="number" name="quantity" min="1" max="{{ $row->quantity }}" value="{{ $row->quantity }}" style="width:min(6ch,100%)">
                                    @endif
                                    <button type="submit" class="btn btn--sm cut">{{ Text::get('safehouse.storage.store') }}</button>
                                </form>
                            @endforeach
                            </div>
                            </details>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    </div>
@endsection
