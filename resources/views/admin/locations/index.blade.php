@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.locations.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.locations.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.locations.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.locations.lede') }}</p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.locations.title') }}</span>
            <div class="spacer"></div>
            <a class="btn btn--sm cut" href="{{ route('admin.locations.create') }}">{{ Text::get('admin.locations.new') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.locations.name') }}</th>
                        <th>{{ Text::get('admin.locations.district') }}</th>
                        <th>{{ Text::get('admin.locations.discovery') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($locations as $location)
                        <tr>
                            <td><a href="{{ route('admin.locations.edit', $location) }}">{{ $location->name }}</a></td>
                            <td class="muted">{{ $location->district?->name }}</td>
                            <td>
                                {{ $location->discovery->value }}
                                @if ($location->concealment) <span class="muted">· {{ $location->concealment->value }}</span> @endif
                                @if ($location->required_flag) <span class="muted">· {{ $location->required_flag }}</span> @endif
                            </td>
                            <td class="t-right"><a class="btn btn--sm btn--ghost cut" href="{{ route('admin.locations.edit', $location) }}">{{ Text::get('admin.locations.edit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
