<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\Console\Output\BufferedOutput;

/** Runs the commands listed in `config/artisan_actions.php`, and nothing else, so production does not need a shell for them. */
class ArtisanController extends Controller
{
    public function index(): View
    {
        $all = Artisan::all();

        return view('admin.artisan.index', [
            'actions' => collect((array) config('artisan_actions'))
                ->filter(fn (string $name): bool => isset($all[$name]))
                ->map(fn (string $name): array => ['name' => $name, 'description' => $all[$name]->getDescription()])
                ->values(),
        ]);
    }

    public function run(Request $request, string $command): RedirectResponse
    {
        abort_unless(in_array($command, (array) config('artisan_actions'), true), 404);

        $output = new BufferedOutput;

        try {
            $code = Artisan::call($command, [], $output);
        } catch (\Throwable $e) {
            $code = 1;
            $output->writeln($e::class.': '.$e->getMessage());
        }

        Log::info('Admin ran an artisan action', ['command' => $command, 'user_id' => $request->user()->getKey(), 'exit' => $code]);

        return back()->with([
            'status' => Text::get($code === 0 ? 'admin.artisan.done' : 'admin.artisan.failed', ['command' => $command]),
            'artisan_output' => mb_substr(trim($output->fetch()), 0, 20000),
        ]);
    }
}
