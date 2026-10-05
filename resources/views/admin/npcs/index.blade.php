@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.npcs.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.npcs.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.npcs.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.npcs.lede') }}</p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.npcs.title') }}</span>
            <div class="spacer"></div>
            <a class="btn btn--sm cut" href="{{ route('admin.npcs.create') }}">{{ Text::get('admin.npcs.new') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.npcs.name') }}</th>
                        <th class="t-right">{{ Text::get('admin.npcs.grade') }}</th>
                        <th class="t-right">{{ Text::get('admin.npcs.hitpoints') }}</th>
                        <th class="t-right">{{ Text::get('admin.npcs.aim') }}</th>
                        <th class="t-right">{{ Text::get('admin.npcs.defence') }}</th>
                        <th>{{ Text::get('admin.npcs.ranged') }}</th>
                        <th>{{ Text::get('admin.npcs.melee') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($npcs as $npc)
                        <tr>
                            <td><a href="{{ route('admin.npcs.edit', $npc) }}">{{ $npc->name }}</a></td>
                            <td class="t-right num">{{ $npc->grade }}</td>
                            <td class="t-right num">{{ $npc->hitpoints }}</td>
                            <td class="t-right num">{{ $npc->aim }}</td>
                            <td class="t-right num">{{ $npc->defence }}</td>
                            <td>{{ $npc->rangedWeapon?->displayName() ?? '—' }}</td>
                            <td>{{ $npc->meleeWeapon?->displayName() ?? Text::get('combat.weapons.bare_hands') }}</td>
                            <td class="t-right"><a class="btn btn--sm btn--ghost cut" href="{{ route('admin.npcs.edit', $npc) }}">{{ Text::get('admin.npcs.edit') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">{{ Text::get('admin.npcs.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
