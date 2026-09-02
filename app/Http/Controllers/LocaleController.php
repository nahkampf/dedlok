<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('i18n.locales')))],
        ]);

        $locale = $validated['locale'];

        $request->session()->put(config('i18n.session_key', 'locale'), $locale);

        // Signed-in players get it persisted, so the choice survives a new device.
        $request->user()?->forceFill(['locale' => $locale])->save();

        return back()->with('status', __('ui.locale.switched', [
            'language' => config("i18n.locales.{$locale}.native"),
        ], $locale));
    }
}
