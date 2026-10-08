<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Characters\CurrentProcesses;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The fragment behind the top bar's *Current processes* modal: fetched when it opens, never drawn into every page.
 * Read only; see App\Characters\CurrentProcesses for what is in it and why.
 */
class CurrentProcessesController extends Controller
{
    public function index(Request $request, CurrentProcesses $processes): View
    {
        $character = $request->user()->character()->firstOrFail();

        return view('processes._list', ['rows' => $processes->for($character)]);
    }
}
