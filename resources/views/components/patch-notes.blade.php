@use('App\Support\Text')
@props(['id', 'notes', 'title', 'unseen' => false])

{{--
    The patch notes, in a modal: one section per release, newest first.

    Rendered twice on the front page. The version link opens every note; the
    UNSEEN copy opens by itself (data-show-on-load) and, however it is closed —
    button, Esc or backdrop — posts that it was read (data-post-on-close).
--}}
<dialog class="modal" id="{{ $id }}" @if ($unseen) data-show-on-load @endif>
    <div class="panel__hd">
        <span class="micro">{{ $title }}</span>
        <div class="spacer"></div>
        <button type="button" class="btn btn--sm btn--ghost" data-close aria-label="{{ Text::get('home.patch_notes.close') }}">✕</button>
    </div>

    <div class="panel__bd stack" style="--gap:var(--s-4)">
        @foreach ($notes as $note)
            <section class="stack" style="--gap:var(--s-2)">
                <div class="rule">
                    <span class="micro">{{ Text::get('home.patch_notes.heading', ['version' => $note['version'], 'date' => $note['date']]) }}</span>
                </div>
                {{-- Rendered from PATCHNOTES.md with raw HTML stripped, which
                     is what makes printing it unescaped safe. --}}
                <div class="prose">{!! $note['html'] !!}</div>
            </section>
        @endforeach
    </div>

    <div class="panel__ft">
        <div class="spacer"></div>
        <button type="button" class="btn btn--sm" data-close>{{ Text::get('home.patch_notes.close') }}</button>
    </div>

    @if ($unseen)
        <form method="POST" action="{{ route('patch-notes.seen') }}" data-post-on-close hidden>
            @csrf
        </form>
    @endif
</dialog>
