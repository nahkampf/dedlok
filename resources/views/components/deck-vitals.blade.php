@use('App\Support\Text')
@props(['totals', 'thermal', 'spent'])

{{-- A deck's bars: what the running programs spend against what it resolves (compute, memory,
     storage), its power draw against supply, and its heat. Written once for the deck screen and
     the run briefing, which must agree about a deck. --}}
{{-- One-line @php only: a block form beside another is swallowed by Blade's own regex. --}}
@php($bars = [
    ['key' => 'qflops', 'label' => 'decks.fitting.compute', 'used' => $spent['qflops'], 'total' => $totals->qflops],
    ['key' => 'memory', 'label' => 'decks.fitting.memory', 'used' => $spent['memory'], 'total' => $totals->quantabits],
    ...($totals->stored === null ? [] : [['key' => 'storage', 'label' => 'decks.fitting.storage', 'used' => $totals->stored, 'total' => $totals->storage]]),
])
@php($scale = $thermal->scale())
@php($load = $totals->netThermalLoad())

{{-- data-vital / data-used / data-total / data-format are what the run briefing redraws as a loadout is ticked
     (ui-kit.js, data-run-budget); nothing else reads them. --}}
<div class="vitals" data-format="{{ Text::get('decks.fitting.used', ['used' => ':used', 'total' => ':total']) }}">
    {{-- What the running programs spend, against what the deck
         resolves: the budget a start is checked against. --}}
    @foreach ($bars as $bar)
        <div class="vital" data-vital="{{ $bar['key'] }}" data-used="{{ $bar['used'] }}" data-total="{{ $bar['total'] }}">
            <span class="vital__label">{{ Text::get($bar['label']) }}</span>
            <div class="meter" style="--v:{{ $bar['total'] > 0 ? round(min(1, $bar['used'] / $bar['total']), 3) : ($bar['used'] > 0 ? 1 : 0) }};--fill:var({{ $bar['used'] > $bar['total'] ? '--crit' : '--ok' }})">
                <div class="meter__fill"></div>
            </div>
            <span class="vital__value num" data-vital-value>{{ Text::get('decks.fitting.used', ['used' => $bar['used'], 'total' => $bar['total']]) }}</span>
        </div>
    @endforeach

    {{-- The plain sum of what is fitted. Nothing enforces it,
         and an over-committed deck is a design tension rather
         than a row to correct. --}}
    <div class="vital">
        <span class="vital__label">{{ Text::get('decks.fitting.draw') }}</span>
        <div class="meter" style="--v:{{ $totals->ratio() }};--fill:var({{ $totals->isOver() ? '--crit' : '--ok' }})">
            <div class="meter__fill"></div>
        </div>
        <span class="vital__value num">{{ Text::get('decks.fitting.budget', [
            'draw' => $totals->draw,
            'supply' => $totals->supply,
        ]) }}</span>
    </div>

    {{-- A deck has no thermal rating, so this bar is not
         measured against one: it ends at the highest LIMIT
         anything fitted carries, and each module's warning
         (brass) and limit (ember) is a tick on it. Coloured by
         the worst-off module. No module with a limit, nothing
         to measure against, and no bar. --}}
    @if ($scale !== null)
        @php($marks = implode("\n", array_map(fn (array $t): string => Text::get('decks.fitting.threshold', ['module' => $t['name'], 'warning' => $t['warning'], 'limit' => $t['limit']]), $thermal->thresholds())))
        <div class="vital">
            <span class="vital__label">{{ Text::get('decks.fitting.thermal') }}</span>
            <div class="meter" style="--v:{{ round(min(1, $load / $scale), 3) }};--fill:{{ $thermal->worst()->isSafe() ? 'var(--ok)' : $thermal->worst()->inkToken() }}"
                 title="{{ $marks }}" aria-label="{{ $marks }}" role="img">
                <div class="meter__fill"></div>
                @foreach ($thermal->thresholds() as $t)
                    <span class="meter__mark" style="--x:{{ round($t['warning'] / $scale, 3) }};--mark:var(--warn)"></span>
                    <span class="meter__mark" style="--x:{{ round(min(1, $t['limit'] / $scale), 3) }};--mark:var(--crit)"></span>
                @endforeach
            </div>
            <span class="vital__value num">{{ Text::get('decks.units.thermal_load', ['value' => $load]) }}</span>
        </div>
    @endif
</div>

