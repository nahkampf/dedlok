@use('App\Standing\Band')
@use('App\Support\Text')
@extends('layouts.app')
@section('title', $profile->handle)

@section('content')
    <h1>{{ $profile->handle }}</h1>

    <div class="row row--wrap" style="--gap:var(--s-5);align-items:flex-start;margin-top:var(--s-5)">
        <div class="stack" style="--gap:var(--s-3);width:min(300px,100%)">
            <x-entity-image kind="{{ $profile->user?->isSupporter() ? 'characters/avatars' : 'characters' }}" :id="$profile->getKey()" :alt="$profile->handle" portrait />
        </div>

        <div class="stack" style="--gap:var(--s-4);flex:1 1 min(320px,100%);min-width:0">
            <div class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                <span style="font-size:var(--fs-lg);font-weight:650">{{ $profile->handle }}</span>
                <x-supporter-badge :character="$profile" />
            </div>

            @unless ($isMe)
                {{-- Both are the existing doors: a direct line is found or made (`chat.direct`), and a message is the
                     inbox's own compose screen with the To field filled in. --}}
                <div class="row row--wrap" style="--gap:var(--s-2)">
                    <form method="POST" action="{{ route('chat.direct', ['character' => $profile->handle]) }}">
                        @csrf
                        <button type="submit" class="btn cut">{{ Text::get('profile.chat') }}</button>
                    </form>
                    <a class="btn btn--quiet cut" href="{{ route('messages.create', ['to' => $profile->handle]) }}">{{ Text::get('profile.message') }}</a>
                </div>
            @endunless

            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('profile.bio') }}</span></div>
                <div class="panel__bd">
                    @if (filled($profile->bio))
                        {{-- Printed escaped and as typed: it is where ASCII art is drawn, so no wrapping and no reflow. --}}
                        <pre class="ascii" style="margin:0">{{ $profile->bio }}</pre>
                    @else
                        <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('profile.bio_empty') }}</p>
                    @endif
                </div>
            </div>

            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('profile.standing') }}</span></div>
                <div class="panel__bd">
                    @if ($books->isEmpty())
                        <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('profile.standing_empty') }}</p>
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
                                            <td>{{ $book->entity->name }}</td>
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
        </div>
    </div>
@endsection
