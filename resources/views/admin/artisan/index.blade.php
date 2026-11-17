@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.artisan.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.artisan.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.artisan.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.artisan.lede') }}</p>

    @if (session('artisan_output') !== null)
        <div class="panel" style="margin-top:var(--s-4)">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.artisan.output') }}</span></div>
            <div class="panel__bd"><pre style="margin:0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:var(--fs-sm)">{{ session('artisan_output') !== '' ? session('artisan_output') : Text::get('admin.artisan.no_output') }}</pre></div>
        </div>
    @endif

    <div class="grid" style="margin-top:var(--s-4);grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr))">
        @foreach ($actions as $action)
            <div class="card">
                <div class="card__hd">
                    <div class="card__title">{{ $action['name'] }}</div>
                </div>
                <div class="card__bd">{{ $action['description'] }}</div>
                <form method="POST" action="{{ route('admin.artisan.run', $action['name']) }}" class="card__ft">
                    @csrf
                    <div class="spacer"></div>
                    <button type="submit" class="btn btn--sm cut">{{ Text::get('admin.artisan.run') }}</button>
                </form>
            </div>
        @endforeach
    </div>
@endsection
