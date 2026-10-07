@use('App\Support\Text')

{{--
    The invitation to the Discord, shown ONCE to an account that has not closed it (a new one: older accounts were
    marked seen by the migration). It opens by itself (data-show-on-load) and, however it is closed, posts that it was
    seen (data-post-on-close), the same as the patch notes.
--}}
<dialog class="modal" id="discord-invite" data-show-on-load>
    <div class="panel__hd">
        <span class="micro">{{ Text::get('discord.title') }}</span>
        <div class="spacer"></div>
        <button type="button" class="btn btn--sm btn--ghost" data-close aria-label="{{ Text::get('discord.close') }}">✕</button>
    </div>

    <div class="panel__bd stack" style="--gap:var(--s-3)">
        <p>
            {{ Text::get('discord.text') }}
            <a href="{{ Text::get('discord.url') }}" target="_blank" rel="noopener noreferrer">{{ Text::get('discord.url') }}</a>
        </p>
    </div>

    <div class="panel__ft">
        <div class="spacer"></div>
        <button type="button" class="btn btn--sm" data-close>{{ Text::get('discord.close') }}</button>
    </div>

    <form method="POST" action="{{ route('discord.seen') }}" data-post-on-close hidden>
        @csrf
    </form>
</dialog>
