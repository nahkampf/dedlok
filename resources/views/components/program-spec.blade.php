@use('App\Support\Text')
@props(['item'])
{{-- What a program costs to hold and to run, and what it may not do beside; for a
     deck module, the one number it is for (RAM's QB, a CPU's qFLOPS) and what it draws and heats. The listing's
     version of the stash card's spec lines. Nothing for anything else. --}}
@php($module = $item->hardware && ! $item->hardware->isDeck() ? $item->deckModule : null)
@php($measure = $module?->measureLabel($item->hardware))
@php($load = $module?->loadLabels($item->hardware) ?? [])
@if ($item->program || $measure || $load)
    <div class="stack" style="--gap:var(--s-1)">
        @if ($measure)
            <span class="micro num" style="color:var(--ink-3)">{{ $measure }}</span>
        @endif
        @if ($load)
            <span class="micro num" style="color:var(--ink-3)">{{ implode(Text::get('items.summary.separator'), $load) }}</span>
        @endif
        @if ($item->program)
            <span class="micro num" style="color:var(--ink-3)">{{ $item->programSummary() }}</span>
            @foreach ($item->programRequirements() as $line)
                <span class="micro" style="color:var(--ink-3)">{{ $line }}</span>
            @endforeach
        @endif
    </div>
@endif
