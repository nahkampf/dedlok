@use('App\Support\Text')
@use('App\Models\Item')
@use('App\Decks\Hardware')
@extends('layouts.app')
@section('title', Text::get('admin.items.title'))

@section('content')
    <div class="row row--wrap">
        <h1>{{ Text::get('admin.items.title') }}</h1>
        <div class="spacer"></div>
        <a class="btn btn--sm btn--primary cut" href="{{ route('admin.items.create') }}">
            {{ Text::get('admin.actions.new_item') }}
        </a>
    </div>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.items.title') }}</span>
            <span class="tag">{{ $items->total() }}</span>
        </div>

        {{-- GET, so a filtered list is a URL someone can keep. Every control
             wraps: on a 320px phone this is four rows, not a sideways page. --}}
        <div class="panel__bd">
            <form method="GET" action="{{ route('admin.items.index') }}" class="row row--wrap" style="--gap:var(--s-3)">
                <div class="field" style="flex:1 1 min(220px, 100%)">
                    <label class="field__label" for="q">{{ Text::get('admin.actions.search') }}</label>
                    <input class="input" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                           placeholder="{{ Text::get('admin.items.search_hint') }}">
                </div>

                <div class="field" style="flex:1 1 min(180px, 100%)">
                    <label class="field__label" for="container">{{ Text::get('admin.items.fields.container') }}</label>
                    <select class="select" id="container" name="container">
                        <option value="">{{ Text::get('admin.items.containers.any') }}</option>
                        @foreach (Item::CONTAINERS as $container)
                            <option value="{{ $container }}" @selected(($filters['container'] ?? '') === $container)>
                                {{ Text::get("admin.items.containers.{$container}") }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field" style="flex:1 1 min(180px, 100%)">
                    <label class="field__label" for="hardware">{{ Text::get('admin.items.hardware.filter') }}</label>
                    <select class="select" id="hardware" name="hardware">
                        <option value="">{{ Text::get('admin.items.hardware.any') }}</option>
                        @foreach (Hardware::cases() as $kind)
                            <option value="{{ $kind->value }}" @selected(($filters['hardware'] ?? '') === $kind->value)>
                                {{ $kind->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field" style="flex:1 1 min(180px, 100%)">
                    <label class="field__label" for="market">{{ Text::get('admin.items.fields.on_market') }}</label>
                    <select class="select" id="market" name="market">
                        @foreach (['' => 'any', 'on' => 'on', 'off' => 'off'] as $value => $key)
                            <option value="{{ $value }}" @selected(($filters['market'] ?? '') === $value)>
                                {{ Text::get("admin.items.market.{$key}") }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="row row--wrap" style="align-items:flex-end;padding-bottom:2px">
                    <button type="submit" class="btn btn--sm">{{ Text::get('admin.actions.apply') }}</button>
                    <a class="btn btn--sm btn--ghost" href="{{ route('admin.items.index') }}">{{ Text::get('admin.actions.clear') }}</a>
                </div>
            </form>
        </div>

        @if ($items->isEmpty())
            <div class="panel__bd">
                <div class="empty">
                    <div style="font-size:var(--fs-xl);color:var(--ink-3)">◇</div>
                    <div class="h3" style="color:var(--ink-1)">{{ Text::get('admin.items.empty') }}</div>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ Text::get('admin.items.columns.slug') }}</th>
                            <th>{{ Text::get('admin.items.columns.name') }}</th>
                            <th>{{ Text::get('admin.items.columns.kind') }}</th>
                            <th>{{ Text::get('admin.items.columns.tier') }}</th>
                            <th>{{ Text::get('admin.items.columns.rarity') }}</th>
                            <th class="t-right">{{ Text::get('admin.items.columns.cost') }}</th>
                            <th class="t-right">{{ Text::get('admin.items.columns.stock') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.items.edit', $item) }}" class="num">{{ $item->slug }}</a>
                                    @unless ($item->on_market)
                                        <span class="tag">{{ Text::get('admin.items.unlisted') }}</span>
                                    @endunless
                                </td>
                                <td>
                                    {{ $item->displayName() }}
                                    @if ($item->isSkillbook())
                                        <div class="micro">{{ Text::get('admin.items.teaches', ['skill' => $item->useSkill?->slug ?? '—']) }}</div>
                                    @elseif ($item->isContainer())
                                        <div class="micro">{{ Text::get('admin.items.container_of', ['accepts' => $item->accepts]) }}</div>
                                    @endif

                                    {{-- The spec line: a deck's sockets, or a
                                         module's numbers. Nothing at all for
                                         an item that is neither. --}}
                                    @if ($item->hardware)
                                        <div class="micro" style="color:var(--ink-3)">
                                            {{ $item->hardware->label() }}@if ($item->hardwareSummary()) — {{ $item->hardwareSummary() }}@endif
                                        </div>
                                    @endif
                                </td>
                                <td class="muted">{{ Text::get("admin.items.containers.{$item->container}") }}</td>
                                <td><span class="item-row__meta {{ $item->tierClass() }}">{{ $item->tierName() }}</span></td>
                                <td class="muted">{{ $item->rarityName() }}</td>
                                <td class="t-right num">{{ number_format($item->base_cost) }}</td>
                                <td class="t-right num">{{ $item->stockOnHand() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="panel__ft">
                {{ $items->links('pagination.ui-kit') }}
            </div>
        @endif
    </div>
@endsection
