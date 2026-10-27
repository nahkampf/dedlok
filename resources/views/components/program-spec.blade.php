@use('App\Support\Text')
@props(['item'])
{{-- What a program costs to hold and to run, and what it may not do beside; for a
     deck module, the one number it is for (RAM's QB, a CPU's qFLOPS) and what it draws and heats. The listing's
     version of the stash card's spec lines. Nothing for anything else. --}}
@php($module = $item->hardware && ! $item->hardware->isDeck() ? $item->deckModule : null)
@php($measure = $module?->measureLabel($item->hardware))
@php($load = $module?->loadLabels($item->hardware) ?? [])
{{-- What it does while in play: an expansion card's or implant's own modifiers, or a program's (an icebreaker's damage), as the stash card words them. --}}
@php($effects = $module || $item->isImplant()
    ? collect((array) $item->modifiers)->map(fn ($m) => \App\Stats\Modifier::fromArray($m)->effectText())
    : collect($item->program?->modifiers())->map(fn ($m) => $m->effectText())->unique()->values())
{{-- A weapon's and a round's figures, one line each; a rider reads as its kind, chance and turns. --}}
@php($weapon = $item->weapon)
@php($round = $item->ammunition)
@php($profile = fn (array $p): string => collect($p)->map(fn ($share, $type) => $share.'% '.\App\Stats\DamageOrigin::from($type)->label())->implode(', '))
@php($riders = collect($weapon?->riders() ?? $round?->riders() ?? [])->map(fn ($r) => Text::get('market.spec.rider', ['kind' => \App\Combat\RiderKind::from($r['kind'])->label(), 'chance' => $r['chance'], 'turns' => $r['turns']])))
{{-- A cyberdeck's sockets, the line its stash card carries. --}}
@php($sockets = $item->hardware?->isDeck() ? $item->hardwareSummary() : null)
@if ($item->program || $sockets || $measure || $load || $effects->isNotEmpty() || $weapon || $round)
    <div class="stack" style="--gap:var(--s-1)">
        @if ($sockets)
            <span class="micro num" style="color:var(--ink-3)">{{ $sockets }}</span>
        @endif
        @if ($measure)
            <span class="micro num" style="color:var(--ink-3)">{{ $measure }}</span>
        @endif
        @if ($weapon)
            <span class="micro num" style="color:var(--ink-3)">{{ Text::get($weapon->calibre ? 'market.spec.firearm' : 'market.spec.weapon', ['class' => $weapon->class->label(), 'accuracy' => $weapon->accuracy, 'rate' => $weapon->rate, 'magazine' => $weapon->magazine, 'calibre' => $weapon->calibre]) }}</span>
            @if ($weapon->damage)
                <span class="micro num" style="color:var(--ink-3)">{{ Text::get('market.spec.damage', ['damage' => $weapon->damage, 'profile' => $profile($weapon->profile())]) }}</span>
            @endif
        @endif
        @if ($round)
            <span class="micro num" style="color:var(--ink-3)">{{ Text::get('market.spec.ammo', ['calibre' => $round->calibre, 'type' => $round->type->label(), 'penetration' => $round->penetration]) }}</span>
            <span class="micro num" style="color:var(--ink-3)">{{ Text::get('market.spec.damage', ['damage' => $round->damage, 'profile' => $profile($round->profile() ?? [])]) }}</span>
        @endif
        @foreach ($riders as $rider)
            <span class="micro num" style="color:var(--ink-3)">{{ $rider }}</span>
        @endforeach
        @foreach ($effects as $effect)
            <span class="micro num" style="color:var(--ink-3)">{{ $effect }}</span>
        @endforeach
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
