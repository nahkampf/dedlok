@use('App\Chat\Markup')
@use('App\Models\Chat')
@use('App\Support\Image')
@use('App\Support\Text')

{{-- THE CHAT PANEL, as a fragment: fetched when the dialog opens (`[data-fragment]`) and re-fetched by its own timer
     (`data-chat-poll`, which swaps only the tabs and the log so what is being typed survives). Lines are drawn on the
     server — escaped, then a few markdown forms (`Markup`) — and never built in script from what somebody typed. --}}
@php($faces = [])
<div class="chat" data-chat="{{ $chat->getKey() }}" data-chat-poll="{{ route('chat.poll', ['chat' => $chat->getKey()]) }}">
    <div class="chat__tabs" data-chat-tabs role="group" aria-label="{{ Text::get('chat.title') }}">
        @foreach ($tabs as $tab)
            <button type="button" @class(['btn', 'btn--sm', 'btn--ghost' => ! $tab['chat']->is($chat)])
                    data-chat-go="{{ route('chat.show', ['chat' => $tab['chat']->getKey()]) }}"
                    @if ($tab['chat']->is($chat)) aria-current="true" @endif>
                {{ $tab['name'] }}@if ($tab['chat']->kind === Chat::GENERAL) ({{ $active }})@endif
                @if ($tab['unread'] > 0)<span class="tag tag--ember">{{ $tab['unread'] > 99 ? '99+' : $tab['unread'] }}</span>@endif
            </button>
        @endforeach
    </div>

    <div class="chat__log" data-chat-log>
        @forelse ($lines as $line)
            @php($who = $line->character)
            @php($kind = $who?->user?->isSupporter() ? 'characters/avatars' : 'characters')
            @php($faces[$kind.$who?->getKey()] ??= $who ? Image::card($kind, $who->getKey()) : null)
            <div class="chat__row" title="{{ $line->created_at->utc()->format('Y-m-d H:i:s') }} UTC">
                <span class="chat__face">@if ($faces[$kind.$who?->getKey()])<img src="{{ $faces[$kind.$who?->getKey()] }}" alt="" width="32" height="24" loading="lazy">@endif</span>
                <span class="chat__line">
                    {{-- The profile screen is not built yet; the handle carries the character id for when it is. --}}
                    <button type="button" class="chat__who" data-profile="{{ $line->character_id }}"
                            title="{{ Text::get('chat.profile', ['handle' => $who?->handle]) }}">{{ $who?->handle }}&gt;</button>
                    <span class="chat__msg">{!! Markup::render($line->message) !!}</span>
                </span>
            </div>
        @empty
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('chat.empty') }}</p>
        @endforelse
    </div>

    <form class="chat__form" data-chat-form action="{{ route('chat.say') }}">
        <input type="hidden" name="chat" value="{{ $chat->getKey() }}">
        <input class="input" name="message" type="text" maxlength="500" autocomplete="off" required
               placeholder="{{ Text::get('chat.say') }}" aria-label="{{ Text::get('chat.say') }}">
        <button type="submit" class="btn btn--sm">{{ Text::get('chat.send') }}</button>
    </form>

    <div class="chat__foot">
        <button type="button" class="btn btn--sm btn--ghost" data-open="#chat-create">{{ Text::get('chat.create') }}</button>
        @if ($chat->kind === Chat::ROOM)
            <button type="button" class="btn btn--sm btn--ghost" data-open="#chat-invite" data-chat-invite data-chat-invite-url="{{ route('chat.invite', ['chat' => $chat->getKey()]) }}">{{ Text::get('chat.invite') }}</button>
        @endif
    </div>

    @if ($joinable->isNotEmpty())
        <details class="chat__browse">
            <summary class="micro">{{ Text::get('chat.browse') }} ({{ $joinable->count() }})</summary>
            @foreach ($joinable as $room)
                <div class="chat__room">
                    <span>{{ $room->name }}</span>
                    <button type="button" class="btn btn--sm btn--ghost" data-chat-join="{{ route('chat.join', ['chat' => $room->getKey()]) }}">{{ Text::get('chat.join') }}</button>
                </div>
            @endforeach
        </details>
    @endif
</div>
