@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.archetypes.title'))

@section('content')
    <h1>{{ Text::get('admin.archetypes.title') }}</h1>

    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">
        {{ Text::get('admin.archetypes.lede') }}
    </p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.archetypes.title') }}</span>
            <span class="tag">{{ $archetypes->count() }}</span>
        </div>

        @if ($archetypes->isEmpty())
            <div class="panel__bd">
                <div class="empty">
                    <div style="font-size:var(--fs-xl);color:var(--ink-3)">◇</div>
                    <div class="h3" style="color:var(--ink-1)">{{ Text::get('admin.archetypes.empty') }}</div>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ Text::get('admin.archetypes.columns.slug') }}</th>
                            <th>{{ Text::get('admin.archetypes.columns.name') }}</th>
                            <th>{{ Text::get('admin.archetypes.columns.role') }}</th>
                            <th>{{ Text::get('admin.archetypes.columns.playable') }}</th>
                            <th class="t-right">{{ Text::get('admin.archetypes.columns.creds') }}</th>
                            <th class="t-right">{{ Text::get('admin.archetypes.columns.skills') }}</th>
                            <th class="t-right">{{ Text::get('admin.archetypes.columns.items') }}</th>
                            <th class="t-right">{{ Text::get('admin.archetypes.columns.perks') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($archetypes as $archetype)
                            <tr>
                                <td><a href="{{ route('admin.archetypes.edit', $archetype) }}" class="num">{{ $archetype->slug }}</a></td>
                                <td>{{ $archetype->name }}</td>
                                <td class="muted">{{ $archetype->role }}</td>
                                <td>
                                    {{-- Withdrawn is the state worth seeing at a
                                         glance: the row still exists and still
                                         resolves for the characters who chose
                                         it, it is just off the menu. --}}
                                    @if ($archetype->playable)
                                        <span class="muted">{{ Text::get('admin.archetypes.offered.yes') }}</span>
                                    @else
                                        <span class="tag">{{ Text::get('admin.archetypes.offered.no') }}</span>
                                    @endif
                                </td>
                                <td class="t-right num">{{ number_format($archetype->starting_creds, 0, '.', ' ') }}</td>
                                <td class="t-right num">{{ $archetype->starting_skills_count }}</td>
                                <td class="t-right num">{{ $archetype->starting_items_count }}</td>
                                <td class="t-right num">{{ $archetype->perks_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
