@use('App\Support\Text')
@props(['node', 'levels' => []])

@php
    // Presence is what marks a skill as known: level 0 is a real state, so
    // reading the level to decide would hide a freshly injected skill.
    $known = array_key_exists($node->skill->id, $levels);
    $level = $levels[$node->skill->id] ?? 0;
@endphp

{{-- One skill, and everything hanging off it.

     The component recurses, so the nesting IS the indentation: each level
     wraps its children in a rail carrying a hairline down the left. The
     hairline goes on at every depth, but the indent STOPS after three — it
     compounds, and on a 320px screen a fourth step costs more width than the
     structure it buys. The rail and the row's own glyph still say "child"
     without it. --}}
<div @class(['item-row', $node->tierClass(), 'ticked' => $known])
     @if ($known) style="--tick:var(--ok)" title="{{ Text::get('skills.injected') }}" @endif>
    <span class="slot__icon" aria-hidden="true">{{ $node->isRoot() ? '◆' : '└' }}</span>

    <span class="stack" style="--gap:2px;min-width:0">
        <span class="item-row__name">{{ $node->skill->title }}</span>

        @if ($known)
            {{-- Levels attained, out of five. The unfilled segments are the
                 kit's own grey; only what has been earned is lit. --}}
            <div class="meter meter--seg"
                 style="--segs:5;--v:{{ $level / 5 }};--fill:var(--ok);width:min(140px,100%);margin-block:2px"
                 role="img"
                 aria-label="{{ Text::get('skills.level_meter', ['level' => $level, 'max' => 5]) }}"
                 title="{{ Text::get('skills.level_meter', ['level' => $level, 'max' => 5]) }}">
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

        {{-- Every requirement, in the same box whether the tree drew the edge
             or not. The one it hangs under comes first; the rest are the ones
             a tree cannot show, and hiding those would be the only real lie
             this layout could tell. --}}
        @if ($node->requirements() !== [] || $node->unreachable)
            <span class="row row--wrap" style="--gap:var(--s-2);margin-top:2px">
                @foreach ($node->requirements() as $requirement)
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

@if ($node->children !== [])
    <div @style([
        'border-left:var(--hair) solid var(--line-0)',
        'margin-inline-start:var(--s-3)' => $node->depth < 3,
        'padding-inline-start:var(--s-2)' => $node->depth < 3,
    ])>
        @foreach ($node->children as $child)
            <x-skill-node :node="$child" :levels="$levels" />
        @endforeach
    </div>
@endif
