@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('wallet.title'))

@section('content')
    <h1>{{ Text::get('wallet.title') }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:70ch">{{ Text::get('wallet.lede') }}</p>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr));--gap:var(--s-4);margin-top:var(--s-5)">
        <div class="card">
            <div class="card__bd stack" style="--gap:var(--s-2)">
                <div class="readout">
                    <span class="readout__label">{{ Text::get('wallet.balance') }}</span>
                    <span class="readout__value num">¤ {{ number_format((int) $character->creds) }}</span>
                </div>
            </div>
        </div>

        <form class="panel" method="POST" action="{{ route('wallet.transfer') }}">
            @csrf
            <div class="panel__hd"><span class="micro">{{ Text::get('wallet.transfer') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                <label class="field" style="margin:0">
                    <span class="field__label">{{ Text::get('wallet.handle') }}</span>
                    <input class="input" type="text" name="handle" maxlength="100" required value="{{ old('handle') }}">
                    @error('handle') <span class="field__error">{{ $message }}</span> @enderror
                </label>
                <label class="field" style="margin:0">
                    <span class="field__label">{{ Text::get('wallet.amount') }}</span>
                    <input class="input num" type="number" name="amount" min="1" step="1" required value="{{ old('amount') }}">
                    @error('amount') <span class="field__error">{{ $message }}</span> @enderror
                </label>
                @error('transfer') <span class="field__error">{{ $message }}</span> @enderror
                <button type="submit" class="btn cut">{{ Text::get('wallet.send') }}</button>
            </div>
        </form>
    </div>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd"><span class="micro">{{ Text::get('wallet.ledger') }}</span></div>
        <div class="panel__bd">
            @if ($entries->isEmpty())
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('wallet.empty') }}</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ Text::get('wallet.when') }}</th>
                                <th>{{ Text::get('wallet.what') }}</th>
                                <th>{{ Text::get('wallet.change') }}</th>
                                <th>{{ Text::get('wallet.after') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr>
                                    <td class="micro">{{ $entry->created_at->diffForHumans() }}</td>
                                    <td>
                                        {{ $entry->reason->label() }}
                                        @if ($entry->note)
                                            <span class="micro" style="color:var(--ink-3)">· {{ $entry->note }}</span>
                                        @endif
                                    </td>
                                    <td class="num" style="color:var(--{{ $entry->amount > 0 ? 'ok' : 'ink-1' }})">{{ $entry->amount > 0 ? '+' : '−' }}{{ number_format(abs($entry->amount)) }}</td>
                                    <td class="num">{{ number_format($entry->balance) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($entries->hasPages())
            <div class="panel__ft">
                {{ $entries->links('pagination.ui-kit') }}
            </div>
        @endif
    </div>
@endsection
