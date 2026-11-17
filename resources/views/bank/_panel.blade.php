{{-- Kronos Capital's counter: the account, a deposit, a withdrawal and the last of the statement. Used by the City
     branch and by the Exchange desk; the routes come in with $bank. --}}
@use('App\Support\Text')
<div class="panel" style="margin-top:var(--s-4)">
    <div class="panel__hd"><span class="micro">{{ Text::get('bank.heading') }}</span></div>
    <div class="panel__bd stack" style="--gap:var(--s-3)">
        <div class="row row--wrap" style="--gap:var(--s-3)">
            <div class="readout">
                <span class="readout__label">{{ Text::get('bank.balance') }}</span>
                <span class="readout__value num">¤ {{ number_format($bank['balance']) }}</span>
            </div>
            <div class="readout">
                <span class="readout__label">{{ Text::get('bank.cash') }}</span>
                <span class="readout__value num">¤ {{ number_format((int) $character->creds) }}</span>
            </div>
        </div>

        @if ($bank['acts'] ?? true)
            <div class="row row--wrap" style="--gap:var(--s-3);align-items:flex-end">
                @foreach (['deposit', 'withdraw'] as $op)
                    <form method="POST" action="{{ $bank[$op] }}" class="row" style="--gap:var(--s-2);align-items:flex-end">
                        @csrf
                        <label class="field" style="margin:0">
                            <span class="field__label">{{ Text::get('bank.amount') }}</span>
                            <input class="input num" type="number" name="amount" min="1" step="1" required style="width:9rem">
                        </label>
                        <button type="submit" class="btn btn--sm cut">{{ Text::get('bank.'.$op) }}</button>
                    </form>
                @endforeach
            </div>
        @endif

        <span class="micro">{{ Text::get('bank.statement') }}</span>
        @if ($bank['statement']->isEmpty())
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('bank.no_entries') }}</p>
        @else
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                        @foreach ($bank['statement'] as $entry)
                            <tr>
                                <td class="micro">{{ $entry->created_at?->format('Y-m-d H:i') }}</td>
                                <td>{{ $entry->kind->label() }}</td>
                                <td class="num" style="color:var(--{{ $entry->amount > 0 ? 'ok' : 'ink-1' }})">{{ $entry->amount > 0 ? '+' : '−' }}{{ number_format(abs($entry->amount)) }}</td>
                                <td class="num">{{ number_format($entry->balance) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
