@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.subnets.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.subnets.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.subnets.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.subnets.lede') }}</p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.subnets.title') }}</span>
            <div class="spacer"></div>
            <a class="btn btn--sm cut" href="{{ route('admin.subnets.create') }}">{{ Text::get('admin.subnets.new') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.subnets.name') }}</th>
                        <th>{{ Text::get('admin.subnets.prefix') }}</th>
                        <th class="t-right">{{ Text::get('admin.subnets.size') }}</th>
                        <th class="t-right">{{ Text::get('admin.subnets.systems') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($subnets as $subnet)
                        <tr>
                            <td><a href="{{ route('admin.subnets.edit', $subnet->id) }}">{{ $subnet->name }}</a></td>
                            <td class="num">{{ $subnet->prefix }}</td>
                            <td class="t-right num">{{ $subnet->size }}</td>
                            <td class="t-right num">{{ $counts[$subnet->id] ?? 0 }}</td>
                            <td class="t-right"><a class="btn btn--sm btn--ghost cut" href="{{ route('admin.subnets.edit', $subnet->id) }}">{{ Text::get('admin.subnets.edit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
