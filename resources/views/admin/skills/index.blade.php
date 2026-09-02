@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.skills.title'))

@section('content')
    <div class="row row--wrap">
        <h1>{{ Text::get('admin.skills.title') }}</h1>
        <div class="spacer"></div>
        <a class="btn btn--sm btn--primary cut" href="{{ route('admin.skills.create') }}">
            {{ Text::get('admin.actions.new_skill') }}
        </a>
    </div>

    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">
        {{ Text::get('admin.skills.lede') }}
    </p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.skills.title') }}</span>
            <span class="tag">{{ $skills->total() }}</span>
        </div>

        <div class="panel__bd">
            <form method="GET" action="{{ route('admin.skills.index') }}" class="row row--wrap" style="--gap:var(--s-3)">
                <div class="field" style="flex:1 1 min(220px, 100%)">
                    <label class="field__label" for="q">{{ Text::get('admin.actions.search') }}</label>
                    <input class="input" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                           placeholder="{{ Text::get('admin.skills.search_hint') }}">
                </div>

                <div class="field" style="flex:1 1 min(200px, 100%)">
                    <label class="field__label" for="category">{{ Text::get('admin.skills.fields.category') }}</label>
                    <select class="select" id="category" name="category">
                        <option value="">{{ Text::get('admin.skills.all_categories') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>
                                {{ $category }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="row row--wrap" style="align-items:flex-end;padding-bottom:2px">
                    <button type="submit" class="btn btn--sm">{{ Text::get('admin.actions.apply') }}</button>
                    <a class="btn btn--sm btn--ghost" href="{{ route('admin.skills.index') }}">{{ Text::get('admin.actions.clear') }}</a>
                </div>
            </form>
        </div>

        @if ($skills->isEmpty())
            <div class="panel__bd">
                <div class="empty">
                    <div style="font-size:var(--fs-xl);color:var(--ink-3)">◇</div>
                    <div class="h3" style="color:var(--ink-1)">{{ Text::get('admin.skills.empty') }}</div>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ Text::get('admin.skills.columns.slug') }}</th>
                            <th>{{ Text::get('admin.skills.columns.title') }}</th>
                            <th>{{ Text::get('admin.skills.columns.category') }}</th>
                            <th class="t-right">{{ Text::get('admin.skills.columns.rank') }}</th>
                            <th class="t-right">{{ Text::get('admin.skills.columns.requires') }}</th>
                            <th class="t-right">{{ Text::get('admin.skills.columns.unlocks') }}</th>
                            <th>{{ Text::get('admin.skills.columns.book') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($skills as $skill)
                            <tr>
                                <td><a href="{{ route('admin.skills.edit', $skill) }}" class="num">{{ $skill->slug }}</a></td>
                                <td>{{ $skill->title }}</td>
                                <td class="muted">{{ $skill->category }}</td>
                                <td class="t-right num">{{ Text::get('skills.rank', ['rank' => $skill->rank]) }}</td>
                                <td class="t-right num">{{ $skill->prerequisites_count }}</td>
                                <td class="t-right num">{{ $skill->unlocks_count }}</td>
                                <td>
                                    {{-- A skill with no book cannot be learned by
                                         anyone who does not already know it. That
                                         is the one status on this row worth an
                                         accent. --}}
                                    @if ($skill->skillbook)
                                        <span class="muted">{{ Text::get('admin.skills.book.yes') }}</span>
                                    @else
                                        <span class="tag tag--crit">{{ Text::get('admin.skills.book.no') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="panel__ft">
                {{ $skills->links('pagination.ui-kit') }}
            </div>
        @endif
    </div>
@endsection
