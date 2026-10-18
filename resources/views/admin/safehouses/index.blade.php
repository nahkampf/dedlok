@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.safehouses.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.safehouses.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.safehouses.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.safehouses.lede') }}</p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.safehouses.title') }}</span>
            <div class="spacer"></div>
            <a class="btn btn--sm cut" href="{{ route('admin.safehouses.create') }}">{{ Text::get('admin.safehouses.new') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ Text::get('admin.safehouses.name') }}</th>
                        <th>{{ Text::get('admin.safehouses.district') }}</th>
                        <th class="t-right">{{ Text::get('admin.safehouses.rent') }}</th>
                        <th class="t-right">{{ Text::get('admin.safehouses.rest_rate') }}</th>
                        <th class="t-right">{{ Text::get('admin.safehouses.holders') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($safehouses as $safehouse)
                        <tr>
                            <td>
                                <a href="{{ route('admin.safehouses.edit', $safehouse) }}">{{ $safehouse->title }}</a>
                                @if ($safehouse->slug === $fallback)
                                    <span class="tag">{{ Text::get('admin.safehouses.fallback_tag') }}</span>
                                @endif
                            </td>
                            <td>{{ $safehouse->district->name }}</td>
                            <td class="t-right num">{{ $safehouse->rent }}</td>
                            <td class="t-right num">{{ $safehouse->rest_rate }}</td>
                            <td class="t-right num">{{ $holders[$safehouse->id] ?? 0 }}</td>
                            <td class="t-right"><a class="btn btn--sm btn--ghost cut" href="{{ route('admin.safehouses.edit', $safehouse) }}">{{ Text::get('admin.safehouses.edit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
