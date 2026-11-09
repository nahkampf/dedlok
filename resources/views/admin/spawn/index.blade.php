@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.spawn.title'))

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li aria-current="page">{{ Text::get('admin.spawn.title') }}</li>
    </ol>
    <h1 style="margin-top:var(--s-3)">{{ Text::get('admin.spawn.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">{{ Text::get('admin.spawn.lede') }}</p>

    <form method="POST" action="{{ route('admin.spawn.store') }}" class="stack" style="--gap:var(--s-4);margin-top:var(--s-4)">
        @csrf
        <div class="row row--wrap" style="--gap:var(--s-3);align-items:flex-end">
            <div class="field" style="flex:1 1 min(280px,100%)">
                <label class="field__label" for="handle">{{ Text::get('admin.spawn.character') }}</label>
                <input class="input" id="handle" name="handle" type="text" value="{{ old('handle') }}" required autocomplete="off" autocapitalize="off" spellcheck="false"
                       list="spawn-handles" data-suggest="{{ route('admin.spawn.handles') }}" data-suggest-into="spawn-handles">
                <datalist id="spawn-handles"></datalist>
                @error('handle') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn--primary cut">{{ Text::get('admin.spawn.give') }}</button>
        </div>
        @error('pick') <p class="field__error">{{ $message }}</p> @enderror

        <input class="input" type="search" data-filter="#spawn-items" style="max-width:min(360px,100%)"
               placeholder="{{ Text::get('market.search_placeholder') }}" aria-label="{{ Text::get('market.search') }}">

        <div id="spawn-items" class="stack" style="--gap:var(--s-3)">
            @foreach ($items->groupBy(fn ($i) => $i->marketCategory())->sortKeys() as $kind => $list)
                <details class="panel" data-filter-group>
                    <summary class="panel__hd">
                        <span class="micro">{{ $kind }}</span>
                        <div class="spacer"></div>
                        <span class="micro num" data-group-count>{{ count($list) }}</span>
                    </summary>
                    <div class="panel__bd">
                        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                            @foreach ($list as $item)
                                <div class="card ticked {{ $item->tierClass() }}" data-filter-text="{{ $item->displayName() }} {{ $kind }} {{ $item->slug }}">
                                    <x-entity-image :kind="$item->imageKind()" :id="$item->id" :alt="$item->displayName()" />
                                    <div class="card__hd">
                                        <label class="row" style="--gap:var(--s-2);align-items:center">
                                            <input type="checkbox" name="pick[]" value="{{ $item->id }}" @checked(in_array($item->id, old('pick', [])))>
                                            <span class="card__title">{{ $item->displayName() }}</span>
                                        </label>
                                        <div class="card__meta">{{ $item->kindLine() }}</div>
                                    </div>
                                    <div class="card__ft">
                                        <input class="input num" type="number" name="qty[{{ $item->id }}]" value="{{ old("qty.{$item->id}", 1) }}" min="1" max="1000"
                                               style="width:80px" aria-label="{{ Text::get('admin.spawn.quantity') }}">
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </details>
            @endforeach
            <p class="muted" data-filter-empty hidden style="font-size:var(--fs-sm);margin:0">{{ Text::get('market.no_match') }}</p>
        </div>
    </form>
@endsection
