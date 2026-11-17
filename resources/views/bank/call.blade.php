@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('bank.call.title'))

@section('content')
    <h1>{{ Text::get('bank.call.title') }}</h1>

    <div class="panel panel--snug" style="margin-top:var(--s-4)">
        <div class="panel__bd stack" style="--gap:var(--s-3)">
            <p style="margin:0;max-width:70ch">{{ Text::get('bank.call.body') }}</p>
            <div class="readout">
                <span class="readout__label">{{ Text::get('bank.call.owed') }}</span>
                <span class="readout__value num">¤ {{ number_format($loan->owed()) }}</span>
            </div>

            <div class="stack" style="--gap:var(--s-3)">
                <form method="POST" action="{{ route('bank.call.answer', 'pay') }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                    @csrf
                    <button type="submit" class="btn cut" @disabled($character->creds < $loan->owed())>{{ Text::get('bank.call.pay') }}</button>
                    @if ($character->creds < $loan->owed())<span class="micro">{{ Text::get('bank.call.cannot_pay') }}</span>@endif
                </form>
                <form method="POST" action="{{ route('bank.call.answer', 'settle') }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                    @csrf
                    <button type="submit" class="btn cut">{{ Text::get('bank.call.settle') }}</button>
                    <span class="micro">{{ Text::get('bank.call.settle_hint', ['share' => $share]) }}</span>
                </form>
                <form method="POST" action="{{ route('bank.call.answer', 'refuse') }}" class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                    @csrf
                    <button type="submit" class="btn cut">{{ Text::get('bank.call.refuse') }}</button>
                    <span class="micro">{{ Text::get('bank.call.refuse_hint', ['bounty' => number_format((int) ceil($loan->owed() * config('bank.bounty_percent') / 100))]) }}</span>
                </form>
            </div>
        </div>
    </div>
@endsection
