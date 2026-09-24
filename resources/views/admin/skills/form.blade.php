@use('App\Support\Text')
@extends('layouts.app')
@section('title', $skill ? Text::get('admin.skills.editing', ['skill' => $skill->slug]) : Text::get('admin.skills.new'))

@php
    $creating = $skill === null;
    $value = fn (string $field, $fallback = null) => old($field, $skill?->{$field} ?? $fallback);

    // Requirements come back from old() on a rejected submission, already
    // normalised by the request; otherwise from the rows themselves.
    $rows = old('prerequisites', $existing->map(fn ($row) => [
        'required_skill_id' => $row->required_skill_id,
        'required_level' => $row->required_level,
    ])->all());
@endphp

@section('content')
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">{{ Text::get('admin.title') }}</a></li>
        <li><a href="{{ route('admin.skills.index') }}">{{ Text::get('admin.skills.title') }}</a></li>
        <li aria-current="page">{{ $creating ? Text::get('admin.skills.new') : $skill->slug }}</li>
    </ol>

    <h1 style="margin-top:var(--s-3)">
        {{ $creating ? Text::get('admin.skills.new') : Text::get('admin.skills.editing', ['skill' => $skill->slug]) }}
    </h1>

    <x-auth-errors />

    <form method="POST"
          action="{{ $creating ? route('admin.skills.store') : route('admin.skills.update', $skill) }}"
          class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @csrf
        @unless ($creating) @method('PUT') @endunless

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.skills.sections.identity') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">

                <div class="field" @error('slug') data-invalid @enderror>
                    <label class="field__label" for="slug">{{ Text::get('admin.skills.fields.slug') }}</label>
                    @if ($creating)
                        <input class="input input--mono" id="slug" name="slug" value="{{ old('slug') }}" required>
                        <span class="field__hint">{{ Text::get('admin.skills.fields.slug_hint') }}</span>
                    @else
                        <input class="input input--mono" value="{{ $skill->slug }}" disabled>
                        <span class="field__hint">{{ Text::get('admin.skills.fields.slug_locked') }}</span>
                    @endif
                    @error('slug') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('title') data-invalid @enderror>
                    <label class="field__label" for="title">{{ Text::get('admin.skills.fields.title') }}</label>
                    <input class="input" id="title" name="title" value="{{ $value('title') }}" required>
                    <span class="field__hint">{{ Text::get('admin.skills.fields.title_hint') }}</span>
                    @error('title') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('category') data-invalid @enderror>
                    <label class="field__label" for="category">{{ Text::get('admin.skills.fields.category') }}</label>
                    {{-- A free text field with suggestions, not a <select>: a
                         category is created by being typed, and the tree draws
                         a lane for whatever it finds. --}}
                    <input class="input" id="category" name="category" list="categories"
                           value="{{ $value('category') }}" required>
                    <datalist id="categories">
                        @foreach ($categories as $category)
                            <option value="{{ $category }}"></option>
                        @endforeach
                    </datalist>
                    <span class="field__hint">{{ Text::get('admin.skills.fields.category_hint') }}</span>
                    @error('category') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" style="grid-column:1/-1" @error('description') data-invalid @enderror>
                    <label class="field__label" for="description">{{ Text::get('admin.skills.fields.description') }}</label>
                    <textarea class="textarea" id="description" name="description">{{ $value('description') }}</textarea>
                    @error('description') <span class="field__error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.skills.sections.training') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr))">

                <div class="field" @error('rank') data-invalid @enderror>
                    <label class="field__label" for="rank">{{ Text::get('admin.skills.fields.rank') }}</label>
                    <select class="select" id="rank" name="rank">
                        @foreach ($ranks as $rank)
                            <option value="{{ $rank }}" @selected((int) $value('rank', 1) === $rank)>
                                {{ Text::get('admin.skills.fields.rank_value', ['rank' => $rank]) }}
                            </option>
                        @endforeach
                    </select>
                    <span class="field__hint">{{ Text::get('admin.skills.fields.rank_hint') }}</span>
                    @error('rank') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                @foreach (['primary_attribute', 'secondary_attribute'] as $field)
                    <div class="field" @error($field) data-invalid @enderror>
                        <label class="field__label" for="{{ $field }}">{{ Text::get("admin.skills.fields.{$field}") }}</label>
                        <select class="select" id="{{ $field }}" name="{{ $field }}">
                            <optgroup label="{{ Text::get('character.attributes.physical') }}">
                                @foreach ($physical as $attribute)
                                    <option value="{{ $attribute }}" @selected($value($field) === $attribute)>
                                        {{ Text::get("character.names.{$attribute}") }}
                                    </option>
                                @endforeach
                            </optgroup>
                            <optgroup label="{{ Text::get('character.attributes.mental') }}">
                                @foreach ($mental as $attribute)
                                    <option value="{{ $attribute }}" @selected($value($field) === $attribute)>
                                        {{ Text::get("character.names.{$attribute}") }}
                                    </option>
                                @endforeach
                            </optgroup>
                        </select>
                        @error($field) <span class="field__error">{{ $message }}</span> @enderror
                    </div>
                @endforeach

                <div class="field" style="align-self:end">
                    <span class="field__hint">{{ Text::get('admin.skills.fields.attribute_hint') }}</span>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.skills.sections.prerequisites') }}</span></div>
            <div class="panel__bd stack">
                <p class="muted" style="font-size:var(--fs-sm)">{{ Text::get('admin.skills.prerequisites.help') }}</p>

                @error('prerequisites')
                    <div class="callout callout--crit">{{ $message }}</div>
                @enderror

                <div class="stack" data-requirements>
                    @foreach ($rows as $index => $row)
                        <div class="row row--wrap" data-requirement style="--gap:var(--s-3)">
                            <div class="field" style="flex:1 1 min(220px, 100%)">
                                <label class="field__label">{{ Text::get('admin.skills.prerequisites.skill') }}</label>
                                <select class="select" name="prerequisites[{{ $index }}][required_skill_id]">
                                    @foreach ($candidates as $candidate)
                                        <option value="{{ $candidate->id }}"
                                                @selected((int) ($row['required_skill_id'] ?? 0) === $candidate->id)>
                                            {{ $candidate->title }} ({{ $candidate->slug }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="field" style="flex:0 1 min(120px, 100%)">
                                <label class="field__label">{{ Text::get('admin.skills.prerequisites.level') }}</label>
                                <select class="select" name="prerequisites[{{ $index }}][required_level]">
                                    @for ($level = 1; $level <= $maxLevel; $level++)
                                        <option value="{{ $level }}" @selected((int) ($row['required_level'] ?? 1) === $level)>
                                            {{ $level }}
                                        </option>
                                    @endfor
                                </select>
                            </div>

                            <div class="row" style="align-items:flex-end;padding-bottom:2px">
                                <button type="button" class="btn btn--sm btn--ghost" data-remove-requirement>
                                    {{ Text::get('admin.actions.remove') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($candidates->isEmpty())
                    <p class="micro">{{ Text::get('admin.skills.prerequisites.none') }}</p>
                @else
                    <div>
                        <button type="button" class="btn btn--sm" data-add-requirement>
                            {{ Text::get('admin.actions.add_requirement') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.skills.sections.market') }}</span></div>
            <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr))">

                <div class="field" @error('base_cost') data-invalid @enderror>
                    <label class="field__label" for="base_cost">{{ Text::get('admin.skills.fields.base_cost') }}</label>
                    <input class="input num" id="base_cost" name="base_cost" type="number" min="0"
                           value="{{ $value('base_cost', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.skills.fields.base_cost_hint') }}</span>
                    @error('base_cost') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <span class="field__label">{{ Text::get('admin.skills.fields.on_market') }}</span>
                    <label class="switch">
                        <input type="checkbox" name="on_market" value="1" @checked($value('on_market', true))>
                        <span class="switch__track"></span>
                        <span>{{ Text::get('admin.skills.fields.on_market') }}</span>
                    </label>
                </div>
            </div>
        </div>

        {{-- What KNOWING this grants, which is not the check it feeds: a check
             is a generated stat and belongs to nobody. The level column is the
             one extra key a skill's modifiers carry — App\Stats\Sources\SkillEffects
             filters by it and strips it before building. --}}
        <x-modifier-rows name="modifiers"
                         :rows="\App\Admin\ModifierField::rows($skill?->modifiers, ['level'])"
                         :extras="['level']"
                         :legend="Text::get('admin.skills.fields.modifiers')"
                         :hint="Text::get('admin.skills.fields.modifiers_hint')" />

        <div class="row row--wrap">
            <button type="submit" class="btn btn--primary cut">
                {{ $creating ? Text::get('admin.actions.create') : Text::get('admin.actions.save') }}
            </button>
            <a class="btn btn--ghost" href="{{ route('admin.skills.index') }}">{{ Text::get('admin.actions.cancel') }}</a>
        </div>
    </form>

    @unless ($creating)
        {{-- The book is not part of the form: it is a separate row with its own
             editor, and half of its columns stopped following the skill the
             moment it was created. --}}
        <div class="panel" style="margin-top:var(--s-6)">
            <div class="panel__hd"><span class="micro">{{ Text::get('admin.skills.sections.book') }}</span></div>
            <div class="panel__bd stack">
                @if ($book)
                    <p style="font-size:var(--fs-sm)">{{ Text::get('admin.skills.book.present', ['book' => $book->slug]) }}</p>
                    <p class="muted" style="font-size:var(--fs-sm)">{{ Text::get('admin.skills.book.derived') }}</p>
                    <div>
                        <a class="btn btn--sm" href="{{ route('admin.items.edit', $book) }}">
                            {{ Text::get('admin.skills.book.edit') }}
                        </a>
                    </div>
                @else
                    <div class="callout callout--crit">{{ Text::get('admin.skills.book.missing') }}</div>
                @endif
            </div>
        </div>

        <div style="margin-top:var(--s-5)">
            <x-delete-panel :action="route('admin.skills.destroy', $skill)" :name="$skill->slug" :impact="$impact" />
        </div>
    @endunless
@endsection

@push('scripts')
    {{-- The requirement repeater. Rows are indexed only so the browser sends an
         array; the request reindexes and drops the blanks, so a gap left by a
         removal is harmless. --}}
    <template id="requirement-row">
        <div class="row row--wrap" data-requirement style="--gap:var(--s-3)">
            <div class="field" style="flex:1 1 min(220px, 100%)">
                <label class="field__label">{{ Text::get('admin.skills.prerequisites.skill') }}</label>
                <select class="select" name="prerequisites[__INDEX__][required_skill_id]">
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->title }} ({{ $candidate->slug }})</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="flex:0 1 min(120px, 100%)">
                <label class="field__label">{{ Text::get('admin.skills.prerequisites.level') }}</label>
                <select class="select" name="prerequisites[__INDEX__][required_level]">
                    @for ($level = 1; $level <= $maxLevel; $level++)
                        <option value="{{ $level }}">{{ $level }}</option>
                    @endfor
                </select>
            </div>
            <div class="row" style="align-items:flex-end;padding-bottom:2px">
                <button type="button" class="btn btn--sm btn--ghost" data-remove-requirement>
                    {{ Text::get('admin.actions.remove') }}
                </button>
            </div>
        </div>
    </template>

    <script>
        (function () {
            var list = document.querySelector('[data-requirements]');
            var template = document.getElementById('requirement-row');
            var add = document.querySelector('[data-add-requirement]');
            if (!list || !template || !add) return;

            var next = list.querySelectorAll('[data-requirement]').length;

            add.addEventListener('click', function () {
                var markup = template.innerHTML.replace(/__INDEX__/g, String(next++));
                list.insertAdjacentHTML('beforeend', markup);
            });

            list.addEventListener('click', function (event) {
                var button = event.target.closest('[data-remove-requirement]');
                if (button) button.closest('[data-requirement]').remove();
            });
        })();
    </script>
@endpush
