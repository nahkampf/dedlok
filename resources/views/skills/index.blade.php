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
        {{-- One section per category, each collapsible. The kit's accordion
             draws its own hairlines, so it needs no panel around it — and a
             full-width section gives a deep tree more room than a grid column
             did, which matters most on a phone. --}}
        <div class="accordion" style="margin-top:var(--s-5)">
            @foreach ($categories as $category => $roots)
                <details @if ($loop->first) open @endif>
                    <summary>
                        {{ $category }}
                        <div class="spacer"></div>
                        <span class="micro num">
                            {{ Text::plural('skills.count', collect($roots)->sum(fn ($root) => $root->descendantCount())) }}
                        </span>
                    </summary>

                    <div>
                        @foreach ($roots as $root)
                            <x-skill-node :node="$root" :levels="$levels" />
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
    @endif
@endsection
