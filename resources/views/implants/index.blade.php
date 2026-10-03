@use('App\Implants\BodyRegion')
@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('implants.title'))

@section('content')
    <h1>{{ Text::get('character.title') }}</h1>

    <x-character-tabs current="implants" />

    <div class="tabpanel">
        <p class="micro" style="margin:0 0 var(--s-4);max-width:70ch">{{ Text::get('implants.lede') }}</p>
        <p style="margin:0 0 var(--s-4);max-width:70ch">
            {{ Text::get('city.surgery.only_here') }}
            <a href="{{ route('city.index') }}">{{ Text::get('city.surgery.go') }}</a>
        </p>

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

                    @if ($spare->isNotEmpty() && $installed->count() < $region->capacity())
                        <p class="micro" style="color:var(--ink-2);margin-top:var(--s-2)">
                            {{ Text::get('implants.carrying', ['count' => $spare->count()]) }}
                        </p>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
    </div>
@endsection
