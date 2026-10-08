@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('supporters.title'))

@section('content')
    <h1>{{ Text::get('supporters.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch">{{ Text::get('supporters.lede') }}</p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('supporters.title') }}</span>
            <div class="spacer"></div>
            <span class="micro num" style="color:var(--ink-2)">{{ $names->count() }}</span>
        </div>
        <div class="panel__bd">
            @if ($names->isEmpty())
                <p class="muted" style="margin:0;font-size:var(--fs-sm)">{{ Text::get('supporters.none') }}</p>
            @else
                <ul class="stack" style="--gap:var(--s-2);list-style:none;margin:0;padding:0">
                    @foreach ($names as $name)
                        <li>{{ $name }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
