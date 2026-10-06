@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.beta_keys.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.beta_keys.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.beta_keys.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.beta_keys.lede') }}</p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.beta_keys.title') }}</span>
            <div class="spacer"></div>
            <form method="POST" action="{{ route('admin.beta_keys.store') }}">
                @csrf
                <button type="submit" class="btn btn--sm cut">{{ Text::get('admin.beta_keys.generate') }}</button>
            </form>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.beta_keys.code') }}</th>
                        <th>{{ Text::get('admin.beta_keys.status') }}</th>
                        <th>{{ Text::get('admin.beta_keys.used_by') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($keys as $key)
                        <tr>
                            {{-- A key that has been handed out is struck through, so the ones still to give are the ones
                                 that are legible. The script adds the same class the moment a key is copied. --}}
                            <td><span class="mono beta-code @if ($key->given_out_at) strike @endif">{{ $key->code }}</span></td>
                            <td>
                                @if ($key->isUsed())
                                    <span class="tag tag--ok">{{ Text::get('admin.beta_keys.used') }}</span>
                                @elseif ($key->given_out_at)
                                    <span class="tag">{{ Text::get('admin.beta_keys.given_out') }}</span>
                                @else
                                    <span class="tag" style="color:var(--ink-3)">{{ Text::get('admin.beta_keys.unused') }}</span>
                                @endif
                            </td>
                            <td class="micro">
                                @if ($key->isUsed())
                                    {{ $key->user?->email }}
                                    @if ($key->user?->character)
                                        · {{ $key->user->character->handle }}
                                    @elseif ($key->user)
                                        · <span style="color:var(--ink-3)">{{ Text::get('admin.beta_keys.no_character') }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="t-right">
                                <button type="button" class="btn btn--sm btn--ghost cut"
                                        data-copy="{{ $key->code }}"
                                        data-copied="{{ Text::get('admin.beta_keys.copied') }}"
                                        data-copy-failed="{{ Text::get('admin.beta_keys.copy_failed') }}"
                                        data-copy-then="{{ route('admin.beta_keys.given', $key) }}"
                                        data-copy-mark=".beta-code">{{ Text::get('admin.beta_keys.copy') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">{{ Text::get('admin.beta_keys.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
