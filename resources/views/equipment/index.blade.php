@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('equipment.title'))

@section('content')
    <h1>{{ Text::get('character.title') }}</h1>

    <x-character-tabs current="equipment" />

    <div class="tabpanel">
        <p class="micro" style="margin:0 0 var(--s-4);max-width:70ch">{{ Text::get('equipment.lede') }}</p>

        <div class="bodymap" style="margin-top:var(--s-5)">
            <x-equipment-map :regions="$regions" />

            <div>
                @foreach ($regions as $region)
                    @php($used = count($region['rows']))

                    <section class="region">
                        <div class="region__hd">
                            <h2 style="font-size:var(--fs-md);margin:0">{{ $region['label'] }}</h2>
                            @if ($region['hint'])
                                <span class="micro" style="color:var(--ink-3)">{{ $region['hint'] }}</span>
                            @endif
                            <span class="micro" style="color:var(--ink-2)">
                                {{ Text::get('implants.capacity', ['used' => $used, 'total' => $region['capacity']]) }}
                            </span>
                        </div>

                        {{-- One line per slot, filled or not: how many a place holds is a fact about the body, so
                             an empty one is drawn instead of implied. A filled one carries the button that takes
                             it off again, on the route that put it there. --}}
                        <div class="region__slots stack" style="--gap:var(--s-2)">
                            @foreach ($region['rows'] as $row)
                                <div class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                                    <span class="tag tag--info">{{ $row->item?->displayName() }}</span>
                                    @if ($row->quantity > 1)
                                        <span class="micro num" style="color:var(--ink-3)">×{{ $row->quantity }}</span>
                                    @endif
                                    <form method="POST" action="{{ route($region['route'], $row) }}">
                                        @csrf
                                        <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('equipment.take_off') }}</button>
                                    </form>
                                </div>
                            @endforeach
                            @for ($i = $used; $i < $region['capacity']; $i++)
                                <span class="tag" style="color:var(--ink-3)">{{ Text::get($region['empty']) }}</span>
                            @endfor
                        </div>
                    </section>
                @endforeach

                <p class="micro" style="color:var(--ink-3);margin-top:var(--s-4)">
                    {{ Text::get('equipment.how') }}
                    <a href="{{ route('inventory.index') }}">{{ Text::get('equipment.to_stash') }}</a>
                </p>
            </div>
        </div>
    </div>
@endsection
