{{--
    Locale switcher. The URL carries no locale segment, so switching is a POST
    that writes the choice to the session (and to the account when signed in),
    then returns to the page you were on.

    Renders nothing while only one language is configured — a select with a
    single option is noise. Add a locale to config/i18n.php and it reappears.
--}}
@php($locales = config('i18n.locales', []))

@if (count($locales) > 1)
    <form method="POST" action="{{ route('locale.update') }}" class="row" style="--gap:var(--s-2)">
        @csrf
        <label class="sr-only" for="locale-select">{{ __('ui.locale.label') }}</label>
        <select id="locale-select" name="locale" class="select"
                style="width:auto;min-height:26px;padding:2px 28px 2px 8px;font-size:var(--fs-xs)"
                onchange="this.form.submit()">
            @foreach ($locales as $code => $meta)
                <option value="{{ $code }}" @selected(app()->getLocale() === $code)>
                    {{ $meta['native'] }}
                </option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn btn--sm">{{ __('ui.action.save') }}</button></noscript>
    </form>
@endif
