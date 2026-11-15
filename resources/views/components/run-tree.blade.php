@use('App\Support\Text')
@props([
    // App\Cyberspace\Tree\TreeView
    'view',
    'label',
    // What the runner has chosen and is waiting on the round for: the node of a Move, '-' for anything else, '' for nothing.
    'waiting' => '',
    // Drawn from a scan outside a run (the tree modal): no form, nothing to move to, no you-are-here.
    'static' => false,
    // The run this tree belongs to: the page remembers what it last showed of it, to animate a move and a reveal.
    'run' => null,
    // The alert has gone off: the threats on the map pulse.
    'alert' => false,
])

{{-- A RUN TREE, left to right (docs/RUN_TREE.md): the board's own classes, as the city map uses them, on a board as wide
     as the columns the runner can see. A node one edge away is a button that moves there: one form, one field, the same
     turn as every other action. --}}
@php($at = collect($view->nodes)->keyBy('key'))

@if ($static)
<div>
@else
<form method="POST" action="{{ route('run.act') }}" data-turn-form data-turn-field="node" data-waiting="{{ $waiting }}">
    @csrf
    <input type="hidden" name="action" value="move">
@endif
    <div class="board-wrap">
        <div @class(['frame scanlines brackets board board--tree', 'board--alert' => $alert]) role="group" aria-label="{{ $label }}"
             @if ($run !== null) data-tree-run="{{ $run }}" data-tree-here="{{ collect($view->nodes)->firstWhere('here', true)['key'] ?? '' }}" @endif
             style="--tree-cols:{{ $view->cols }};--tree-lanes:{{ $view->lanes }}">
            <span class="frame__label">{{ $label }}</span>

            <svg class="board__wires" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                @foreach ($view->links as [$from, $to])
                    @if (isset($at[$from], $at[$to]))
                        <line class="board__wire" data-from="{{ $from }}" data-to="{{ $to }}" x1="{{ $at[$from]['x'] }}" y1="{{ $at[$from]['y'] }}" x2="{{ $at[$to]['x'] }}" y2="{{ $at[$to]['y'] }}"></line>
                    @endif
                @endforeach
            </svg>

            @foreach ($view->nodes as $node)
                @php($tag = $node['move'] ? 'button' : 'div')
                <{{ $tag }}
                    @class(['board__node', 'board__node--here' => $node['here'], 'board__node--fog' => ! $node['known'], 'board__node--threat' => $node['threat'] ?? false, 'board__node--sprung' => $node['sprung'] ?? false, 'board__node--cutting' => ($node['cutting'] ?? null) !== null])
                    data-node="{{ $node['key'] }}" @if (($node['kind'] ?? null) !== null) data-kind="{{ $node['kind'] }}" @endif
                    @if ($node['move']) type="submit" name="node" value="{{ $node['key'] }}" aria-label="{{ Text::get('cyberspace.run.action.move') }}: {{ $node['label'] }}" @endif
                    @if ($node['here']) aria-current="location" @endif
                    @if ($node['blocked'] ?? false) data-blocked @endif
                    style="left:{{ $node['x'] }}%;top:{{ $node['y'] }}%">
                    <span class="board__box">
                        <span class="board__name">@if ($node['here'])<span class="here-mark" aria-hidden="true">&#9670;</span> @endif{{ $node['label'] }}@if ($node['allies'] > 0) <span class="board__count" title="{{ Text::get('cyberspace.tree.allies') }}">&#9671;{{ $node['allies'] > 1 ? $node['allies'] : '' }}</span>@endif @if ($node['barrier'] ?? false) <span class="board__count board__count--crit" title="{{ Text::get('cyberspace.tree.barrier') }}">&#9688;</span>@endif @if ($node['ice'] ?? false) <span class="board__count board__count--crit" title="{{ Text::get('cyberspace.tree.ice') }}">&#9632;</span>@endif @if (($node['sentries'] ?? 0) > 0) <span class="board__count board__count--crit" title="{{ Text::get('cyberspace.tree.sentry') }}">&#9650;{{ $node['sentries'] > 1 ? $node['sentries'] : '' }}</span>@endif</span>
                    </span>
                    {{-- A CORDON CUTTING this node: scissors and the turns left, under the box where nothing else is. --}}
                    @if (($node['cutting'] ?? null) !== null)
                        <span class="board__label board__label--cut" title="{{ Text::get('cyberspace.tree.cutting', ['turns' => $node['cutting']]) }}">&#9986; {{ $node['cutting'] }}</span>
                    @endif
                </{{ $tag }}>
            @endforeach
        </div>
    </div>
@if ($static)
</div>
@else
    <p class="micro here-legend"><span class="here-mark" aria-hidden="true">&#9670;</span> {{ Text::get('city.you_are_here') }} · &#9671; {{ Text::get('cyberspace.tree.allies') }} · <span style="color:var(--crit)" aria-hidden="true">&#9632;</span> {{ Text::get('cyberspace.tree.ice') }} · <span style="color:var(--crit)" aria-hidden="true">&#9688;</span> {{ Text::get('cyberspace.tree.barrier') }} · <span style="color:var(--crit)" aria-hidden="true">&#9650;</span> {{ Text::get('cyberspace.tree.sentry') }}</p>
</form>
@endif
