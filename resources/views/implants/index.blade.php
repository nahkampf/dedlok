@use('App\Implants\BodyRegion')
@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('implants.title'))

@section('content')
    <h1>{{ Text::get('implants.title') }}</h1>
    <p class="micro" style="color:var(--ink-2)">{{ Text::get('implants.lede') }}</p>

    <div class="bodymap" style="margin-top:var(--s-5)">
        <x-body-map :body="$body" />

        <div>
            @foreach (BodyRegion::cases() as $region)
                @php
                    $installed = $body[$region->value];
                    $spare = $available->get($region->value, collect());
                @endphp

                <section class="region">
                    <div class="region__hd">
                        <h2 style="font-size:var(--fs-md);margin:0">{{ $region->label() }}</h2>
                        <span class="micro" style="color:var(--ink-2)">
                            {{ Text::get('implants.capacity', ['used' => $installed->count(), 'total' => $region->capacity()]) }}
                        </span>
                    </div>

                    {{-- One box per slot, filled or not: the region's capacity
                         is a fact about the body, so an empty slot is drawn
                         rather than merely implied. --}}
                    <div class="region__slots">
                        @foreach ($installed as $row)
                            <span class="tag tag--info">{{ $row->item?->displayName() }}</span>
                        @endforeach
                        @for ($i = $installed->count(); $i < $region->capacity(); $i++)
                            <span class="tag" style="color:var(--ink-3)">{{ Text::get('implants.empty_slot') }}</span>
                        @endfor
                    </div>

                    @if ($installed->isNotEmpty())
                        <div style="display:flex;flex-wrap:wrap;gap:var(--s-2);margin-top:var(--s-3)">
                            @foreach ($installed as $row)
                                <form method="POST" action="{{ route('implants.remove', $row) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--sm btn--ghost cut">
                                        {{ Text::get('implants.remove') }} — {{ $row->item?->displayName() }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @endif

                    @if ($spare->isNotEmpty() && $installed->count() < $region->capacity())
                        <div style="display:flex;flex-wrap:wrap;gap:var(--s-2);margin-top:var(--s-3)">
                            @foreach ($spare as $row)
                                <form method="POST" action="{{ route('implants.install', $row) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--sm cut">
                                        {{ Text::get('implants.install') }} — {{ $row->item?->displayName() }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @elseif ($installed->isEmpty())
                        <p class="micro" style="color:var(--ink-2);margin-top:var(--s-2)">
                            {{ Text::get('implants.none_to_install') }}
                        </p>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
@endsection
