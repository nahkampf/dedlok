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

            <a class="btn btn--sm cut" href="{{ route('messages.create') }}">{{ Text::get('notifications.compose.button') }}</a>

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
                                @php($link = $message->details['link'] ?? null)
                                @php($at = $link === null ? false : mb_strpos($message->body, $link['word']))
                                <p style="margin:0">
                                    @if ($at !== false)
                                        {{ mb_substr($message->body, 0, $at) }}<a href="{{ route($link['route']) }}">{{ $link['word'] }}</a>{{ mb_substr($message->body, $at + mb_strlen($link['word'])) }}
                                    @else
                                        {{ $message->body }}
                                    @endif
                                </p>

                                {{-- THE LIST UNDER THE SENTENCE. Snapshotted with the body: a
                                     system named here may have moved by now, and the line still
                                     says what it paid.

                                     Two shapes now: a finished read's `facts` and the siphon report's list of lines. A
                                     third makes this a per-key partial. --}}
                                {{-- A message a player wrote: who from, in the sender's own handle. Plain text, printed escaped. --}}
                                @if (! empty($message->details['from']))
                                    <span class="micro" style="color:var(--ink-3)">{{ Text::get('notifications.compose.from', ['handle' => $message->details['from']]) }}</span>
                                @elseif (! empty($message->details['targets']))
                                    {{-- An accepted job's systems: where to go. The address is the button that copies it. --}}
                                    <div class="prose micro" style="color:var(--ink-2)">
                                        <ul>
                                            @foreach ($message->details['targets'] as $target)
                                                <li>
                                                    <x-address :address="$target['address']" /> <x-jump :address="$target['address']" />
                                                    @if (! empty($target['grade']))
                                                        · {{ Text::get('notifications.details.grade', ['grade' => $target['grade']]) }}
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @elseif (! empty($message->details['facts']))
                                    {{-- A finished read: every row the system overview shows, as they stood when it
                                         finished. --}}
                                    @if (! empty($message->details['address']))
                                        <span class="micro"><x-address :address="$message->details['address']" /> <x-jump :address="$message->details['address']" /></span>
                                    @endif
                                    <x-scan-facts :facts="$message->details['facts']" />
                                @elseif (! empty($message->details['link']))
                                    {{-- Only a link in the sentence above: nothing to list. --}}
                                @elseif (! empty($message->details['collect']))
                                    {{-- A lost home's belongings: the button below collects them. --}}
                                @elseif ($message->details && empty($message->details['chat_invite']))
                                    <div class="prose micro" style="color:var(--ink-2)">
                                        <ul>
                                            @foreach ($message->details as $line)
                                                <li>
                                                    {{ $line['system'] ?? '' }}
                                                    @if (! empty($line['address']))
                                                        · <x-address :address="$line['address']" /> <x-jump :address="$line['address']" />
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
                                @if (! empty($message->details['chat_invite']))
                                    {{-- A room's invitation: answered here, and the message goes once it has been. --}}
                                    <form method="POST" action="{{ route('chat.accept', ['chat' => $message->details['chat_invite']]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn--sm cut">{{ Text::get('chat.accept') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('chat.decline', ['chat' => $message->details['chat_invite']]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn--sm btn--quiet cut">{{ Text::get('chat.decline') }}</button>
                                    </form>
                                @elseif (! empty($message->details['collect']))
                                    <form method="POST" action="{{ route('safehouse.collect', ['safehouse' => $message->details['collect']]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn--sm cut">{{ Text::get('safehouse.storage.collect') }}</button>
                                    </form>
                                @elseif (! empty($message->details['from']))
                                    <a class="btn btn--sm btn--quiet cut" href="{{ route('messages.create', ['to' => $message->details['from']]) }}">{{ Text::get('notifications.compose.reply') }}</a>
                                @else
                                <form method="POST" action="{{ route('notifications.open', $message) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--sm btn--quiet cut">
                                        {{ Text::get('notifications.go_to', ['place' => $message->category->label()]) }}
                                    </button>
                                </form>
                                @endif

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
