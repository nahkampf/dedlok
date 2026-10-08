@use('App\Support\Text')
@use('Carbon\CarbonInterval')
{{-- The body of the Current processes modal. One row per thing in flight: what it is, a bar for how far along, and the
     time left on the bar's tooltip (hover, or focus on a phone: the bar takes focus, which is how a tap reaches it). --}}
@forelse ($rows as $row)
    @php($left = $row['seconds'] === null
        ? null
        : ($row['seconds'] < 1
            ? Text::get('processes.finishing')
            : Text::get('processes.left', ['time' => CarbonInterval::seconds($row['seconds'])->cascade()->forHumans(['parts' => 2, 'short' => true])])))
    <div class="stack" style="--gap:var(--s-1);padding:var(--s-3) 0;border-top:var(--hair) solid var(--line-0)">
        <div class="row row--wrap" style="justify-content:space-between;gap:var(--s-3)">
            <span style="min-width:0">{{ $row['label'] }}</span>
            @if ($row['detail'])
                <span class="micro" style="color:var(--ink-3)">{{ $row['detail'] }}</span>
            @endif
        </div>

        @if ($row['ratio'] !== null)
            <div class="proc-bar" tabindex="0" @if ($left) data-tip="{{ $left }}" aria-label="{{ $left }}" @endif>
                <div class="meter" style="--v:{{ round($row['ratio'], 3) }}">
                    <div class="meter__fill"></div>
                </div>
            </div>
        @elseif ($left)
            <span class="micro" style="color:var(--ink-3)">{{ $left }}</span>
        @endif
    </div>
@empty
    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('processes.none') }}</p>
@endforelse
