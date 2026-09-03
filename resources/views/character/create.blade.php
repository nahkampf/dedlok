@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('character.create.title'))

@php
    use App\Models\Character;
    use App\Rules\ValidHandle;

    // Built here rather than inline in @foreach: a Blade directive expression
    // has to sit on one line, and a multi-line array literal breaks its parser.
    $groups = [
        'character.attributes.physical' => Character::PHYSICAL_ATTRIBUTES,
        'character.attributes.mental' => Character::MENTAL_ATTRIBUTES,
    ];

    // Same one-line constraint applies to @json, so the script's strings are
    // assembled here. Placeholders are left intact for the client to fill.
    $poolStrings = [
        'spent' => Text::get('character.attributes.spent', ['spent' => ':spent', 'total' => ':total']),
        'balanced' => Text::get('character.attributes.balanced'),
        'remaining' => Text::get('character.attributes.remaining', ['count' => ':count']),
        'overspent' => Text::get('character.attributes.overspent', ['count' => ':count']),
        'needHandle' => Text::get('character.handle.required'),
        'needArchetype' => Text::get('character.archetype.required'),
    ];

    $derivedSpec = $derived;
    $derivedEffects = $archetypeEffects;

    $handleStrings = [
        'failed' => Text::get('auth.suggest_handle_failed'),
        'empty' => Text::get('auth.suggest_handle_empty'),
    ];
@endphp

@push('head')
<style>
    /* Marks the entry the player asked about. Ember hairline, matching the
       kit's selected-state language rather than inventing a highlight. */
    /* The pool is the only feedback explaining why submit is disabled, and the
       sliders are far below it. Keep it on screen while they are being used. */
    .pool-sticky { position: sticky; top: 52px; z-index: 20; background: var(--bg-0); padding-bottom: var(--s-2); }

    .attr-info { border-left: 2px solid transparent; padding-left: var(--s-3); transition: border-color var(--t-base) var(--ease); }
    .attr-info--current { border-left-color: var(--ember); }
    .attr-info--current dt { color: var(--ember); }
</style>
@endpush

