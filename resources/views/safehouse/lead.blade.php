@use('App\Support\Text')
@extends('layouts.app')
@section('title', $lead->name())

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('city.district', $lead->safehouse->district) }}">← {{ $lead->safehouse->district->name }}</a></li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ $lead->name() }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:70ch">
        {{ $cleared ? Text::get('safehouse.lead.cleared_intro') : ($known ? Text::get('safehouse.lead.known_intro', ['handle' => $lead->target->handle]) : Text::get('safehouse.lead.intro')) }}
    </p>

    <div class="stack" style="--gap:var(--s-4);margin-top:var(--s-5)">
        @if ($activity)
            <x-city-activity :activity="$activity" :remaining="$remaining" :route="[]" :in-pass="null" />
        @elseif ($lead->inside)
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('safehouse.lead.inside') }}</span></div>
                <div class="panel__bd row row--wrap" style="--gap:var(--s-3)">
                    <form method="POST" action="{{ route('lead.rob', $lead) }}">@csrf<button type="submit" class="btn cut">{{ Text::get('safehouse.lead.rob') }}</button></form>
                    <form method="POST" action="{{ route('lead.burn', $lead) }}">@csrf<button type="submit" class="btn btn--ghost cut">{{ Text::get('safehouse.lead.burn') }}</button></form>
                    <form method="POST" action="{{ route('lead.leave', $lead) }}">@csrf<button type="submit" class="btn btn--ghost cut">{{ Text::get('safehouse.lead.leave') }}</button></form>
                </div>
            </div>
        @else
            <div class="panel">
                <div class="panel__bd row row--wrap" style="--gap:var(--s-3)">
                    @unless ($known)
                        <form method="POST" action="{{ route('lead.watch', [$lead, 'stakeout']) }}">@csrf<button type="submit" class="btn cut">{{ Text::get('safehouse.lead.stake_out') }}</button></form>
                        <form method="POST" action="{{ route('lead.ask', $lead) }}">@csrf<button type="submit" class="btn cut">{{ Text::get('safehouse.lead.ask_around') }}</button></form>
                    @else
                        @unless ($cleared)
                            <form method="POST" action="{{ route('lead.watch', [$lead, 'scope-out']) }}">@csrf<button type="submit" class="btn cut">{{ Text::get('safehouse.lead.scope_out') }}</button></form>
                        @endunless
                        <form method="POST" action="{{ route('lead.break_in', $lead) }}">@csrf<button type="submit" class="btn cut">{{ Text::get('safehouse.lead.break_in') }}</button></form>
                    @endunless
                </div>
            </div>
        @endif
    </div>
@endsection
