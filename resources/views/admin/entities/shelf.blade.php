@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.entities.shelf_title', ['entity' => $entity->name]))

@section('content')
    <h1>{{ Text::get('admin.entities.shelf_title', ['entity' => $entity->name]) }}</h1>

    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">
        {{ Text::get('admin.entities.shelf_lede') }}
    </p>

    {{-- WHAT IS ON IT. An offer is four numbers and an item, which is small
         enough to edit in place — so the listing IS the editor and there is no
         page per row. Each line is its own form, because a single form around
         the table would save every row on every press. --}}
    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.entities.offers') }}</span>
            <span class="tag">{{ count($offers) }}</span>
        </div>

        @if (count($offers) === 0)
            <div class="panel__bd">
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('admin.entities.shelf_empty') }}</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ Text::get('admin.entities.item') }}</th>
                            <th class="t-right">{{ Text::get('admin.entities.grade_from') }}</th>
                            <th class="t-right">{{ Text::get('admin.entities.grade_to') }}</th>
                            <th class="t-right">{{ Text::get('admin.entities.price') }}</th>
                            <th class="t-right">{{ Text::get('admin.entities.min_standing') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($offers as $offer)
                            <tr>
                                <td>
                                    {{ $offer->item?->displayName() }}
                                    <span class="micro" style="color:var(--ink-3)">{{ $offer->item?->slug }}</span>
                                </td>
                                <td class="t-right">
                                    <input form="offer-{{ $offer->id }}" class="input num" type="number"
                                           name="min_grade" min="1" max="5" style="width:64px"
                                           value="{{ old('min_grade', $offer->min_grade) }}"
                                           aria-label="{{ Text::get('admin.entities.grade_from') }}">
                                </td>
                                <td class="t-right">
                                    <input form="offer-{{ $offer->id }}" class="input num" type="number"
                                           name="max_grade" min="1" max="5" style="width:64px"
                                           value="{{ old('max_grade', $offer->max_grade) }}"
                                           aria-label="{{ Text::get('admin.entities.grade_to') }}">
                                </td>
                                <td class="t-right">
                                    <input form="offer-{{ $offer->id }}" class="input num" type="number"
                                           name="loyalty_price" min="0" style="width:96px"
                                           value="{{ old('loyalty_price', $offer->loyalty_price) }}"
                                           aria-label="{{ Text::get('admin.entities.price') }}">
                                </td>
                                <td class="t-right">
                                    <input form="offer-{{ $offer->id }}" class="input num" type="number"
                                           name="min_standing" min="0" max="100" step="0.001" style="width:96px"
                                           value="{{ old('min_standing', $offer->min_standing) }}"
                                           aria-label="{{ Text::get('admin.entities.min_standing') }}">
                                </td>
                                <td class="t-right">
                                    <span class="row row--wrap" style="--gap:var(--s-2);justify-content:flex-end">
                                        {{-- The form lives here and the inputs
                                             point at it by id: a form cannot
                                             wrap table cells without breaking
                                             the row. --}}
                                        <form id="offer-{{ $offer->id }}" method="POST"
                                              action="{{ route('admin.entities.offers.update', [$entity, $offer]) }}">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="item_id" value="{{ $offer->item_id }}">
                                            <button type="submit" class="btn btn--sm cut">
                                                {{ Text::get('admin.entities.save') }}
                                            </button>
                                        </form>

                                        <form method="POST" action="{{ route('admin.entities.offers.destroy', [$entity, $offer]) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn--sm btn--ghost cut">
                                                {{ Text::get('admin.entities.remove') }}
                                            </button>
                                        </form>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ADDING ONE. The two grades default to the whole range and the standing
         gate to nothing, so the common row is an item and a price. --}}
    <form method="POST" action="{{ route('admin.entities.offers.store', $entity) }}" class="panel" style="margin-top:var(--s-4)">
        @csrf

        <div class="panel__hd"><span class="micro">{{ Text::get('admin.entities.add') }}</span></div>

        <div class="panel__bd">
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr))">
                <div class="field" @error('item_id') data-invalid @enderror>
                    <label class="field__label" for="item_id">{{ Text::get('admin.entities.item') }}</label>
                    <select class="select" id="item_id" name="item_id">
                        <x-grouped-options :groups="$items" :selected="old('item_id')" />
                    </select>
                    @error('item_id') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('loyalty_price') data-invalid @enderror>
                    <label class="field__label" for="loyalty_price">{{ Text::get('admin.entities.price') }}</label>
                    <input class="input num" id="loyalty_price" name="loyalty_price" type="number" min="0"
                           value="{{ old('loyalty_price', 0) }}">
                    @error('loyalty_price') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('min_grade') data-invalid @enderror>
                    <label class="field__label" for="min_grade">{{ Text::get('admin.entities.grade_from') }}</label>
                    <input class="input num" id="min_grade" name="min_grade" type="number" min="1" max="5"
                           value="{{ old('min_grade', 1) }}">
                    @error('min_grade') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('max_grade') data-invalid @enderror>
                    <label class="field__label" for="max_grade">{{ Text::get('admin.entities.grade_to') }}</label>
                    <input class="input num" id="max_grade" name="max_grade" type="number" min="1" max="5"
                           value="{{ old('max_grade', 5) }}">
                    @error('max_grade') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('min_standing') data-invalid @enderror>
                    <label class="field__label" for="min_standing">{{ Text::get('admin.entities.min_standing') }}</label>
                    <input class="input num" id="min_standing" name="min_standing" type="number" min="0" max="100" step="0.001"
                           value="{{ old('min_standing', 0) }}">
                    <span class="field__hint">{{ Text::get('admin.entities.min_standing_hint') }}</span>
                    @error('min_standing') <span class="field__error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="panel__ft">
            <div class="spacer"></div>
            <button type="submit" class="btn btn--sm cut">{{ Text::get('admin.entities.add') }}</button>
        </div>
    </form>

    <p style="margin-top:var(--s-4)">
        <a class="btn btn--ghost btn--sm cut" href="{{ route('admin.entities.index') }}">
            &larr; {{ Text::get('admin.entities.title') }}
        </a>
    </p>
@endsection
