@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.characters.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.characters.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.characters.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.characters.lede') }}</p>

    <form method="GET" action="{{ route('admin.characters.index') }}" class="row row--wrap" style="--gap:var(--s-3);margin-top:var(--s-4);align-items:flex-end">
        <div class="field" style="flex:1 1 min(280px,100%)">
            <label class="field__label" for="q">{{ Text::get('admin.characters.search') }}</label>
            <input class="input" id="q" name="q" type="search" value="{{ $q }}" autocomplete="off">
        </div>
        <button type="submit" class="btn btn--sm cut">{{ Text::get('admin.characters.find') }}</button>
    </form>
    @error('amount') <p class="field__error" style="margin-top:var(--s-3)">{{ $message }}</p> @enderror

    <div class="panel" style="margin-top:var(--s-4)">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.characters.character') }}</th>
                        <th>{{ Text::get('admin.characters.creds') }}</th>
                        <th>{{ Text::get('admin.characters.hp') }}</th>
                        <th>{{ Text::get('admin.characters.si') }}</th>
                        <th>{{ Text::get('admin.characters.grant') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($characters as $c)
                        <tr>
                            <td>{{ $c->handle }} <span class="micro" style="color:var(--ink-3)">{{ $c->user?->email }}</span></td>
                            <td>{{ number_format($c->creds) }}</td>
                            <td>{{ $c->current_hitpoints }} / {{ $c->maximumFor('current_hitpoints') }}</td>
                            <td>{{ $c->current_synaptic_integrity }} / {{ $c->maximumFor('current_synaptic_integrity') }}</td>
                            <td>
                                <div class="row row--wrap" style="--gap:var(--s-2)">
                                    <form method="POST" action="{{ route('admin.characters.creds', $c) }}" class="row row--wrap" style="--gap:var(--s-2)">
                                        @csrf
                                        <input class="input" name="amount" type="number" min="1" max="1000000" required placeholder="¤" aria-label="{{ Text::get('admin.characters.amount') }}" style="width:min(8rem,100%)">
                                        <button type="submit" class="btn btn--sm cut">{{ Text::get('admin.characters.grant_creds') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.characters.refill', [$c, 'hitpoints']) }}">
                                        @csrf
                                        <button type="submit" class="btn btn--sm cut">{{ Text::get('admin.characters.full_hp') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.characters.refill', [$c, 'si']) }}">
                                        @csrf
                                        <button type="submit" class="btn btn--sm cut">{{ Text::get('admin.characters.full_si') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">{{ Text::get('admin.characters.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="panel__ft">{{ $characters->links('pagination.ui-kit') }}</div>
    </div>
@endsection
