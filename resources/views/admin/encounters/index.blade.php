@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.encounters.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.encounters.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.encounters.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.encounters.lede') }}</p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.encounters.title') }}</span>
            <div class="spacer"></div>
            <a class="btn btn--sm cut" href="{{ route('admin.encounters.create') }}">{{ Text::get('admin.encounters.new') }}</a>
        </div>
        {{-- A table's look from lists: every row is the same grid, so the columns line up through any depth of nesting. --}}
        @php($cols = 'display:grid;grid-template-columns:minmax(0,2fr) minmax(0,2fr) 4rem minmax(0,1fr) auto;gap:var(--s-4);align-items:center;padding:var(--s-3) var(--s-4)')
        <div style="overflow-x:auto">
            <div style="min-width:640px">
                <div class="micro" style="{{ $cols }};color:var(--ink-3);text-transform:uppercase;border-bottom:1px solid var(--line-1)">
                    <span>{{ Text::get('admin.encounters.columns.title') }}</span>
                    <span>{{ Text::get('admin.encounters.columns.starts') }}</span>
                    <span>{{ Text::get('admin.encounters.columns.nodes') }}</span>
                    <span>{{ Text::get('admin.encounters.columns.state') }}</span>
                    <span style="width:3.5rem"></span>
                </div>
                @php($byParent = $encounters->groupBy(fn ($e) => $e->parent_id ?? 0))
                @include('admin.encounters._tree', ['nodes' => $byParent->get(0, collect()), 'byParent' => $byParent, 'depth' => 0, 'cols' => $cols])
            </div>
        </div>
    </div>
@endsection
