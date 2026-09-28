@use('App\Support\Text')
@use('Illuminate\Support\Number')
@extends('layouts.app')
@section('title', Text::get('notifications.title'))

@section('content')
    <h1>{{ Text::get('notifications.title') }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:70ch">{{ Text::get('notifications.lede') }}</p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('notifications.inbox') }}</span>
            <div class="spacer"></div>

            {{-- Only while there is something to do. A button that is always
                 there and usually does nothing is a button nobody trusts. --}}
            @if ($unread > 0)
                <span class="micro num">{{ Text::plural('notifications.unread', $unread) }}</span>

                <form method="POST" action="{{ route('notifications.read') }}">
                    @csrf
                    <button type="submit" class="btn btn--sm btn--quiet cut">
                        {{ Text::get('notifications.mark_read') }}
                    </button>
                </form>
            @endif
        </div>

        <div class="panel__bd">
            @if (count($messages) === 0)
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('notifications.empty') }}</p>
            @else
                <div class="stack" style="--gap:2px">
                    @foreach ($messages as $message)
                        {{-- THE ROW IS A BUTTON, because opening a message
                             writes: it marks that one read and then goes
                             wherever its category leads. A category with
                             nowhere to lead — the street, until there is a
                             street — still reads, and simply stays put.

                             A read message is DIMMED rather than marked. The
                             kit's live dot is ember and pulses, and an inbox
                             with ten unread would be ten pulsing dots — which
                             is exactly what the accent is rationed against.
                             Unread is the ordinary weight; read is quieter. --}}
                        <form method="POST" action="{{ route('notifications.open', $message) }}">
                            @csrf
                            <button type="submit" class="item-row">
                                <span class="slot__icon" aria-hidden="true">{{ $message->category->glyph() }}</span>

                                <span class="stack" style="--gap:2px;min-width:0">
                                    <span class="item-row__name"@if ($message->read_at !== null) style="color:var(--ink-3)"@endif>{{ $message->body }}</span>

                                    <span class="row row--wrap micro" style="--gap:var(--s-2);align-items:center;color:var(--ink-3)">
                                        <span class="tag">{{ $message->category->label() }}</span>
                                        <span>{{ $message->created_at->diffForHumans() }}</span>
                                    </span>
                                </span>

                                @if ($message->read_at === null)
                                    <span class="item-row__meta micro">{{ Text::get('notifications.new') }}</span>
                                @endif
                            </button>
                        </form>

                        {{-- THE LIST UNDER THE SENTENCE, outside the row's
                             button because an address in it is a copy button
                             of its own, and a button inside a button is not
                             something a browser will draw. Snapshotted with
                             the body — a system named here may have moved by
                             now, and the line still says what it paid.

                             ponytail: drawn in the siphon report's shape, the
                             only sender with a list. A second sender with a
                             different shape makes this a per-category partial. --}}
                        @if ($message->details)
                            <div class="prose micro" style="margin:0 0 var(--s-2) var(--s-5);color:var(--ink-2)">
                                <ul>
                                    @foreach ($message->details as $line)
                                        <li>
                                            {{ $line['system'] ?? '' }}
                                            @if (! empty($line['address']))
                                                · <x-address :address="$line['address']" />
                                            @endif
                                            · {{ Text::get('notifications.details.grade', ['grade' => $line['grade'] ?? '?']) }}
                                            · <span class="num">{{ Number::format((int) ($line['creds'] ?? 0)) }} ¤</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
