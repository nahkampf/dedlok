<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/** The help page: one page, read straight from `strings.help`. Public, like the front page. */
class HelpController extends Controller
{
    public function index(): View
    {
        return view('help.index', ['help' => config('strings.help')]);
    }
}
