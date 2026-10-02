@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.districts.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.districts.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.districts.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.districts.lede') }}</p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.districts.title') }}</span>
            <div class="spacer"></div>
            <a class="btn btn--sm cut" href="{{ route('admin.districts.create') }}">{{ Text::get('admin.districts.new') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.districts.name') }}</th>
                        <th>{{ Text::get('admin.districts.color') }}</th>
                        <th class="t-right">{{ Text::get('admin.districts.weight') }}</th>
                        <th class="t-right">{{ Text::get('admin.districts.places') }}</th>
                        <th class="t-right">{{ Text::get('admin.districts.here') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($districts as $district)
                        <tr>
                            <td><a href="{{ route('admin.districts.edit', $district) }}">{{ $district->name }}</a></td>
                            <td style="color:{{ $district->color->token() }}">{{ $district->color->value }}</td>
                            <td class="t-right num">{{ $district->travel_weight }}</td>
                            <td class="t-right num">{{ $district->locations_count }}</td>
                            <td class="t-right num">{{ $standing[$district->id] ?? 0 }}</td>
                            <td class="t-right"><a class="btn btn--sm btn--ghost cut" href="{{ route('admin.districts.edit', $district) }}">{{ Text::get('admin.districts.edit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
