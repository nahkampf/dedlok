@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('skills.title'))

@section('content')
    <h1>{{ Text::get('skills.title') }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:70ch">{{ Text::get('skills.lede') }}</p>

    @if ($categories === [])
        <div class="panel" style="margin-top:var(--s-5)">
            <div class="panel__bd">
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('skills.empty') }}</p>
            </div>
        </div>
    @else
        {{-- One section per category, each collapsible and each a flat list.
             The section IS the grouping, so nothing is nested inside it: a
             skill states what it needs in boxes, which an edge could not do
             more than once anyway. --}}
        <div class="accordion" style="margin-top:var(--s-5)">
            @foreach ($categories as $category => $nodes)
                <details @if ($loop->first) open @endif>
                    <summary>
                        {{ $category }}
                        <div class="spacer"></div>
                        <span class="micro num">
                            {{ Text::plural('skills.count', count($nodes)) }}
                        </span>
                    </summary>

                    <div>
                        @foreach ($nodes as $node)
                            <x-skill-node :node="$node" :levels="$levels" />
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
    @endif
@endsection
