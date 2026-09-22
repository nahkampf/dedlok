@use('App\Stats\Modifier')
@use('App\Support\Text')
@props([
    'skill',
    // The top of the level meter — SkillProgression::maxLevel().
    'max',
    // Null means not injected. Level 0 is a real state (injected, untrained),
    // so absent and zero are different answers here, as they are everywhere.
    'level' => null,
    // Whether this is the skill at the head of the queue, earning its next
    // level. That level breathes in ember on the meter.
    'training' => false,
    // Skill points banked, for the meter's label.
    'points' => 0,
    'ownsBook' => false,
    // skill id => attained level, for saying which requirements are met.
    // Empty is fine: everything then reads as unmet, which is what it is for
    // somebody who knows nothing.
    'levels' => [],
])

{{-- EVERYTHING ABOUT ONE SKILL, written once. Put it in a <template> beside
     whatever lists the skill and point data-inspect (hover layer and modal) or
     data-inspect-open (modal only) at it — the same device <x-item-detail>
     uses, so a skill and an item are inspected the same way. [data-act] is
     what the hover layer hides. --}}
<div class="card" style="max-width:min(420px,100%)">
    <div class="card__hd">
        <div class="row" style="--gap:var(--s-2);align-items:flex-start">
            <span class="stack" style="--gap:5px;min-width:0">
                <span class="card__title">{{ $skill->title }}</span>
                <span class="row row--wrap" style="--gap:var(--s-2)">
                    <span class="tag">{{ $skill->category }}</span>
                    <span class="micro" style="color:var(--ink-2)">{{ Text::get('training.card.rank', ['rank' => $skill->rank]) }}</span>
                </span>
            </span>
            <div class="spacer"></div>
            <button type="button" class="btn btn--sm btn--ghost" data-act data-close
                    aria-label="{{ Text::get('inventory.close') }}">✕</button>
        </div>
    </div>

    <div class="card__bd stack" style="--gap:var(--s-3)">
        @if ($skill->description)
            <p style="margin:0">{{ $skill->description }}</p>
        @endif

        {{-- Where they stand with it: owning the book, having spent it, and
             how far it has been trained since. --}}
        <span class="row row--wrap" style="--gap:var(--s-2)">
            <span class="tag {{ $level === null ? '' : 'tag--ok' }}">
                {{ Text::get($level === null ? 'training.card.not_injected' : 'training.card.injected') }}
            </span>
            {{-- Not owning the book says nothing once it has been spent. --}}
            @if ($ownsBook || $level === null)
                <span class="tag {{ $ownsBook ? 'tag--ok' : '' }}">
                    {{ Text::get('training.card.book') }} · {{ Text::get($ownsBook ? 'training.card.book_owned' : 'training.card.book_not_owned') }}
                </span>
            @endif
        </span>

        @if ($level !== null)
            @php($meterLabel = Text::get('training.level_meter', ['level' => $level, 'max' => $max, 'points' => Number::format($points)]))
            @if ($training)
                @php($meterLabel .= ' · '.Text::get('training.card.training', ['level' => $level + 1]))
            @endif
            <span class="row" style="--gap:var(--s-3);align-items:center">
                <div class="meter meter--seg"
                     style="--segs:{{ $max }};--v:{{ $max > 0 ? $level / $max : 0 }};--at:{{ $level }};--fill:var(--ok);width:min(140px,100%)"
                     role="img" aria-label="{{ $meterLabel }}" title="{{ $meterLabel }}">
                    <div class="meter__fill"></div>
                    @if ($training && $level < $max)
                        <div class="meter__next"></div>
                    @endif
                </div>
                <span class="micro num" style="color:var(--ink-2)">{{ $level }}/{{ $max }}</span>
            </span>
        @endif
    </div>

    <div class="card__stats">
        <div class="card__stat">
            <span>{{ Text::get('training.card.primary') }}</span>
            <b>{{ Text::get("character.names.{$skill->primary_attribute}") }}</b>
        </div>
        <div class="card__stat">
            <span>{{ Text::get('training.card.secondary') }}</span>
            <b>{{ Text::get("character.names.{$skill->secondary_attribute}") }}</b>
        </div>
    </div>

    {{-- A requirement is a warning and never a wall — see TrainingQueueValidator —
         so an unmet one is brass, not ember. --}}
    <div class="card__stats">
        <span class="micro" style="color:var(--ink-2)">{{ Text::get('training.card.requires') }}</span>
        @forelse ($skill->prerequisites as $prerequisite)
            @php($met = ($levels[$prerequisite->required_skill_id] ?? -1) >= $prerequisite->required_level)
            <div class="card__stat">
                <span style="{{ $met ? '' : 'color:var(--warn)' }}">{{ $prerequisite->requiredSkill?->title }}</span>
                <b class="num">{{ $prerequisite->required_level }}</b>
            </div>
        @empty
            <div class="card__stat"><span>{{ Text::get('training.card.requires_none') }}</span></div>
        @endforelse
    </div>

    {{-- What knowing it grants, level by level, from the modifiers on the row —
         the same text the resolver's own Modifier renders, so the card cannot
         drift from what the skill does. --}}
    <div class="card__stats">
        <span class="micro" style="color:var(--ink-2)">{{ Text::get('training.card.effects') }}</span>
        @forelse ((array) $skill->modifiers as $grant)
            @php($grantLevel = (int) ($grant['level'] ?? 0))
            <div class="card__stat">
                <span style="{{ $level !== null && $level >= $grantLevel ? 'color:var(--ink-0)' : '' }}">{{ Text::get('training.card.effect_level', ['level' => $grantLevel]) }}</span>
                <b>{{ Modifier::fromArray(array_diff_key($grant, ['level' => true]))->effectText() }}</b>
            </div>
        @empty
            <div class="card__stat"><span>{{ Text::get('training.card.effects_none') }}</span></div>
        @endforelse
    </div>
</div>
