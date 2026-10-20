@use('App\City\ActivityKind')
@use('App\Support\Text')
@props(['activity', 'remaining', 'route', 'inPass'])

{{-- What is under way. The countdown is SECONDS REMAINING from the server and
     reloads the page when it ends: the request's lazy settle is what moves
     anything. --}}
<div class="panel">
    <div class="panel__hd">
        <span class="micro">{{ Text::get('city.under_way') }}</span>
        <div class="spacer"></div>
        <span class="tag"><span data-countdown="{{ $remaining }}">{{ intdiv($remaining, 60) }}:{{ str_pad((string) ($remaining % 60), 2, '0', STR_PAD_LEFT) }}</span></span>
    </div>
    <div class="panel__bd stack" style="--gap:var(--s-3)">
        @if ($activity->kind === ActivityKind::Surgery)
            <p style="margin:0">{{ Text::get('city.surgery.on_the_table') }}</p>
        @elseif ($activity->kind === ActivityKind::Rest)
            <p style="margin:0">{{ Text::get('safehouse.resting') }}</p>
        @elseif (in_array($activity->kind, [ActivityKind::Workout, ActivityKind::Study, ActivityKind::Simstim], true))
            <p style="margin:0">{{ Text::get('safehouse.doing.'.$activity->kind->value) }}</p>
        @elseif ($activity->kind === ActivityKind::Stakeout)
            <p style="margin:0">{{ Text::get('safehouse.lead.staking_out') }}</p>
        @elseif ($activity->kind === ActivityKind::ScopeOut)
            <p style="margin:0">{{ Text::get('safehouse.lead.sneaking') }}</p>
        @elseif ($activity->kind === ActivityKind::Travel)
            <p style="margin:0">
                {{ Text::get('city.travelling', ['district' => $inPass, 'to' => end($route) ?: '', 'mode' => $activity->mode?->label()]) }}
            </p>
            <p class="micro" style="margin:0;color:var(--ink-3)">{{ implode(' → ', $route) }}</p>
            @if ($activity->cancelling)
                <p class="micro" style="margin:0">{{ Text::get('city.stopping', ['district' => $inPass]) }}</p>
            @endif
        @else
            <p style="margin:0">{{ Text::get('city.exploring', ['district' => $inPass]) }}</p>
        @endif

        @if ($activity->kind->isSession())
            <form method="POST" action="{{ route('safehouse.rest.stop') }}">
                @csrf
                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get($activity->kind === ActivityKind::Rest ? 'safehouse.stop_resting' : 'safehouse.stop.'.$activity->kind->value) }}</button>
            </form>
        @endif

        @if ($activity->kind === ActivityKind::Stakeout || $activity->kind === ActivityKind::ScopeOut)
            <form method="POST" action="{{ route('lead.stop') }}">
                @csrf
                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get($activity->kind === ActivityKind::Stakeout ? 'safehouse.lead.stop_stakeout' : 'safehouse.lead.stop_scope_out') }}</button>
            </form>
        @endif

        @unless ($activity->cancelling || $activity->kind->isSession() || in_array($activity->kind, [ActivityKind::Surgery, ActivityKind::Stakeout, ActivityKind::ScopeOut], true))
            <form method="POST" action="{{ route('city.cancel') }}">
                @csrf
                {{-- A trip is STOPPED, not cancelled: it finishes the pass it is in and leaves you in that district. --}}
                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get($activity->kind === ActivityKind::Travel ? 'city.stop_travelling' : 'city.cancel') }}</button>
            </form>
        @endunless
    </div>
</div>
