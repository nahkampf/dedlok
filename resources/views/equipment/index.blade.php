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
                             it off again, on the route that put it there. Every line can open the Equip dialog
                             for the place: on a filled one it swaps, on the belt it takes the next free slot. --}}
                        <div class="region__slots stack" style="--gap:var(--s-2)">
                            @foreach ($region['rows'] as $row)
                                <div class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                                    <span class="tag tag--info">{{ $row->item?->displayName() }}</span>
                                    @if ($row->quantity > 1)
                                        <span class="micro num" style="color:var(--ink-3)">×{{ $row->quantity }}</span>
                                    @endif
                                    <div class="spacer"></div>
                                    <button type="button" class="btn btn--sm cut" data-open="#equip-{{ $region['key'] }}" aria-haspopup="dialog">{{ Text::get('equipment.equip') }}</button>
                                    <form method="POST" action="{{ route($region['route'], $row) }}">
                                        @csrf
                                        <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('equipment.take_off') }}</button>
                                    </form>
                                </div>
                            @endforeach
                            @for ($i = $used; $i < $region['capacity']; $i++)
                                <div class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                                    <span class="tag" style="color:var(--ink-3);flex:1 1 auto;justify-content:flex-start">{{ Text::get($region['empty']) }}</span>
                                    <button type="button" class="btn btn--sm cut" data-open="#equip-{{ $region['key'] }}" aria-haspopup="dialog">{{ Text::get('equipment.equip') }}</button>
                                </div>
                            @endfor
                        </div>

                        {{-- WHAT COULD GO HERE, as a table: everything loose in the stash that this place takes. The
                             button on each row posts to the route the stash card uses, so there is one writer
                             whichever screen asks. --}}
                        <dialog id="equip-{{ $region['key'] }}" class="modal">
                            <div class="panel__hd">
                                <span class="micro">{{ Text::get('equipment.equip_title', ['slot' => $region['label']]) }}</span>
                                <button type="button" class="btn btn--sm btn--ghost" data-close>{{ Text::get('ui.action.cancel') }}</button>
                            </div>

                            <div class="panel__bd">
                                @if ($region['candidates'] === [])
                                    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('equipment.candidates_none') }}</p>
                                @else
                                    <div class="table-wrap">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>{{ Text::get('equipment.columns.item') }}</th>
                                                    <th>{{ Text::get('equipment.columns.details') }}</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($region['candidates'] as $candidate)
                                                    <tr>
                                                        <td>{{ $candidate['row']->item->displayName() }}</td>
                                                        <td class="micro">{{ $candidate['details'] }}</td>
                                                        <td>
                                                            <form method="POST" action="{{ route($region['route'], $candidate['row']) }}">
                                                                @csrf
                                                                <button type="submit" class="btn btn--sm btn--primary cut">{{ Text::get('equipment.equip') }}</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        </dialog>
                    </section>
                @endforeach

            </div>
        </div>
    </div>
@endsection
