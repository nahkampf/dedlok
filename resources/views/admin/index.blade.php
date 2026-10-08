@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.title'))

@section('content')
    <h1>{{ Text::get('admin.title') }}</h1>
    <p class="muted" style="margin-top:var(--s-2);max-width:60ch">{{ Text::get('admin.lede') }}</p>

    {{-- A skill with no imprint cannot be learned by anyone who does not already
         know it, so it is a content bug rather than a preference. Warn, not
         crit: the game still runs. --}}
    @if ($skillsWithoutBooks > 0)
        <div class="callout callout--warn" style="margin-top:var(--s-5)">
            {{ Text::plural('admin.skills.without_books', $skillsWithoutBooks) }}
            {{ Text::get('admin.skills.book.missing') }}
        </div>
    @endif

    <div class="grid" style="margin-top:var(--s-5);grid-template-columns:repeat(auto-fit,minmax(min(260px,100%),1fr))">
        <div class="card">
            <div class="card__hd">
                <div class="card__title">{{ Text::get('admin.items.title') }}</div>
                <div class="card__meta">{{ $items }}</div>
            </div>
            <div class="card__bd">{{ Text::get('admin.items.lede', ['off' => Text::get('admin.items.fields.on_market')]) }}</div>
            <div class="card__stats">
                <div class="card__stat">{{ Text::get('admin.items.columns.stock') }} <b class="num">{{ $itemsOnMarket }}</b></div>
            </div>
            <div class="card__ft">
                <a class="btn btn--sm" href="{{ route('admin.items.index') }}">{{ Text::get('admin.items.title') }}</a>
                <div class="spacer"></div>
                <a class="btn btn--sm btn--primary cut" href="{{ route('admin.items.create') }}">{{ Text::get('admin.actions.new_item') }}</a>
            </div>
        </div>

        <div class="card">
            <div class="card__hd">
                <div class="card__title">{{ Text::get('admin.supporters.title') }}</div>
            </div>
            <div class="card__bd">{{ Text::get('admin.supporters.lede') }}</div>
            <div class="card__ft">
                <a class="btn btn--sm" href="{{ route('admin.supporters.index') }}">{{ Text::get('admin.supporters.title') }}</a>
            </div>
        </div>

        {{-- ALPHA TESTING (config/features.php): only while the flag is on. --}}
        @if (config('features.alpha_testing'))
            <div class="card">
                <div class="card__hd">
                    <div class="card__title">{{ Text::get('admin.beta_keys.title') }}</div>
                </div>
                <div class="card__bd">{{ Text::get('admin.beta_keys.lede') }}</div>
                <div class="card__ft">
                    <a class="btn btn--sm" href="{{ route('admin.beta_keys.index') }}">{{ Text::get('admin.beta_keys.title') }}</a>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card__hd">
                <div class="card__title">{{ Text::get('admin.npcs.title') }}</div>
                <div class="card__meta">{{ $npcs }}</div>
            </div>
            <div class="card__bd">{{ Text::get('admin.npcs.lede') }}</div>
            <div class="card__ft">
                <a class="btn btn--sm" href="{{ route('admin.npcs.index') }}">{{ Text::get('admin.npcs.title') }}</a>
            </div>
        </div>

        <div class="card">
            <div class="card__hd">
                <div class="card__title">{{ Text::get('admin.districts.title') }}</div>
                <div class="card__meta">{{ $districts }}</div>
            </div>
            <div class="card__bd">{{ Text::get('admin.districts.lede') }}</div>
            <div class="card__ft">
                <a class="btn btn--sm" href="{{ route('admin.districts.index') }}">{{ Text::get('admin.districts.title') }}</a>
                <a class="btn btn--sm" href="{{ route('admin.locations.index') }}">{{ Text::get('admin.locations.title') }}</a>
                <a class="btn btn--sm" href="{{ route('admin.encounters.index') }}">{{ Text::get('admin.encounters.title') }}</a>
            </div>
        </div>

        <div class="card">
            <div class="card__hd">
                <div class="card__title">{{ Text::get('admin.archetypes.title') }}</div>
                <div class="card__meta">{{ $archetypes }}</div>
            </div>
            <div class="card__bd">{{ Text::get('admin.archetypes.lede') }}</div>
            @if ($archetypesWithoutLoadout > 0)
                <div class="card__stats">
                    <div class="card__stat">
                        <span>{{ Text::get('admin.archetypes.sections.loadout') }}</span>
                        <b>{{ Text::plural('admin.archetypes.without_loadout', $archetypesWithoutLoadout) }}</b>
                    </div>
                </div>
            @endif
            <div class="card__ft">
                <a class="btn btn--sm" href="{{ route('admin.archetypes.index') }}">{{ Text::get('admin.archetypes.title') }}</a>
            </div>
        </div>

        <div class="card">
            <div class="card__hd">
                <div class="card__title">{{ Text::get('admin.entities.title') }}</div>
                <div class="card__meta">{{ $entities }}</div>
            </div>
            <div class="card__bd">{{ Text::get('admin.entities.lede') }}</div>
            @if ($entitiesWithoutShelf > 0)
                {{-- A desk that deals with players and sells them nothing is
                     one that takes loyalty and hands back nothing. --}}
                <div class="card__stats">
                    <div class="card__stat">
                        <span>{{ Text::get('admin.entities.offers') }}</span>
                        <b>{{ Text::plural('admin.entities.without_shelf', $entitiesWithoutShelf) }}</b>
                    </div>
                </div>
            @endif
            <div class="card__ft">
                <a class="btn btn--sm" href="{{ route('admin.entities.index') }}">{{ Text::get('admin.entities.title') }}</a>
            </div>
        </div>

        <div class="card">
            <div class="card__hd">
                <div class="card__title">{{ Text::get('admin.skills.title') }}</div>
                <div class="card__meta">{{ $skills }}</div>
            </div>
            <div class="card__bd">{{ Text::get('admin.skills.lede') }}</div>
            <div class="card__ft">
                <a class="btn btn--sm" href="{{ route('admin.skills.index') }}">{{ Text::get('admin.skills.title') }}</a>
                <div class="spacer"></div>
                <a class="btn btn--sm btn--primary cut" href="{{ route('admin.skills.create') }}">{{ Text::get('admin.actions.new_skill') }}</a>
            </div>
        </div>
    </div>
@endsection
