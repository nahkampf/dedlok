@use('App\Chat\Markup')
@use('App\Support\Image')
@use('App\Support\Text')

{{-- ONE LINE of a chat, drawn on the server for both the page and the websocket event (`ChatLine`), so a pushed line
     is exactly the line a reload would show. `data-line` is what the page dedupes on; `data-by` what /ignore hides. --}}
@if ($line->is_event)
    <div class="chat__event" data-line="{{ $line->getKey() }}" data-by="{{ $line->character_id }}"
         title="{{ $line->created_at->utc()->format('Y-m-d H:i:s') }} UTC"><em>{{ $line->message }}</em></div>
@else
    @php($who = $line->character)
    @php($face = $who ? Image::card($who->user?->isSupporter() ? 'characters/avatars' : 'characters', $who->getKey()) : null)
    <div class="chat__row" data-line="{{ $line->getKey() }}" data-by="{{ $line->character_id }}"
         title="{{ $line->created_at->utc()->format('Y-m-d H:i:s') }} UTC">
        <span class="chat__face">@if ($face)<img src="{{ $face }}" alt="" width="32" height="24" loading="lazy">@endif</span>
        <span class="chat__line">
            {{-- The profile screen is not built yet; the handle carries the character id for when it is. --}}
            <button type="button" class="chat__who" data-profile="{{ $line->character_id }}"
                    title="{{ Text::get('chat.profile', ['handle' => $who?->handle]) }}">{{ $who?->handle }}&gt;</button>
            <span class="chat__msg">{!! Markup::render($line->message) !!}</span>
        </span>
    </div>
@endif
