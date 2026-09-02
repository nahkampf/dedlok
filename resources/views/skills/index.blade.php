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
        {{-- One lane per category. The floor is capped so a lane never demands
             more width than a 320px screen has. --}}
        <div class="grid" style="margin-top:var(--s-5);grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr))">
            @foreach ($categories as $category => $roots)
                <div class="panel" style="min-width:0">
                    <div class="panel__hd">
                        <span class="micro">{{ $category }}</span>
                        <div class="spacer"></div>
                        <span class="micro num">
                            {{ Text::plural('skills.count', collect($roots)->sum(fn ($root) => $root->descendantCount())) }}
                        </span>
                    </div>

                    <div>
                        @foreach ($roots as $root)
                            <x-skill-node :node="$root" />
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
