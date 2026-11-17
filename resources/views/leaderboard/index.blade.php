@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('leaderboard.title'))

@section('content')
    <h1>{{ Text::get('character.title') }}</h1>

    <x-character-tabs current="leaderboard" />

    <div class="tabpanel">
        <p class="lede" style="margin:0 0 var(--s-4);max-width:70ch">{{ Text::get('leaderboard.lede') }}</p>

        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr));--gap:var(--s-4)">
            @foreach ($boards as $b)
                <div class="panel">
                    <div class="panel__hd"><span class="micro">{{ $b['board']->label() }}</span></div>
                    <div class="panel__bd stack" style="--gap:var(--s-3)">
                        @if ($b['rows']->isEmpty())
                            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('leaderboard.empty') }}</p>
                        @else
                            <div class="table-wrap">
                                <table class="table">
                                    <tbody>
                                        @foreach ($b['rows'] as $row)
                                            <tr @if ($row->character_id === $me->getKey()) aria-current="true" @endif>
                                                <td class="num">{{ $row->rank }}</td>
                                                <td><a href="{{ route('characters.show', $row->character) }}">{{ $row->character->handle }}</a></td>
                                                @if ($b['board']->showsFigures())
                                                    <td class="num">{{ number_format($row->value) }}</td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        <span class="micro">{{ $b['mine'] === null ? Text::get('leaderboard.unranked') : Text::get('leaderboard.your_rank', ['rank' => $b['mine']]) }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($writtenAt)
            <p class="micro" style="margin-top:var(--s-4);color:var(--ink-3)">{{ Text::get('leaderboard.written', ['when' => $writtenAt->diffForHumans()]) }}</p>
        @endif
    </div>
@endsection
