@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('character.title'))

@section('content')
    <h1>{{ Text::get('character.title') }}</h1>

    <x-character-tabs current="character" />

    <div class="tabpanel">
        {{-- The picture beside the name, and the name beside what they are.
             A 3:4 portrait rather than the 16:9 card every other picture in
             the game uses: this one is of a person. Found by convention like
             all of them — public/images/characters/<id>.jpg, with
             _default.jpg behind it. --}}
        <div class="row row--wrap" style="--gap:var(--s-5);align-items:flex-start">
            <x-entity-image kind="characters" :id="$character->id" :alt="$character->handle" portrait />

            <div class="stack" style="--gap:var(--s-4);flex:1 1 min(320px,100%);min-width:0">
                <div class="stack" style="--gap:2px">
                    <span style="font-size:var(--fs-lg);font-weight:650">{{ $character->handle }}</span>
                    <span class="micro" style="color:var(--ink-3)">{{ $archetype?->name }}{{ $archetype?->role ? ' · '.$archetype->role : '' }}</span>
                </div>

                <div class="stack" style="--gap:var(--s-2)">
                    <span class="micro">{{ Text::get('character.attributes_heading') }}</span>

                    {{-- RESOLVED rather than stored, so an implant or a drug
                         shows here as well as in the check it moves. Two
                         columns where there is room and one where there is
                         not: ten bars in a single column is a scroll. --}}
                    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));--gap:var(--s-2)">
                        @foreach ($attributes as $attribute)
                            <div class="vital vital--wide">
                                <span class="vital__label">{{ $attribute['label'] }}</span>
                                <div class="meter" style="--v:{{ $attribute['ratio'] }};--fill:var(--ok)"
                                     role="img"
                                     aria-label="{{ Text::get('character.attribute_value', [
                                         'attribute' => $attribute['label'],
                                         'value' => $attribute['value'],
                                         'max' => $max,
                                     ]) }}">
                                    <div class="meter__fill"></div>
                                </div>
                                <span class="vital__value">{{ $attribute['value'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- The two resources against their RESOLVED maxima, and under
                     each whatever moved that maximum: an injury, an implant, a
                     dose. One line per layer, the evasion card's reading. --}}
                <div class="stack" style="--gap:var(--s-2)">
                    <span class="micro">{{ Text::get('character.vitals_heading') }}</span>

                    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));--gap:var(--s-3)">
                        @foreach ($vitals as $vital)
                            <div class="card">
                                <div class="card__bd stack" style="--gap:var(--s-2)">
                                    <div class="readout">
                                        <span class="readout__label">{{ $vital['label'] }}</span>
                                        <span class="readout__value num">{{ $vital['current'] }}<span style="font-size:var(--fs-md);color:var(--ink-3)"> / {{ $vital['max'] }}</span></span>
                                    </div>
                                    {{-- data-hot-low: ui-kit.js owns what "low" is,
                                         the same test the topbar's dials run. --}}
                                    <div class="meter" data-hot-low style="--v:{{ $vital['ratio'] }};--fill:var(--ok)"
                                         role="img"
                                         aria-label="{{ Text::get('character.vital_value', [
                                             'vital' => $vital['label'],
                                             'current' => $vital['current'],
                                             'max' => $vital['max'],
                                         ]) }}">
                                        <div class="meter__fill"></div>
                                    </div>
                                </div>

                                @if ($vital['steps'] === [])
                                    <div class="card__stats">
                                        <span class="card__stat">{{ Text::get('character.vitals_unmodified') }}</span>
                                    </div>
                                @else
                                    <div class="card__stats">
                                        <span class="micro" style="color:var(--ink-2)">{{ Text::get('character.vitals_modified') }}</span>
                                        @foreach ($vital['steps'] as $step)
                                            <div class="card__stat">
                                                <span>{{ $step->sources === [] ? $step->op : implode(', ', $step->sources) }}</span>
                                                <b class="num">{{ $step->delta() >= 0 ? '+' : '−' }}{{ rtrim(rtrim(number_format(abs($step->delta()), 1), '0'), '.') }}</b>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- THE PRICE ON THEIR HEAD, in creds. Always drawn: zero is an answer. --}}
        <div class="stack" style="--gap:var(--s-2);margin-top:var(--s-5)">
            <span class="micro">{{ Text::get('character.bounty_heading') }}</span>
            <div class="card" style="max-width:min(320px,100%)">
                <div class="card__bd stack" style="--gap:var(--s-2)">
                    <div class="readout">
                        <span class="readout__label">{{ Text::get('character.bounty_heading') }}</span>
                        <span class="readout__value num">¤ {{ number_format((int) $character->bounty) }}</span>
                    </div>
                    <span class="micro" style="color:var(--ink-3)">{{ $character->bounty > 0 ? Text::get('character.bounty_hint') : Text::get('character.bounty_none') }}</span>
                </div>
            </div>
        </div>

        {{-- WHAT IS IN THEM RIGHT NOW. Absent for nearly everybody, because a
             family with nothing in it and clean books is left out — a row of
             zeroes would say somebody has a drug problem they do not have.
             The meter is the OVERDOSE line: the dose that takes it past the
             capacity is the dose that breaks it. --}}
        @if ($doses !== [])
            <div class="stack" style="--gap:var(--s-2);margin-top:var(--s-5)">
                <span class="micro">{{ Text::get('character.doses_heading') }}</span>

                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(260px,100%),1fr));--gap:var(--s-3)">
                    @foreach ($doses as $row)
                        <div class="card">
                            <div class="card__hd">
                                <div class="row row--wrap" style="--gap:var(--s-2);align-items:baseline">
                                    <span class="card__title">{{ $row['family']->label() }}</span>
                                    <div class="spacer"></div>
                                    {{-- Brass and not ember: being hooked is a
                                         condition to manage, and the accent is
                                         for what is happening to you now. --}}
                                    @if ($row['withdrawing'])
                                        <span class="tag tag--warn">{{ Text::get('character.doses_withdrawing') }}</span>
                                    @elseif ($row['hooked'])
                                        <span class="tag tag--warn">{{ Text::get('character.doses_hooked') }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="card__bd stack" style="--gap:var(--s-2)">
                                <div class="vital vital--wide">
                                    <span class="vital__label">{{ Text::get('character.doses_capacity', ['used' => $row['used'], 'capacity' => $row['capacity']]) }}</span>
                                    <div class="meter" style="--v:{{ $row['ratio'] }};--fill:var({{ $row['used'] >= $row['capacity'] ? '--crit' : '--ok' }})">
                                        <div class="meter__fill"></div>
                                    </div>
                                    <span class="vital__value num">{{ $row['used'] }}/{{ $row['capacity'] }}</span>
                                </div>

                                @if ($row['used'] >= $row['capacity'])
                                    <span class="micro" style="color:var(--crit)">{{ Text::get('character.doses_at_the_line') }}</span>
                                @endif

                                @forelse ($row['doses'] as $dose)
                                    <div class="row row--wrap micro" style="--gap:var(--s-2);color:var(--ink-3)">
                                        <span style="color:var(--ink-1)">{{ $dose->item?->displayName() }}</span>
                                        <span>{{ Text::get('character.doses_wears_off', ['when' => $dose->expires_at->diffForHumans()]) }}</span>
                                        <span class="num">{{ Text::get('character.doses_potency', ['percent' => (int) round($dose->potency * 100)]) }}</span>
                                    </div>
                                @empty
                                    <span class="micro" style="color:var(--ink-3)">{{ Text::get('character.doses_none') }}</span>
                                @endforelse
                            </div>

                            {{-- The books. They move on their own clock and
                                 only time brings either down. --}}
                            <div class="card__stats">
                                <div class="card__stat">
                                    <span>{{ Text::get('character.doses_tolerance') }}</span>
                                    <b class="num">{{ round($row['tolerance']) }}</b>
                                </div>
                                <div class="card__stat">
                                    <span>{{ Text::get('character.doses_addiction') }}</span>
                                    <b class="num">{{ round($row['addiction']) }}</b>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- The only thing on this screen the game never reads. Theirs to
             write and theirs to take down again: clearing the box is how. --}}
        <form method="POST" action="{{ route('character.bio') }}" class="stack"
              style="--gap:var(--s-2);margin-top:var(--s-5);max-width:70ch">
            @csrf

            <label class="field" style="margin:0">
                <span class="field__label" for="bio">{{ Text::get('character.bio') }}</span>
                <textarea class="input" id="bio" name="bio" rows="6" maxlength="65000"
                          placeholder="{{ Text::get('character.bio_placeholder') }}">{{ old('bio', $character->bio) }}</textarea>
                <span class="field__hint">{{ Text::get('character.bio_hint') }}</span>
                @error('bio') <span class="field__error">{{ $message }}</span> @enderror
            </label>

            <div class="row" style="--gap:var(--s-2)">
                <button type="submit" class="btn btn--sm cut">{{ Text::get('character.bio_save') }}</button>
            </div>
        </form>
    </div>
@endsection
