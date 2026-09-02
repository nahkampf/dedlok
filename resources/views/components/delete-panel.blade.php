@use('App\Support\Text')

@props(['action', 'name', 'impact', 'id' => 'confirm-delete'])

{{--
    Deleting a definition, and an honest account of what goes with it.

    Almost every foreign key pointing at an item or a skill cascades, so one
    button reaches a long way into live player data. App\Admin\DeletionImpact
    counts how far, and this is where that count is spent — there is no undo
    and no soft delete, so the confirmation is the only place a mistake can
    still be caught.

    The dialog is the kit's <dialog class="modal">, opened by data-open and
    closed by data-close, both wired in ui-kit.js.
--}}
<div class="panel" style="border-color:var(--ember-2)">
    <div class="panel__hd">
        <span class="micro micro--ember">{{ Text::get('admin.actions.delete') }}</span>
    </div>

    <div class="panel__bd stack">
        <p class="muted" style="font-size:var(--fs-sm)">{{ Text::get('admin.impact.irreversible') }}</p>

        @if ($impact->isEmpty())
            <p class="micro">{{ Text::get('admin.impact.nothing') }}</p>
        @else
            <div class="stack" style="--gap:var(--s-2)">
                <span class="micro">{{ Text::get('admin.impact.title') }}</span>
                <ul class="stack" style="--gap:var(--s-1);list-style:none;padding:0;font-size:var(--fs-sm)">
                    @foreach ($impact->counts() as $key => $count)
                        <li class="row row--wrap" style="--gap:var(--s-2)">
                            <span class="dot dot--crit"></span>
                            <span>{{ Text::plural("admin.impact.{$key}", $count) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <button type="button" class="btn btn--sm btn--danger cut" data-open="#{{ $id }}">
                {{ Text::get('admin.actions.delete') }}
            </button>
        </div>
    </div>
</div>

<dialog class="modal" id="{{ $id }}">
    <div class="panel__hd">
        <span class="micro micro--ember">{{ Text::get('admin.impact.confirm', ['name' => $name]) }}</span>
        <div class="spacer"></div>
        <button type="button" class="btn btn--sm btn--ghost" data-close>✕</button>
    </div>

    <div class="panel__bd stack">
        <p style="font-size:var(--fs-sm)">{{ Text::get('admin.impact.irreversible') }}</p>

        @unless ($impact->isEmpty())
            <div class="rule"><span class="micro">{{ Text::get('admin.impact.title') }}</span></div>
            <ul class="stack" style="--gap:var(--s-1);list-style:none;padding:0;font-size:var(--fs-sm)">
                @foreach ($impact->counts() as $key => $count)
                    <li class="row row--wrap" style="--gap:var(--s-2)">
                        <span class="dot dot--crit"></span>
                        <span>{{ Text::plural("admin.impact.{$key}", $count) }}</span>
                    </li>
                @endforeach
            </ul>
        @endunless
    </div>

    <div class="panel__ft row row--wrap">
        <button type="button" class="btn btn--sm btn--ghost" data-close>{{ Text::get('admin.impact.keep') }}</button>
        <div class="spacer"></div>
        {{-- The form lives inside the dialog so the destructive click and the
             confirmation are the same gesture; there is no second button
             elsewhere on the page that could be reached without it. --}}
        <form method="POST" action="{{ $action }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn--sm btn--danger cut">{{ Text::get('admin.impact.destroy') }}</button>
        </form>
    </div>
</dialog>
