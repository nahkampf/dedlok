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
        @if ($activity->kind === ActivityKind::Travel)
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

        @unless ($activity->cancelling)
            <form method="POST" action="{{ route('city.cancel') }}">
                @csrf
                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('city.cancel') }}</button>
            </form>
        @endunless
    </div>
</div>
