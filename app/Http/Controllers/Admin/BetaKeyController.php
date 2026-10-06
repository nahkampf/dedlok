<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Beta\BetaKeys;
use App\Http\Controllers\Controller;
use App\Models\BetaKey;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The keys testers register with while `features.alpha_testing` is on: a list, one button to make a key, and the
 * copy button that also marks a key as handed out.
 *
 * THE WHOLE SECTION IS 404 WHILE THE FLAG IS OFF, so a route that exists in the file does not exist on a site that
 * has not switched the feature on. `App\Beta\BetaKeys` is the only writer.
 */
class BetaKeyController extends Controller
{
    public function index(): View
    {
        $this->featureOn();

        return view('admin.beta-keys.index', [
            'keys' => BetaKey::query()->with('user.character')->orderByDesc('id')->get(),
        ]);
    }

    public function store(BetaKeys $keys): RedirectResponse
    {
        $this->featureOn();

        $key = $keys->generate();

        return redirect()->route('admin.beta_keys.index')
            ->with('status', Text::get('admin.beta_keys.generated', ['code' => $key->code]));
    }

    /** Copying a key is handing it out. The page's script posts here after it has copied. */
    public function given(BetaKey $betaKey, BetaKeys $keys): JsonResponse
    {
        $this->featureOn();

        $keys->markGivenOut($betaKey);

        return response()->json(['status' => 'ok']);
    }

    private function featureOn(): void
    {
        abort_unless(BetaKeys::enabled(), 404);
    }
}
