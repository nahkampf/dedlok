@use('App\Support\Text')
@use('App\Support\Qb')
@extends('layouts.app')
@section('title', Text::get('cyberspace.fortress.host.market'))

@section('content')
<a class="btn btn--quiet btn--sm" href="{{ route('cyberspace.index') }}">← {{ Text::get('cyberspace.fortress.host.back') }}</a>

<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <span class="micro">{{ $fortress->displayName() }} · {{ Text::get('cyberspace.fortress.host.market') }}</span>
        <div class="spacer"></div>
        <span class="micro num">{{ $fortress->market_tax }}%</span>
    </div>
    <div class="panel__bd stack" style="--gap:var(--s-2)">
        @unless ($open)
            <p class="muted" style="margin:0">{{ Text::get('cyberspace.fortress.host.market_closed_note') }}</p>
        @else
            @forelse ($listings as $listing)
                <div class="row row--wrap" style="justify-content:space-between;align-items:center">
                    <span>{{ $listing->label }} <span class="micro num">{{ Qb::format((float) $listing->size_qb) }} QB</span></span>
                    <span class="row" style="--gap:var(--s-2);align-items:center">
                        <span class="num">¤ {{ number_format((int) $listing->price) }}</span>
                        @if ($here)
                            <form method="POST" action="{{ route('fortress.market.buy', [$fortress, $listing]) }}">@csrf
                                <button class="btn btn--sm cut" type="submit" @disabled((int) $character->creds < (int) $listing->price)>{{ Text::get('cyberspace.fortress.host.buy') }}</button></form>
                        @endif
                    </span>
                </div>
            @empty
                <p class="muted" style="margin:0">{{ Text::get('cyberspace.fortress.host.nothing_for_sale') }}</p>
            @endforelse
        @endunless
    </div>
</div>
@endsection
