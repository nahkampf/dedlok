@use('App\Support\Text')

{{-- The Running modal's "Could run" list, fetched when the modal opens. Only what `ProgramRunner::refusalFor()`
     accepts, so the button is there for exactly what a press would do; the Run form is the one the deck screen
     posts to, so there is one writer whichever screen asks. --}}
@forelse ($programs as $row)
    @php($program = $row->item->program)
    <div class="row row--wrap" style="justify-content:space-between;gap:var(--s-3);align-items:center;padding:var(--s-3) 0;border-top:var(--hair) solid var(--line-0)">
        <div style="min-width:0">
            <div style="color:var(--ink-0)">
                {{ $row->item->displayName() }}
                @if ($row->quantity > 1)
                    <span class="micro num" style="color:var(--ink-3)">×{{ $row->quantity }}</span>
                @endif
            </div>
            <div class="micro" style="color:var(--ink-2)">{{ $program->kind->label() }}</div>
        </div>
        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center;flex:none">
            <div class="micro num" style="text-align:right;color:var(--ink-2);white-space:nowrap">
                <div>{{ Text::get('programs.costs.compute', ['value' => $program->qflops_cost]) }}</div>
                <div>{{ Text::get('programs.costs.memory', ['value' => $program->memory_cost]) }}</div>
            </div>
            <form method="POST" action="{{ route('inventory.run', $row) }}">
                @csrf
                <button type="submit" class="btn btn--sm cut">{{ Text::get('programs.action.start') }}</button>
            </form>
        </div>
    </div>
@empty
    <p class="muted" style="font-size:var(--fs-sm);margin:0">
        {{ Text::get($hasDeck ? 'programs.startable_none' : 'programs.startable_no_deck') }}
    </p>
@endforelse
