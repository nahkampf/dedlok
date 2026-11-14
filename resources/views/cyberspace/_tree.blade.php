@use('App\Support\Text')
{{-- The tree modal's body (#tree-view, filled through [data-listing]): what a scan read of a system's run tree, or why
     there is nothing to draw any more. --}}
@if ($view)
    <x-run-tree :view="$view" :label="$label" static />
@else
    <p class="muted" style="margin:0">{{ Text::get('cyberspace.tree.gone') }}</p>
@endif
