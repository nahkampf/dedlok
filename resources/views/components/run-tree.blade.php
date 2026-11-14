@use('App\Support\Text')
@props([
    // App\Cyberspace\Tree\TreeView
    'view',
    'label',
    // What the runner has chosen and is waiting on the round for: the node of a Move, '-' for anything else, '' for nothing.
    'waiting' => '',
    // Drawn from a scan outside a run (the tree modal): no form, nothing to move to, no you-are-here.
    'static' => false,
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
        <div class="frame scanlines brackets board board--tree" role="group" aria-label="{{ $label }}"
             style="--tree-cols:{{ $view->cols }};--tree-lanes:{{ $view->lanes }}">
            <span class="frame__label">{{ $label }}</span>

            <svg class="board__wires" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                @foreach ($view->links as [$from, $to])
                    @if (isset($at[$from], $at[$to]))
                        <line class="board__wire" x1="{{ $at[$from]['x'] }}" y1="{{ $at[$from]['y'] }}" x2="{{ $at[$to]['x'] }}" y2="{{ $at[$to]['y'] }}"></line>
                    @endif
                @endforeach
            </svg>

            @foreach ($view->nodes as $node)
                @php($tag = $node['move'] ? 'button' : 'div')
                <{{ $tag }}
                    @class(['board__node', 'board__node--here' => $node['here'], 'board__node--fog' => ! $node['known']])
                    @if ($node['move']) type="submit" name="node" value="{{ $node['key'] }}" aria-label="{{ Text::get('cyberspace.run.action.move') }}: {{ $node['label'] }}" @endif
                    @if ($node['here']) aria-current="location" @endif
                    style="left:{{ $node['x'] }}%;top:{{ $node['y'] }}%">
                    <span class="board__box">
                        <span class="board__name">@if ($node['here'])<span class="here-mark" aria-hidden="true">&#9670;</span> @endif{{ $node['label'] }}@if ($node['allies'] > 0) <span class="board__count" title="{{ Text::get('cyberspace.tree.allies') }}">&#9671;{{ $node['allies'] > 1 ? $node['allies'] : '' }}</span>@endif</span>
                    </span>
                </{{ $tag }}>
            @endforeach
        </div>
    </div>
@if ($static)
</div>
@else
    <p class="micro here-legend"><span class="here-mark" aria-hidden="true">&#9670;</span> {{ Text::get('city.you_are_here') }} · &#9671; {{ Text::get('cyberspace.tree.allies') }}</p>
</form>
@endif
