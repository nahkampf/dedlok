{{-- Kronos Capital's loan desk, City branch only: take a loan, pay the instalment, pay it all off. --}}
@use('App\Support\Text')
@use('App\Bank\LoanState')
<div class="panel" style="margin-top:var(--s-4)">
    <div class="panel__hd"><span class="micro">{{ Text::get('bank.loan.heading') }}</span></div>
    <div class="panel__bd stack" style="--gap:var(--s-3)">
        @if ($loan['open'] === null)
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('bank.loan.none') }}</p>
            <span class="micro">{{ Text::get('bank.loan.limit', ['limit' => number_format($loan['limit']), 'min' => number_format($loan['min'])]) }}</span>
            @if ($loan['acts'] && $loan['limit'] >= $loan['min'])
                <form method="POST" action="{{ route('city.loan', [$slug, 'take']) }}" class="row" style="--gap:var(--s-2);align-items:flex-end">
                    @csrf
                    <label class="field" style="margin:0">
                        <span class="field__label">{{ Text::get('bank.amount') }}</span>
                        <input class="input num" type="number" name="amount" min="{{ $loan['min'] }}" max="{{ $loan['limit'] }}" step="1" required style="width:9rem">
                    </label>
                    <button type="submit" class="btn btn--sm cut">{{ Text::get('bank.loan.borrow') }}</button>
                </form>
            @endif
        @else
            @php($l = $loan['open'])
            <div class="row row--wrap" style="--gap:var(--s-3)">
                <div class="readout"><span class="readout__label">{{ Text::get('bank.loan.owed') }}</span><span class="readout__value num">¤ {{ number_format($l->owed()) }}</span></div>
                <div class="readout"><span class="readout__label">{{ Text::get('bank.loan.principal') }}</span><span class="readout__value num">¤ {{ number_format($l->principal) }}</span></div>
                <div class="readout"><span class="readout__label">{{ Text::get('bank.loan.interest') }}</span><span class="readout__value num">¤ {{ number_format($l->interest) }}</span></div>
                <div class="readout"><span class="readout__label">{{ Text::get('bank.loan.instalment') }}</span><span class="readout__value num">¤ {{ number_format($l->due()) }}</span></div>
            </div>
            @if ($l->state === LoanState::Defaulted)
                <p style="margin:0;color:var(--crit)">{{ Text::get('bank.loan.defaulted') }}</p>
            @elseif ($l->missed_at)
                <p style="margin:0;color:var(--crit)">{{ Text::get('bank.loan.missed', ['until' => $l->missed_at->copy()->addHours((int) config('bank.grace_hours'))->format('Y-m-d H:i')]) }}</p>
            @elseif ($l->next_due_at)
                <span class="micro">{{ Text::get('bank.loan.next_due', ['when' => $l->next_due_at->format('Y-m-d H:i')]) }}</span>
            @endif
            @if ($loan['acts'])
                <div class="row row--wrap" style="--gap:var(--s-3)">
                    @if ($l->state === LoanState::Active && $l->settlement === null)
                        @foreach (['account' => 'bank.loan.pay', 'wallet' => 'bank.loan.pay_wallet'] as $source => $label)
                            <form method="POST" action="{{ route('city.loan', [$slug, 'pay']) }}">@csrf<input type="hidden" name="source" value="{{ $source }}"><button type="submit" class="btn btn--sm cut" @disabled($l->missed_at === null && $l->next_due_at?->isFuture())>{{ Text::get($label) }}</button></form>
                        @endforeach
                    @endif
                    @foreach (['account' => 'bank.loan.payoff', 'wallet' => 'bank.loan.payoff_wallet'] as $source => $label)
                        <form method="POST" action="{{ route('city.loan', [$slug, 'payoff']) }}">@csrf<input type="hidden" name="source" value="{{ $source }}"><button type="submit" class="btn btn--sm cut">{{ Text::get($label) }}</button></form>
                    @endforeach
                </div>
                @if ($l->state === LoanState::Active && $l->settlement === null)
                    <span class="micro">{{ Text::get('bank.loan.payoff_fee', ['fee' => number_format($loan['fee'])]) }}</span>
                @endif
            @endif
        @endif
    </div>
</div>
