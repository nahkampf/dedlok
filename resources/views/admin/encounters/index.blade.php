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
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.encounters.columns.title') }}</th>
                        <th>{{ Text::get('admin.encounters.columns.starts') }}</th>
                        <th>{{ Text::get('admin.encounters.columns.nodes') }}</th>
                        <th>{{ Text::get('admin.encounters.columns.state') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($encounters as $encounter)
                        <tr>
                            <td><a href="{{ route('admin.encounters.edit', $encounter) }}">{{ $encounter->title }}</a></td>
                            <td class="muted">{{ $encounter->starts_on->label() }}</td>
                            <td class="num">{{ $encounter->nodes_count }}</td>
                            <td class="muted">
                                w{{ $encounter->weight }}
                                @if ($encounter->once) · {{ Text::get('admin.encounters.once_tag') }} @endif
                                @if ($encounter->retired) · {{ Text::get('admin.encounters.retired_tag') }} @endif
                            </td>
                            <td class="t-right"><a class="btn btn--sm btn--ghost cut" href="{{ route('admin.encounters.edit', $encounter) }}">{{ Text::get('admin.encounters.edit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
