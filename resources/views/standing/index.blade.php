@use('App\Standing\Band')
@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('standing.title'))

@section('content')
    <h1>{{ Text::get('character.title') }}</h1>

    <x-character-tabs current="standing" />

    <div class="tabpanel stack" style="--gap:var(--s-5)">
        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('standing.heading') }}</span></div>
            <div class="panel__bd">
                @if ($books->isEmpty())
                    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('standing.empty') }}</p>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ Text::get('standing.entity') }}</th>
                                    <th>{{ Text::get('standing.band') }}</th>
                                    <th>{{ Text::get('standing.value') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($books as $book)
                                    @php($band = Band::for($book->standing))
                                    <tr>
                                        <td>{{ $book->entity?->name }}</td>
                                        <td style="color:{{ $band->inkToken() }}">{{ $band->label() }}</td>
                                        <td class="num">{{ $book->standing > 0 ? '+' : '−' }}{{ number_format(abs($book->standing), 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('standing.log') }}</span></div>
            <div class="panel__bd">
                @if ($entries->isEmpty())
                    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('standing.log_empty') }}</p>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ Text::get('wallet.when') }}</th>
                                    <th>{{ Text::get('standing.entity') }}</th>
                                    <th>{{ Text::get('wallet.what') }}</th>
                                    <th>{{ Text::get('wallet.change') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($entries as $entry)
                                    <tr>
                                        <td class="micro">{{ $entry->happened_at->diffForHumans() }}</td>
                                        <td>{{ $entry->entity?->name }}</td>
                                        <td>{{ $entry->reason->label() }}</td>
                                        <td class="num" style="color:var(--{{ $entry->delta > 0 ? 'ok' : 'crit' }})">{{ $entry->delta > 0 ? '+' : '−' }}{{ number_format(abs($entry->delta), 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
