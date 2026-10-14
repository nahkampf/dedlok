@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('chat.title'))

@section('content')
    <h1>{{ Text::get('chat.title') }}</h1>

    <div style="margin-top:var(--s-5);max-width:70ch" data-chat-page data-chat-url="{{ route('chat.show') }}">
        @include('chat.panel')
    </div>

    {{-- Drawn on the page itself rather than inside the panel, which is swapped: the handle suggestions
         (`data-suggest`) bind at load. --}}
    <dialog id="chat-create" class="modal">
        <form data-chat-form action="{{ route('chat.rooms') }}">
            <div class="panel__hd"><span class="micro">{{ Text::get('chat.create_title') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-4)">
                <div class="field">
                    <label class="field__label" for="chat-room-name">{{ Text::get('chat.name') }}</label>
                    <input class="input" id="chat-room-name" name="name" type="text" maxlength="64" required autocomplete="off">
                </div>
                <label class="switch">
                    <input type="checkbox" name="public" value="1">
                    <span class="switch__track"></span>
                    <span>{{ Text::get('chat.public') }}</span>
                </label>
            </div>
            <div class="panel__ft">
                <button type="button" class="btn btn--sm btn--ghost" data-close>{{ Text::get('ui.action.cancel') }}</button>
                <button type="submit" class="btn btn--sm">{{ Text::get('chat.make') }}</button>
            </div>
        </form>
    </dialog>

    <dialog id="chat-invite" class="modal">
        <form data-chat-form data-chat-invite-form action="{{ route('chat.invite', ['chat' => $chat->getKey()]) }}">
            <div class="panel__hd"><span class="micro">{{ Text::get('chat.invite_title') }}</span></div>
            <div class="panel__bd stack" style="--gap:var(--s-4)">
                <div class="field">
                    <label class="field__label" for="chat-invite-handle">{{ Text::get('chat.handle') }}</label>
                    <input class="input" id="chat-invite-handle" name="handle" type="text" maxlength="50" list="chat-handles"
                           autocomplete="off" autocapitalize="off" spellcheck="false" required
                           data-suggest="{{ route('messages.recipients') }}" data-suggest-into="chat-handles">
                    <datalist id="chat-handles"></datalist>
                </div>
            </div>
            <div class="panel__ft">
                <button type="button" class="btn btn--sm btn--ghost" data-close>{{ Text::get('ui.action.cancel') }}</button>
                <button type="submit" class="btn btn--sm">{{ Text::get('chat.invite') }}</button>
            </div>
        </form>
    </dialog>
@endsection
