@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.supporters.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.supporters.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.supporters.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.supporters.lede') }}</p>

    <p style="margin-top:var(--s-3)"><a href="{{ route('admin.supporters.history') }}">{{ Text::get('admin.supporters.history_link') }} →</a></p>

    @if ($unmatched->isNotEmpty())
        <div class="panel" style="margin-top:var(--s-4)">
            <div class="panel__hd"><h2>{{ Text::get('admin.supporters.unmatched') }}</h2></div>
            <p class="muted" style="margin:var(--s-2) var(--s-4);font-size:var(--fs-sm)">{{ Text::get('admin.supporters.unmatched_lede') }}</p>
            @error('account') <p class="field__error" style="margin:0 var(--s-4)">{{ $message }}</p> @enderror
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ Text::get('admin.supporters.kofi_email') }}</th>
                            <th>{{ Text::get('admin.supporters.payments') }}</th>
                            <th>{{ Text::get('admin.supporters.last_payment') }}</th>
                            <th>{{ Text::get('admin.supporters.account_email') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($unmatched as $row)
                            <tr>
                                <td>{{ $row->email }}</td>
                                <td>{{ $row->payments }}</td>
                                <td>{{ \Illuminate\Support\Carbon::parse($row->last_at)->format('Y-m-d') }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.supporters.connect') }}" class="row row--wrap" style="--gap:var(--s-2)">
                                        @csrf
                                        <input type="hidden" name="kofi_email" value="{{ $row->email }}">
                                        <input class="input" name="account" type="email" required aria-label="{{ Text::get('admin.supporters.account_email') }}" style="flex:1 1 min(200px,100%)">
                                        <button type="submit" class="btn btn--sm cut">{{ Text::get('admin.supporters.connect') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <form method="GET" action="{{ route('admin.supporters.index') }}" class="row row--wrap" style="--gap:var(--s-3);margin-top:var(--s-4);align-items:flex-end">
        <div class="field" style="flex:1 1 min(280px,100%)">
            <label class="field__label" for="q">{{ Text::get('admin.supporters.search') }}</label>
            <input class="input" id="q" name="q" type="search" value="{{ $q }}" autocomplete="off">
        </div>
        <button type="submit" class="btn btn--sm cut">{{ Text::get('admin.supporters.find') }}</button>
    </form>

    <div class="panel" style="margin-top:var(--s-4)">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.supporters.account') }}</th>
                        <th>{{ Text::get('admin.supporters.name') }}</th>
                        <th>{{ Text::get('admin.supporters.status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>{{ $user->email }}</td>
                            <td>
                                {{ $user->character?->handle ?? Text::get('admin.supporters.no_character') }}
                                @if ($user->credit_name) <span class="micro" style="color:var(--ink-3)">· “{{ $user->credit_name }}”</span> @endif
                            </td>
                            <td>
                                @if ($user->isSupporter())
                                    <span class="tag tag--ember">{{ Text::get('supporters.badge') }}</span>
                                    <span class="micro" style="color:var(--ink-3)">{{ $user->supporter_since?->format('Y-m-d') }}{{ $user->supporter_until ? ' → '.$user->supporter_until->format('Y-m-d') : '' }}</span>
                                @endif
                            </td>
                            <td style="text-align:right">
                                <form method="POST" action="{{ route('admin.supporters.update', $user) }}">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="supporter" value="{{ $user->isSupporter() ? 0 : 1 }}">
                                    <button type="submit" class="btn btn--sm {{ $user->isSupporter() ? 'btn--ghost' : '' }} cut">
                                        {{ Text::get($user->isSupporter() ? 'admin.supporters.revoke' : 'admin.supporters.grant') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">{{ Text::get('admin.supporters.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="panel__ft">{{ $users->links('pagination.ui-kit') }}</div>
    </div>
@endsection
