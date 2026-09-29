@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('admin.entities.shelf_title', ['entity' => $entity->name]))

@section('content')
    <h1>{{ Text::get('admin.entities.shelf_title', ['entity' => $entity->name]) }}</h1>

    <p class="muted" style="margin-top:var(--s-2);max-width:70ch;font-size:var(--fs-sm)">
        {{ Text::get('admin.entities.shelf_lede') }}
    </p>

    {{-- WHO THEY ARE. Name, category and whether they deal with players are
         set by the migration that made them and shown here as facts: the first
         is quoted in stored inbox sentences, and the other two change what an
         entity IS rather than how it reads.

         What they SAY about themselves is flavour with no mechanical weight,
         and until this screen a typo in either line was unfixable without a
         migration. --}}
    <form method="POST" action="{{ route('admin.entities.update', $entity) }}" class="panel" style="margin-top:var(--s-5)">
        @csrf @method('PUT')

        <div class="panel__hd">
            <span class="micro">{{ Text::get('admin.entities.about') }}</span>
            <div class="spacer"></div>
            <span class="tag tag--warn">{{ $entity->category->label() }}</span>
            @if ($entity->interactable)
                <span class="tag tag--ok">{{ Text::get('admin.entities.interactable') }}</span>
            @else
                <span class="tag">{{ Text::get('admin.entities.scenery') }}</span>
            @endif
            <span class="micro" style="color:var(--ink-3)">{{ Text::get('admin.entities.fixed') }}</span>
        </div>

        <div class="panel__bd stack" style="--gap:var(--s-4)">
            <div class="field" @error('business') data-invalid @enderror>
                <label class="field__label" for="business">{{ Text::get('admin.entities.business') }}</label>
                <input class="input" id="business" name="business" type="text" maxlength="255"
                       value="{{ old('business', $entity->business) }}">
                <span class="field__hint">{{ Text::get('admin.entities.business_hint') }}</span>
                @error('business') <span class="field__error">{{ $message }}</span> @enderror
            </div>

            <div class="field" @error('description') data-invalid @enderror>
                <label class="field__label" for="description">{{ Text::get('admin.entities.description') }}</label>
                <textarea class="textarea" id="description" name="description" rows="5"
                          maxlength="5000">{{ old('description', $entity->description) }}</textarea>
                <span class="field__hint">{{ Text::get('admin.entities.description_hint') }}</span>
                @error('description') <span class="field__error">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="panel__ft">
            <div class="spacer"></div>
            <button type="submit" class="btn btn--sm cut">{{ Text::get('admin.entities.describe') }}</button>
        </div>
    </form>

    {{-- WHAT IS ON IT. An offer is four numbers and an item, which is small
         enough to edit in place — so the listing IS the editor and there is no
         page per row. Each line is its own form, because a single form around
         the table would save every row on every press. --}}
    <div class="panel" style="margin-top:var(--s-4)">
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
                                    @if ($offer->item && ! $offer->item->on_market)
                                        <span title="{{ Text::get('admin.entities.off_market_hint') }}">{{ Text::get('admin.entities.off_market') }}</span>
                                    @endif
                                    {{ $offer->displayName() }}
                                    <span class="micro" style="color:var(--ink-3)">
                                        {{ $offer->service ? $offer->service->describe((float) $offer->service_amount) : $offer->item?->slug }}
                                    </span>
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
                                            <input type="hidden" name="service" value="{{ $offer->service?->value }}">
                                            <input type="hidden" name="service_amount" value="{{ $offer->service_amount }}">
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
                        <option value="">{{ Text::get('admin.entities.no_item') }}</option>
                        <x-grouped-options :groups="$items" :selected="old('item_id')" />
                    </select>
                    <span class="field__hint">{{ Text::get('admin.entities.off_market_hint') }}</span>
                    @error('item_id') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                {{-- OR A SERVICE: something the entity does rather than hands
                     over. One or the other; the request refuses both. --}}
                <div class="field" @error('service') data-invalid @enderror>
                    <label class="field__label" for="service">{{ Text::get('admin.entities.service') }}</label>
                    <select class="select" id="service" name="service">
                        <option value="">{{ Text::get('admin.entities.no_service') }}</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->value }}" @selected(old('service') === $service->value)>{{ $service->label() }}</option>
                        @endforeach
                    </select>
                    @error('service') <span class="field__error">{{ $message }}</span> @enderror
                </div>

                <div class="field" @error('service_amount') data-invalid @enderror>
                    <label class="field__label" for="service_amount">{{ Text::get('admin.entities.service_amount') }}</label>
                    <input class="input num" id="service_amount" name="service_amount" type="number" min="0.1" step="0.1"
                           value="{{ old('service_amount') }}">
                    <span class="field__hint">{{ Text::get('admin.entities.service_amount_hint') }}</span>
                    @error('service_amount') <span class="field__error">{{ $message }}</span> @enderror
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
