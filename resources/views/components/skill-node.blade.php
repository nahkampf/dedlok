@use('App\Support\Text')
@props(['node'])

{{-- One skill, and everything hanging off it.

     The component recurses, so the nesting IS the indentation: each level
     wraps its children in a rail carrying a hairline down the left. The
     hairline goes on at every depth, but the indent STOPS after three — it
     compounds, and on a 320px screen a fourth step costs more width than the
     structure it buys. The rail and the row's own glyph still say "child"
     without it. --}}
<div class="item-row {{ $node->tierClass() }}">
    <span class="slot__icon" aria-hidden="true">{{ $node->isRoot() ? '◆' : '└' }}</span>

    <span class="stack" style="--gap:2px;min-width:0">
        <span class="item-row__name">{{ $node->skill->title }}</span>

        <span class="row row--wrap" style="--gap:var(--s-2)">
            <span class="micro" style="color:var(--ink-3)">
                {{ Text::get('skills.governed_by', [
                    'primary' => $node->skill->primary_attribute,
                    'secondary' => $node->skill->secondary_attribute,
                ]) }}
            </span>

            @if ($node->requiredLevel !== null)
                <span class="micro num" style="color:var(--ink-3)">
                    {{ Text::get('skills.from_level', ['level' => $node->requiredLevel]) }}
                </span>
            @endif
        </span>

        {{-- Every requirement the tree could not draw as an edge. Stated
             rather than hidden: the graph has skills with two parents, and a
             tree can only show one of them. --}}
        @if ($node->alsoRequires !== [] || $node->unreachable)
            <span class="row row--wrap" style="--gap:var(--s-2);margin-top:2px">
                @foreach ($node->alsoRequires as $extra)
                    <span class="tag">
                        @if ($extra['skill']->category !== $node->skill->category)
                            {{ Text::get('skills.from_elsewhere', [
                                'skill' => $extra['skill']->title,
                                'level' => $extra['level'],
                                'category' => $extra['skill']->category,
                            ]) }}
                        @else
                            {{ Text::get('skills.also_requires', [
                                'skill' => $extra['skill']->title,
                                'level' => $extra['level'],
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
            <x-skill-node :node="$child" />
        @endforeach
    </div>
@endif
