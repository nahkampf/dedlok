@use('App\Chat\Markup')
@use('App\Support\Ansi')
@use('App\Support\Text')

{{-- A bio as another player sees it: the profile page and the sheet's preview draw it with this one file, so a draft looks
     as it will. Printed escaped and as typed: it is where ASCII and ANSI art is drawn, so no wrapping and no reflow. --}}
@if (filled($bio))
    <pre class="ascii{{ Ansi::in($bio) ? ' ansi' : '' }}" style="margin:0">{!! Markup::listings(Ansi::render($bio, $ice)) !!}</pre>
@else
    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('profile.bio_empty') }}</p>
@endif
