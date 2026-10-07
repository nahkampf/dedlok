@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('notifications.compose.title'))

@section('content')
    <a class="link micro" href="{{ route('notifications.index') }}">{{ Text::get('notifications.compose.cancel') }}</a>
    <h1 style="margin-top:var(--s-2)">{{ Text::get('notifications.compose.title') }}</h1>

    <form method="POST" action="{{ route('messages.store') }}" class="panel" style="margin-top:var(--s-5);max-width:70ch">
        @csrf

        <div class="panel__bd stack" style="--gap:var(--s-4)">
            {{-- The suggestions are a native <datalist>, refilled as the field is typed in (data-suggest, ui-kit.js).
                 Only handles come back; the server still checks the one that is sent. --}}
            <div class="field" @error('to') data-invalid @enderror>
                <label class="field__label" for="to">{{ Text::get('notifications.compose.to') }}</label>
                <input class="input" id="to" name="to" type="text" maxlength="50" list="recipients" autocomplete="off"
                       autocapitalize="off" spellcheck="false" required
                       data-suggest="{{ route('messages.recipients') }}" data-suggest-into="recipients"
                       value="{{ old('to', $to) }}">
                <datalist id="recipients"></datalist>
                <span class="field__hint">{{ Text::get('notifications.compose.to_hint') }}</span>
                @error('to') <span class="field__error">{{ $message }}</span> @enderror
            </div>

            <div class="field" @error('title') data-invalid @enderror>
                <label class="field__label" for="title">{{ Text::get('notifications.compose.subject') }}</label>
                <input class="input" id="title" name="title" type="text" maxlength="{{ $max['title'] }}" required
                       value="{{ old('title') }}">
                @error('title') <span class="field__error">{{ $message }}</span> @enderror
            </div>

            <div class="field" @error('message') data-invalid @enderror>
                <label class="field__label" for="message">{{ Text::get('notifications.compose.message') }}</label>
                <textarea class="textarea" id="message" name="message" rows="8" maxlength="{{ $max['body'] }}" required>{{ old('message') }}</textarea>
                <span class="field__hint">{{ Text::get('notifications.compose.message_hint', ['max' => $max['body']]) }}</span>
                @error('message') <span class="field__error">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="panel__ft">
            <div class="spacer"></div>
            <button type="submit" class="btn btn--primary cut">{{ Text::get('notifications.compose.send') }}</button>
        </div>
    </form>
@endsection
