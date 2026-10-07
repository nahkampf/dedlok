@use('App\Missions\MissionAction')
@use('App\Support\Text')
@use('Illuminate\Support\Number')
@props([
    'mission',
    // Work in progress reads differently from work on offer: one shows how
    // much is left of it, the other shows what taking it would mean.
    'active' => false,
    // How many complete, unexpired scan files the character is holding. Only
    // a supply job reads it, and it is counted once by the controller rather
    // than per card.
    'files' => 0,
    // The addresses of the scan files in hand. A survey is handed in as files, so each of its systems says whether the
    // one it needs is already held.
    'held' => [],
    // Buttons: Complete and Abandon / Accept and Decline. Off where the card is only being READ (Tasks & Missions): a job is
    // handed in at the desk it came from.
    'actions' => true,
    // Say where it came from: who it is for, their agent and the desk's address.
    'desk' => false,
])

@php($action = $mission->action)
@php($done = $mission->targets->whereNotNull('satisfied_at')->count())
@php($total = $mission->targets->count())

<div class="card {{ $active ? 'card--select' : '' }}">
    <div class="card__hd">
        <div class="card__title">{{ $action->label() }}</div>
        <div class="card__meta">
            @if ($action->designatesTargets())
                {{ Text::get('missions.targets_done', ['done' => $done, 'total' => $total]) }}
            @else
                {{ Text::get('missions.quantity', ['count' => $mission->quantity_required]) }}
            @endif
        </div>
    </div>

    <div class="card__bd stack" style="--gap:var(--s-3)">
        <p class="micro" style="margin:0;color:var(--ink-2);max-width:70ch">{{ $action->description() }}</p>

        {{-- WHERE IT CAME FROM, when the card is not drawn at that desk: the one it is for, the agent, and where the desk is. --}}
        @if ($desk)
            <span class="row row--wrap micro" style="--gap:var(--s-2);color:var(--ink-3)">
                <span>{{ Text::get('tasks.from', ['entity' => $mission->entity?->name, 'agent' => $mission->exchange?->agent]) }}</span>
                @if ($mission->exchange?->system)
                    <span>· {{ Text::get('tasks.desk') }} <x-address :address="$mission->exchange->system->address" /></span>
                @endif
            </span>
        @endif

        {{-- WHAT IT PAYS, and what failing costs beside it. A player choosing
             between two jobs is choosing between two evenings, and the second
             number is half of that decision. --}}
        <span class="row row--wrap micro num" style="--gap:var(--s-3);color:var(--ink-3)">
            <span>{{ Text::get('missions.pays', ['creds' => Number::format($mission->creds_reward)]) }}</span>
            <span>{{ Text::get('missions.standing', ['amount' => number_format($mission->standing_reward, 3)]) }}</span>
            <span>{{ Text::get('missions.loyalty', ['amount' => $mission->loyalty_reward]) }}</span>
            <span style="color:var(--crit)">{{ Text::get('missions.penalty', ['amount' => number_format($mission->penalty, 3)]) }}</span>
        </span>

        @if ($active && $mission->expires_at)
            @php($left = $mission->minutesLeft())
            <div>
                <span class="micro">
                    @if ($left === 0)
                        {{ Text::get('missions.expired') }}
                    @else
                        {{ Text::get('missions.window', ['time' => $mission->expires_at->diffForHumans(['syntax' => 2])]) }}
                    @endif
                </span>
            </div>
        @endif

        {{-- THE SYSTEMS, WITH THEIR ADDRESSES. An address is how a runner gets
             there: it carries no position, so handing one over is harmless and
             is exactly what a job is. A target that stopped answering says so
             rather than being dropped — the job is about to void, and that is
             worth seeing before the walk. --}}
        @if ($action->designatesTargets() && $total > 0)
            <div class="stack" style="--gap:var(--s-2)">
                <span class="micro">{{ Text::get('missions.targets') }}</span>

                @foreach ($mission->targets as $target)
                    <div class="item-row">
                        <span class="item-row__name">
                            <x-address :address="$target->address" />
                        </span>
                        <span class="row row--wrap" style="--gap:var(--s-2);justify-content:flex-end">
                            @if ($target->isSatisfied())
                                <span class="tag tag--ok">{{ Text::get('missions.target_done') }}</span>
                            @elseif (! $target->stillStands())
                                <span class="tag tag--crit">{{ Text::get('missions.target_gone') }}</span>

                            @elseif ($target->system)
                                <span class="item-row__meta">{{ Text::get('cyberspace.scan.grade') }} {{ $target->system->grade }}</span>
                            @endif

                            {{-- A survey is handed in as files: say whether this system's is in hand. --}}
                            @if ($active && $action === MissionAction::Scan && ! $target->isSatisfied() && $target->stillStands())
                                @php($inHand = in_array($target->address, $held, true))
                                <span class="tag {{ $inHand ? 'tag--ok' : '' }}">{{ Text::get($inHand ? 'missions.file_held' : 'missions.file_needed') }}</span>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($action === MissionAction::Supply)
            {{-- A meter, because the whole job is a count against a count and
                 the kit already draws that. --}}
            <div>
                <span class="micro">{{ $files }} / {{ $mission->quantity_required }}</span>
                <div class="meter" style="--v:{{ $mission->quantity_required > 0 ? min(1, $files / $mission->quantity_required) : 0 }}">
                    <div class="meter__fill"></div>
                </div>
            </div>
        @endif
    </div>

    @if ($actions)
    <div class="card__ft">
        @if ($active)
            <form method="POST" action="{{ route('missions.complete', $mission) }}">
                @csrf
                <button type="submit" class="btn btn--sm btn--go cut">{{ Text::get('missions.complete') }}</button>
            </form>
            <div class="spacer"></div>
            <form method="POST" action="{{ route('missions.abandon', $mission) }}">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('missions.abandon') }}</button>
            </form>
        @else
            <form method="POST" action="{{ route('missions.accept', $mission) }}">
                @csrf
                <button type="submit" class="btn btn--sm cut">{{ Text::get('missions.accept') }}</button>
            </form>
            <div class="spacer"></div>
            <form method="POST" action="{{ route('missions.decline', $mission) }}">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('missions.decline') }}</button>
            </form>
        @endif
    </div>
    @endif
</div>
