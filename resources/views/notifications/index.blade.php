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

            {{-- Select-all and delete-selected. The ticks live in the rows and belong to this form
                 through their `form` attribute, so a row's own forms are never nested inside it. --}}
            @if (count($messages) > 0)
                <label class="check" style="margin-left:var(--s-3)">
                    <input type="checkbox" data-select-all="inbox-bulk">
                    <span class="check__box"></span><span class="micro">{{ Text::get('notifications.select_all') }}</span>
                </label>

                <form id="inbox-bulk" method="POST" action="{{ route('notifications.destroy_many') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn--sm btn--quiet cut">{{ Text::get('notifications.delete_selected') }}</button>
                </form>
            @endif
        </div>

        <div class="panel__bd">
            @if (count($messages) === 0)
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('notifications.empty') }}</p>
            @else
                {{-- AN ACCORDION, one <details> a message. The closed row is the TITLE
                     and nothing else a player has to read; opening it shows the sentence
                     and, in the siphon report's case, its list. Unfolding one READS it
                     (`data-mark-read`, in ui-kit.js) without leaving the page, which is
                     the same call the old row made when it navigated: arriving on the
                     screen still reads nothing.

                     A read message is DIMMED rather than marked. The kit's live dot is
                     ember and pulses, and an inbox with ten unread would be ten pulsing
                     dots. Unread is the ordinary weight; read is quieter. The marker is
                     ink and not the accordion's ember, for the same reason. --}}
                <div class="accordion accordion--quiet">
                    @foreach ($messages as $message)
                        <details data-mark-read="{{ route('notifications.mark', $message) }}" @if ($message->read_at !== null) data-read @endif>
                            <summary>
                                <label class="check" title="{{ Text::get('notifications.select_one') }}">
                                    <input type="checkbox" name="ids[]" value="{{ $message->id }}" form="inbox-bulk" data-select-row
                                           aria-label="{{ Text::get('notifications.select_one') }}">
                                    <span class="check__box"></span>
                                </label>
                                <span class="slot__icon" aria-hidden="true">{{ $message->category->glyph() }}</span>
                                <span class="item-row__name" data-message-title style="flex:1;min-width:0;@if ($message->read_at !== null)color:var(--ink-3)@endif">{{ $message->title ?? $message->category->label() }}</span>
                                <span class="micro" style="color:var(--ink-3)">{{ $message->created_at->diffForHumans() }}</span>
                                @if ($message->read_at === null)
                                    <span class="item-row__meta micro" data-message-new>{{ Text::get('notifications.new') }}</span>
                                @endif
                            </summary>

                            <div class="stack" style="--gap:var(--s-2)">
                                <p style="margin:0">{{ $message->body }}</p>

                                {{-- THE LIST UNDER THE SENTENCE. Snapshotted with the body: a
                                     system named here may have moved by now, and the line still
                                     says what it paid.

                                     ponytail: drawn in the siphon report's shape, the only
                                     sender with a list. A second sender with a different shape
                                     makes this a per-category partial. --}}
                                @if ($message->details)
                                    <div class="prose micro" style="color:var(--ink-2)">
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

                                {{-- Where the category leads. A POST and not a link: it writes
                                     (it reads the message), and it sits outside the summary
                                     because a button in a summary is not one a browser draws. --}}
                                <div class="row row--wrap" style="--gap:var(--s-2)">
                                <form method="POST" action="{{ route('notifications.open', $message) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--sm btn--quiet cut">
                                        {{ Text::get('notifications.go_to', ['place' => $message->category->label()]) }}
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('notifications.destroy', $message) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('notifications.delete') }}</button>
                                </form>
                                </div>
                            </div>
                        </details>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
