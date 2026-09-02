@use('App\Support\Text')
@use('Illuminate\Support\Number')
@props(['node', 'levels' => null])

@php
    // Null levels means nobody is signed in: no ticks, and nothing dimmed
    // either, because there is no "you" for a row to be missing from.
    $viewing = $levels !== null;

    // Presence is what marks a skill as known: level 0 is a real state, so
    // reading the level to decide would hide a freshly injected skill.
    $known = $viewing && array_key_exists($node->skill->id, $levels);
    $level = $levels[$node->skill->id]['level'] ?? 0;
    $points = $levels[$node->skill->id]['points'] ?? 0;

    $meterLabel = Text::get('skills.level_meter', [
        'level' => $level,
        'max' => 5,
        'points' => Number::format($points),
    ]);
@endphp

{{-- One skill, flat. Nesting used to say "this needs that", and the boxes said
     it too; now that a category is a section of its own, only the boxes do —
     and unlike an edge they can say it more than once, which the graph needs.

     No tier accent: .item-row paints border-left from --tier, and the rank
     tiers include jade, which IS --ok. A rank-2 skill drew a green stripe that
     read as state it did not mean. Rank is the ×N in the meta column. --}}
<div @class(['item-row', 'ticked' => $known, 'unlit' => $viewing && ! $known])
     @if ($known) style="--tick:var(--ok)" @endif>
    @if ($known)
        <span class="tick" title="{{ Text::get('skills.injected') }}"></span>
    @endif

    <span class="slot__icon" aria-hidden="true">◆</span>

    <span class="stack" style="--gap:2px;min-width:0">
        <span class="item-row__name">{{ $node->skill->title }}</span>

        @if ($known)
            {{-- Levels attained, out of five. The unfilled segments are the
                 kit's own grey; only what has been earned is lit. --}}
            <div class="meter meter--seg"
                 style="--segs:5;--v:{{ $level / 5 }};--fill:var(--ok);width:min(140px,100%);margin-block:2px"
                 role="img"
                 aria-label="{{ $meterLabel }}"
                 title="{{ $meterLabel }}">
                <div class="meter__fill"></div>
            </div>
        @endif

        <span class="row row--wrap" style="--gap:var(--s-2)">
            <span class="micro" style="color:var(--ink-3)">
                {{ Text::get('skills.governed_by', [
                    'primary' => $node->skill->primary_attribute,
                    'secondary' => $node->skill->secondary_attribute,
                ]) }}
            </span>
        </span>

        {{-- Everything this skill wants first. A skill may want several, which
             is the whole reason these are boxes and not lines. --}}
        @if ($node->requirements !== [] || $node->unreachable)
            <span class="row row--wrap" style="--gap:var(--s-2);margin-top:2px">
                @foreach ($node->requirements as $requirement)
                    <span class="tag">
                        @if ($requirement['skill']->category !== $node->skill->category)
                            {{ Text::get('skills.from_elsewhere', [
                                'skill' => $requirement['skill']->title,
                                'level' => $requirement['level'],
                                'category' => $requirement['skill']->category,
                            ]) }}
                        @else
                            {{ Text::get('skills.requires', [
                                'skill' => $requirement['skill']->title,
                                'level' => $requirement['level'],
                            ]) }}
                        @endif
                    </span>
                @endforeach

                @if ($node->unreachable)
                    <span class="tag tag--crit" title="{{ Text::get('skills.unreachable_hint') }}">
                        {{ Text::get('skills.unreachable') }}
                    </span>
                @endif
            </span>
        @endif
    </span>

    <span class="item-row__meta">{{ Text::get('skills.rank', ['rank' => $node->skill->rank]) }}</span>
</div>