@section('content')
    <h1 class="display">{{ Text::get('character.create.title') }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:60ch">{{ Text::get('character.create.lede') }}</p>

    <x-auth-errors />

    {{-- An admin looking at a screen their own character disqualifies them
         from. The middleware refuses the POST whatever this says; the notice
         and the missing button are so the page does not offer something it
         will not do. --}}
    @if ($preview)
        <div class="callout callout--warn" style="margin-top:var(--s-4)">
            {{ Text::get('character.create.preview') }}
        </div>
    @endif

    @unless ($satisfiable)
        <div class="callout callout--crit" style="margin-top:var(--s-4)">
            {{ Text::get('character.attributes.pool_impossible', [
                'points' => $points,
                'min' => \App\Models\Character::CREATION_ATTRIBUTE_MIN * count(\App\Models\Character::ATTRIBUTES),
                'max' => \App\Models\Character::CREATION_ATTRIBUTE_MAX * count(\App\Models\Character::ATTRIBUTES),
            ]) }}
        </div>
    @endunless

    <form method="POST" action="{{ route('character.store') }}" id="character-form"
          data-points="{{ $points }}" class="stack" style="--gap:var(--s-6);margin-top:var(--s-5)">
        @csrf

        {{-- Handle ------------------------------------------------------ --}}
        <section>
            <div class="rule"><span class="micro">{{ Text::get('character.handle.section') }}</span></div>

            <div class="field" style="max-width:420px">
                <label class="field__label" for="handle">{{ Text::get('character.handle.label') }}</label>
                <div class="input-group">
                    <input class="input" id="handle" name="handle" type="text"
                           value="{{ old('handle') }}" required autofocus autocomplete="nickname"
                           maxlength="{{ ValidHandle::MAX_LENGTH }}">
                    <button type="button" class="btn" id="suggest-handle"
                            data-tip="{{ Text::get('auth.suggest_handle') }}"
                            aria-label="{{ Text::get('auth.suggest_handle') }}">↺</button>
                </div>
                <span class="field__hint">{{ Text::get('character.handle.hint', ['max' => ValidHandle::MAX_LENGTH]) }}</span>
                <span class="field__error" id="suggest-handle-error" hidden></span>
            </div>
        </section>

        {{-- Archetype --------------------------------------------------- --}}
        <section>
            <div class="rule"><span class="micro">{{ Text::get('character.archetype.section') }}</span></div>
            <p class="muted" style="font-size:var(--fs-sm);margin-bottom:var(--s-3)">{{ Text::get('character.archetype.help') }}</p>

            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                @foreach ($archetypes as $archetype)
                    {{-- An archetype that is not offered yet stays on the page,
                         disabled. The kit half-lights a .card--select holding a
                         disabled radio, which is the whole treatment: a role
                         nobody can pick is still worth reading, and hiding it
                         would make the roster look shorter than it is. --}}
                    <label class="card card--select">
                        <input type="radio" name="archetype" value="{{ $archetype['id'] }}"
                               @checked($archetype['playable'] && (int) old('archetype') === $archetype['id'])
                               @disabled(! $archetype['playable'])>
                        <div class="card__hd">
                            <div class="card__title">{{ $archetype['name'] }}</div>
                            <div class="card__meta">{{ $archetype['role'] }}</div>
                            @unless ($archetype['playable'])
                                <div class="row" style="margin-top:var(--s-2)">
                                    <span class="tag">{{ Text::get('character.archetype.unavailable') }}</span>
                                </div>
                            @endunless
                        </div>
                        <div class="card__bd">{{ $archetype['description'] }}</div>
                        @if ($archetype['perks'])
                            <div class="card__stats">
                                @foreach ($archetype['perks'] as $perk)
                                    <div class="card__stat">
                                        <span>{{ $perk['label'] }}</span>
                                        <b>{{ $perk['effect'] }}</b>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- The loadout: what the archetype GIVES, under what it
                             changes. Rendered only when there is something to
                             render — a headed block saying "nothing" on every
                             card is noise, and an archetype with an empty
                             loadout is a normal thing for one to be. --}}
                        @if ($archetype['creds'] > 0 || $archetype['skills'] || $archetype['items'])
                            <div class="card__stats">
                                <div class="micro">{{ Text::get('character.loadout.section') }}</div>

                                @if ($archetype['creds'] > 0)
                                    <div class="card__stat">
                                        <span>{{ Text::get('character.loadout.creds') }}</span>
                                        <b>{{ number_format($archetype['creds'], 0, '.', ' ') }}</b>
                                    </div>
                                @endif

                                @foreach ($archetype['skills'] as $skill)
                                    @php
                                        // What the segments say, for the people
                                        // who cannot see them. An empty meter is
                                        // a granted skill nobody has trained yet,
                                        // which is not the same as no skill.
                                        $meterLabel = $skill['level'] === 0
                                            ? Text::get('character.loadout.injected')
                                            : Text::get('character.loadout.level', [
                                                'level' => $skill['level'],
                                                'max' => $maxLevel,
                                            ]);
                                    @endphp
                                    <div class="card__stat">
                                        <span>{{ $skill['title'] }}</span>
                                        {{-- Levels attained out of five, the same
                                             segmented meter the skill tree draws.
                                             Only what the archetype grants is lit. --}}
                                        <div class="meter meter--seg"
                                             style="--segs:{{ $maxLevel }};--v:{{ $skill['level'] / $maxLevel }};--fill:var(--ok);width:min(84px,45%);flex:0 0 auto;align-self:center"
                                             role="img"
                                             aria-label="{{ $meterLabel }}"
                                             title="{{ $meterLabel }}">
                                            <div class="meter__fill"></div>
                                        </div>
                                    </div>
                                @endforeach

                                @foreach ($archetype['items'] as $item)
                                    <div class="card__stat">
                                        <span>{{ $item['name'] }}</span>
                                        <b>{{ Text::get('character.loadout.quantity', ['count' => $item['quantity']]) }}</b>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </label>
                @endforeach
            </div>
        </section>

        {{-- Points pool ------------------------------------------------- --}}
        <section class="pool-sticky">
            <div class="rule"><span class="micro">{{ Text::get('character.attributes.pool') }}</span></div>

            <div class="panel"><div class="panel__bd">
                <div class="row row--wrap" style="--gap:var(--s-6);align-items:flex-end">
                    <div class="readout">
                        <span class="readout__label">{{ Text::get('character.attributes.pool') }}</span>
                        <span class="readout__value num" id="pool-remaining">{{ $points }}</span>
                        <span class="readout__delta" id="pool-state"></span>
                    </div>
                    <div style="flex:1;min-width:min(220px,100%)">
                        <div class="row" style="--gap:var(--s-3);margin-bottom:4px">
                            <span class="micro">{{ Text::get('character.attributes.start', ['each' => reset($starting)]) }}</span>
                            <div class="spacer"></div>
                            <button type="button" class="btn btn--sm" id="pool-reset"
                                    data-each="{{ reset($starting) }}"
                                    data-tip="{{ Text::get('character.attributes.reset_hint', ['each' => reset($starting)]) }}">
                                {{ Text::get('character.attributes.reset') }}
                            </button>
                        </div>
                        <div class="micro" id="pool-spent" style="margin-bottom:6px"></div>
                        <div class="meter meter--lg" id="pool-meter" style="--v:0">
                            <div class="meter__fill"></div>
                        </div>
                    </div>
                </div>
            </div></div>
        </section>

        {{-- Derived values ----------------------------------------------- --}}
        <section>
            <div class="rule"><span class="micro">{{ Text::get('character.derived.section') }}</span></div>
            <p class="muted" style="font-size:var(--fs-sm);margin-bottom:var(--s-3)">{{ Text::get('character.derived.help') }}</p>

            <div class="panel"><div class="panel__bd">
                <div class="row row--wrap" style="--gap:var(--s-7)">
                    @foreach ($derived as $stat)
                        <div class="readout">
                            <span class="readout__label">{{ $stat['label'] }} ({{ $stat['abbr'] }})</span>
                            <span class="readout__value num" id="derived-{{ $stat['id'] }}">0</span>
                            <span class="readout__delta" id="derived-note-{{ $stat['id'] }}"></span>
                        </div>
                    @endforeach
                </div>
            </div></div>
        </section>

        {{-- Attributes -------------------------------------------------- --}}
        @foreach ($groups as $heading => $group)
            <section>
                <div class="rule"><span class="micro">{{ Text::get($heading) }}</span></div>

                <div class="panel"><div class="panel__bd">
                    <div class="vitals">
                        @foreach ($group as $attribute)
                            <div class="vital" style="grid-template-columns:minmax(0,110px) minmax(0,1fr) auto">
                                {{-- A button, not a <label>: clicking it opens the
                                     explainer rather than focusing the slider. The
                                     slider carries its own aria-label instead. --}}
                                <button type="button" class="vital__label link"
                                        style="text-align:left"
                                        data-open="#attribute-info"
                                        data-attr="{{ $attribute }}"
                                        title="{{ Text::get('character.info.open', ['attribute' => Text::get("character.names.{$attribute}")]) }}">
                                    {{ Text::get("character.names.{$attribute}") }}
                                </button>
                                <input class="range" type="range" id="attr-{{ $attribute }}"
                                       name="{{ $attribute }}" data-attribute
                                       aria-label="{{ Text::get("character.names.{$attribute}") }}"
                                       min="{{ Character::CREATION_ATTRIBUTE_MIN }}"
                                       max="{{ Character::CREATION_ATTRIBUTE_MAX }}"
                                       value="{{ old($attribute, $starting[$attribute]) }}">
                                <output class="vital__value num" for="attr-{{ $attribute }}"
                                        id="out-{{ $attribute }}">{{ old($attribute, $starting[$attribute]) }}</output>
                            </div>
                        @endforeach
                    </div>
                </div></div>
            </section>
        @endforeach

        <div class="row row--wrap" style="--gap:var(--s-3)">
            {{-- Not rendered at all in a preview, rather than rendered
                 disabled: the pool updater re-enables it the moment the form
                 is valid, and a button that comes back to life on a screen
                 that cannot save is worse than no button. --}}
            @unless ($preview)
                <button type="submit" class="btn btn--go btn--lg cut" id="character-submit" disabled>
                    {{ Text::get('character.create.submit') }}
                </button>
            @endunless
            <span class="micro" id="submit-hint">{{ $preview ? Text::get('character.create.preview_hint') : '' }}</span>
        </div>
    </form>

    {{-- One modal covering every attribute. Clicking a name opens it and
         highlights that entry, so a player can read one and then browse the
         rest without closing and reopening. --}}
    <dialog class="modal" id="attribute-info" style="width:min(560px,calc(100vw - 32px))">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('character.info.title') }}</span>
            <div class="spacer"></div>
            <button class="btn btn--sm btn--ghost" data-close
                    aria-label="{{ Text::get('character.info.close') }}">✕</button>
        </div>

        <div class="panel__bd" id="attribute-info-body">
            <p class="muted" style="font-size:var(--fs-sm)">
                {{ Text::get('character.info.lede', ['min' => \App\Models\Character::CREATION_ATTRIBUTE_MIN, 'max' => \App\Models\Character::CREATION_ATTRIBUTE_MAX]) }}
            </p>

            @foreach ($groups as $heading => $group)
                <div class="rule"><span class="micro">{{ Text::get($heading) }}</span></div>
                <dl class="stack" style="--gap:var(--s-4)">
                    @foreach ($group as $attribute)
                        <div id="info-{{ $attribute }}" class="attr-info">
                            <dt class="h3">{{ Text::get("character.names.{$attribute}") }}</dt>
                            <dd style="margin:4px 0 0;font-size:var(--fs-sm);color:var(--ink-2)">
                                {{ Text::get("character.descriptions.{$attribute}") }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endforeach
        </div>

        <div class="panel__ft">
            <div class="spacer"></div>
            <button class="btn btn--sm" data-close>{{ Text::get('character.info.close') }}</button>
        </div>
    </dialog>
@endsection

@push('scripts')
<script>
/*
 * ui-kit.js already opens the dialog from [data-open]; this only decides which
 * entry to bring into view. It listens on the document rather than binding each
 * trigger, so it cannot race the kit's own handler.
 */
document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-attr]');
    if (!trigger) { return; }

    var body = document.getElementById('attribute-info-body');
    var entry = document.getElementById('info-' + trigger.dataset.attr);
    if (!body || !entry) { return; }

    body.querySelectorAll('.attr-info').forEach(function (el) {
        el.classList.toggle('attr-info--current', el === entry);
    });

    // The dialog is opened by the kit in the same click, so the scroll has to
    // wait for it to be laid out — before that, offsetTop is meaningless.
    requestAnimationFrame(function () {
        body.scrollTop = Math.max(0, entry.offsetTop - body.offsetTop - 12);
    });
});
</script>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form      = document.getElementById('character-form');
    var total     = parseInt(form.dataset.points, 10);
    var sliders   = Array.prototype.slice.call(form.querySelectorAll('[data-attribute]'));
    var remaining = document.getElementById('pool-remaining');
    var state     = document.getElementById('pool-state');
    var spentEl   = document.getElementById('pool-spent');
    var meter     = document.getElementById('pool-meter');
    var submit    = document.getElementById('character-submit');
    var hint      = document.getElementById('submit-hint');
    var handle    = document.getElementById('handle');

    var T = @json($poolStrings);

    function fill(template, values) {
        return Object.keys(values).reduce(function (out, key) {
            return out.split(':' + key).join(values[key]);
        }, template);
    }

    function archetypeChosen() {
        return !!form.querySelector('input[name="archetype"]:checked');
    }

    function refresh() {
        var spent = sliders.reduce(function (sum, slider) {
            return sum + parseInt(slider.value, 10);
        }, 0);

        var left = total - spent;

        sliders.forEach(function (slider) {
            document.getElementById('out-' + slider.name).value = slider.value;
        });

        remaining.textContent = left;
        spentEl.textContent = fill(T.spent, { spent: spent, total: total });

        // The bar shows consumption, and turns critical once past the budget.
        meter.style.setProperty('--v', Math.min(1, total === 0 ? 0 : spent / total));
        meter.classList.toggle('meter--hot', left < 0);
        meter.style.setProperty('--fill', left === 0 ? 'var(--ok)' : 'var(--ember)');

        if (left < 0) {
            remaining.style.color = 'var(--crit)';
            state.textContent = fill(T.overspent, { count: -left });
            state.dataset.dir = 'down';
        } else if (left > 0) {
            remaining.style.color = '';
            state.textContent = fill(T.remaining, { count: left });
            delete state.dataset.dir;
        } else {
            remaining.style.color = 'var(--ok)';
            state.textContent = T.balanced;
            state.dataset.dir = 'up';
        }

        // Submitting is blocked by three separate things, and the hint has to
        // name the one that actually applies. Reporting the pool state for all
        // of them meant a balanced pool with an empty handle showed "Ready to
        // jack in" beside a disabled button.
        var blocker = null;

        if (left !== 0) {
            blocker = state.textContent;
        } else if (!archetypeChosen()) {
            blocker = T.needArchetype;
        } else if (handle.value.trim() === '') {
            blocker = T.needHandle;
        }

        // Absent in a preview, where there is nothing to enable and the hint
        // already says why.
        if (submit) {
            submit.disabled = blocker !== null;
            hint.textContent = blocker || '';
        }

        // The readout tracks the pool only; it must not claim readiness while
        // something else is still outstanding.
        if (left === 0 && blocker !== null) {
            state.textContent = blocker;
            delete state.dataset.dir;
            remaining.style.color = '';
        }
    }

    /*
     * Derived readouts. The formula arrives as data from config/stats.php, so
     * this computes the same numbers PHP does rather than restating them:
     * each term is ceil(attribute / divisor).
     *
     * Archetype perks are applied as a flattened (add, percent) pair, which is
     * the same subset of the resolver's layers the server flattened them into.
     */
    var derivedSpec = @json($derivedSpec);
    var derivedEffects = @json($derivedEffects);

    function currentAttributes() {
        var values = {};
        sliders.forEach(function (slider) { values[slider.name] = parseInt(slider.value, 10) || 0; });
        return values;
    }

    function selectedArchetype() {
        var chosen = form.querySelector('input[name="archetype"]:checked');
        return chosen ? chosen.value : null;
    }

    function refreshDerived() {
        var attributes = currentAttributes();
        var archetype = selectedArchetype();
        var effects = (archetype !== null && derivedEffects[archetype]) ? derivedEffects[archetype] : {};

        derivedSpec.forEach(function (stat) {
            var base = 0;

            Object.keys(stat.terms).forEach(function (attribute) {
                base += Math.ceil((attributes[attribute] || 0) / stat.terms[attribute]);
            });

            var effect = effects[stat.id] || { add: 0, percent: 0 };
            var value = (base + effect.add) * (1 + effect.percent / 100);

            if (stat.rounding === 'floor') { value = Math.floor(value); }
            else if (stat.rounding === 'ceil') { value = Math.ceil(value); }
            else if (stat.rounding === 'round') { value = Math.round(value); }

            if (stat.min !== null) { value = Math.max(stat.min, value); }
            if (stat.max !== null) { value = Math.min(stat.max, value); }

            document.getElementById('derived-' + stat.id).textContent = value;

            var note = document.getElementById('derived-note-' + stat.id);
            var changed = effect.add !== 0 || effect.percent !== 0;
            note.textContent = changed ? (effect.percent > 0 ? '+' : '') + effect.percent + '% archetype' : '';
            if (changed) { note.dataset.dir = effect.percent < 0 ? 'down' : 'up'; } else { delete note.dataset.dir; }
        });
    }

    /*
     * The ONE entry point. Anything that changes the form calls update(), never
     * refresh() or refreshDerived() directly.
     *
     * They were wired separately at first, and the Reset button was bound to
     * only one of them — so resetting the spread left the derived readouts
     * showing the previous values. A single updater means a new trigger cannot
     * silently forget half the screen.
     */
    function update() {
        refresh();
        refreshDerived();
    }

    sliders.forEach(function (slider) {
        slider.addEventListener('input', update);
    });

    form.querySelectorAll('input[name="archetype"]').forEach(function (radio) {
        radio.addEventListener('change', update);
    });

    handle.addEventListener('input', update);

    // Landing back on an exact total by dragging is fiddly, especially when the
    // even spread already consumes the whole pool and every move unbalances it.
    // This is the guaranteed route back to a valid state.
    var resetButton = document.getElementById('pool-reset');
    if (resetButton) {
        resetButton.addEventListener('click', function () {
            var each = parseInt(resetButton.dataset.each, 10);
            sliders.forEach(function (slider) { slider.value = String(each); });
            update();
        });
    }

    update();
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var button = document.getElementById('suggest-handle');
    var input  = document.getElementById('handle');
    var error  = document.getElementById('suggest-handle-error');
    var pool = null, cursor = 0;

    function shuffle(items) {
        for (var i = items.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var swap = items[i]; items[i] = items[j]; items[j] = swap;
        }
        return items;
    }

    function fail(message) {
        error.textContent = message || @json($handleStrings['failed']);
        error.hidden = false;
    }

    function offerNext() {
        if (!pool.length) { fail(@json($handleStrings['empty'])); return; }
        if (cursor >= pool.length) { shuffle(pool); cursor = 0; }
        var handle = pool[cursor++];
        input.value = handle;
        input.focus();
        input.setSelectionRange(handle.length, handle.length);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    button.addEventListener('click', function () {
        error.hidden = true;
        if (pool !== null) { offerNext(); return; }
        button.dataset.loading = '';
        fetch(@json(route('handles.available')), { headers: { Accept: 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error(@json($handleStrings['failed'])); } return r.json(); })
            .then(function (body) { pool = shuffle(body.handles.slice()); cursor = 0; offerNext(); })
            .catch(function (e) { fail(e.message); })
            .finally(function () { delete button.dataset.loading; });
    });
});
</script>
@endpush
