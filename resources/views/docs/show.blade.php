@use('App\Support\Text')
@extends('layouts.app')
@section('title', $file)

@section('content')
    {{-- A Markdown file from public/docs (DocsController): the rendered text, and the raw file to take away. --}}
    <div class="row row--wrap" style="--gap:var(--s-2);align-items:center;justify-content:flex-end">
        <a class="btn btn--sm btn--ghost cut" href="{{ route('docs.show', ['file' => $file, 'download' => 1]) }}">{{ Text::get('docs.download', ['file' => $file]) }}</a>
    </div>

    <article class="prose" style="max-width:72ch;margin-top:var(--s-3)">
        {!! $html !!}
    </article>
@endsection
