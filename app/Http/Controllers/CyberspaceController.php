<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\BookmarkKeeper;
use App\Cyberspace\BookmarkKind;
use App\Cyberspace\Grid;
use App\Cyberspace\MapView;
use App\Cyberspace\Navigator;
use App\Cyberspace\Position;
use App\Cyberspace\Probe;
use App\Cyberspace\SweepResult;
use App\Cyberspace\TravelResult;
use App\Models\Bookmark;
use App\Models\Character;
use App\Models\System;
use App\Models\SystemContact;
use App\Models\SystemScan;
use App\Stats\Resolver;
use App\Support\Text;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The map, and everything done from it.
 *
 * ONE SCREEN, drawn whether or not the character is in cyberspace, because the
 * way in is on it. A rail item that appeared only once you were already inside
 * would be one nobody could find — the same rule the deck link follows, for the
 * same reason.
 *
 * Every writer here is a class of its own: Navigator moves, BookmarkKeeper
 * saves. What is left in this controller is what a controller is for — reading
 * the request, choosing the message, and redirecting back.
 */
class CyberspaceController extends Controller
{
    public function index(Request $request, Probe $probe): View
    {
        $character = $this->character($request);
        $grid = Grid::fromConfig();

        $scan = $character->isJackedIn()
            ? SystemScan::query()
                ->where('character_id', $character->id)
                ->where('x', $character->grid_x)
                ->where('y', $character->grid_y)
                ->live()
                ->with('operatingSystem')
                ->first()
            : null;

        /*
         * A pass that finished while they were away is resolved HERE, on the
         * way in. Nothing schedules it: the answer is a function of the clock,
         * so whoever looks next can work it out — the same shape a training
         * deposit catches up in.
         *
         * And it SAYS what it came to. Flashed for this render only, because
         * this is a GET and the news belongs to the screen being drawn rather
         * than to the next one — a player who arrives to find a scan quietly
         * finished has been told nothing about the minutes they spent.
         */
        $settled = $probe->settleAll($character);

        if ($settled !== []) {
            $scan = $scan?->fresh(['operatingSystem']);

            // One line however many finished, because the alternative is a
            // stack of callouts on the way back in. What a player wants here is
            // that something landed and whether any of it was lost; the files
            // themselves say the rest.
            $lost = count(array_filter($settled, static fn ($result): bool => $result->voided));
            $found = array_sum(array_map(static fn ($result): int => count($result->found), $settled));

            $request->session()->now(
                $lost > 0 ? 'error' : 'status',
                $lost > 0
                    ? Text::plural('cyberspace.scan.lost', $lost, ['count' => $lost])
                    : Text::plural('cyberspace.scan.found', $found, ['count' => $found]),
            );
        }

        return view('cyberspace.index', [
            'character' => $character,
            'grid' => $grid,
            'map' => MapView::for($character, $grid),
            'swept' => MapView::sweptCount($character),
            // Both kinds, in one list, most recent first. The screen tells
            // them apart; the query has no reason to.
            'bookmarks' => Bookmark::query()
                ->where('character_id', $character->id)
                ->orderByDesc('id')
                ->get(),
            // What is standing where they are, as they last saw it.
            'here' => $character->isJackedIn()
                ? SystemContact::query()
                    ->where('character_id', $character->id)
                    ->at($character->gridPosition())
                    ->first()
                : null,
            // And what they have found out about it. Keyed by ADDRESS rather
            // than by position, because the file describes a machine and a
            // machine can move — a scan of something that has since relocated
            // is stale in the way the expiry exists to describe.
            'scan' => $scan,
            // Reading a machine takes software that is running, and a screen
            // that hides why the button is dead is worse than one without a
            // button. Both come from the Probe rather than being worked out
            // here, so the screen and the act cannot disagree about either.
            // A FREE scanner, not merely a running one: with two machines
            // being read and two instruments going, the button is dead for a
            // third and the screen should know it.
            'canScan' => $probe->freeAnalyser($character) !== null,
            'scanPasses' => $probe->passesFor($character),
        ]);
    }

    public function jackIn(Request $request, Resolver $resolver): RedirectResponse
    {
        return $this->settle(
            Navigator::fromConfig($resolver)->jackIn($this->character($request)),
            Text::get('cyberspace.status.jacked_in'),
        );
    }

    public function jackOut(Request $request, Resolver $resolver): RedirectResponse
    {
        return $this->settle(
            Navigator::fromConfig($resolver)->jackOut($this->character($request)),
            Text::get('cyberspace.status.jacked_out'),
        );
    }

    /**
     * One step, in any of the eight directions.
     *
     * The target arrives as "x,y" rather than as a direction, because the map
     * sends the CELL that was tapped and the keyboard sends the cell the key
     * points at. A direction would mean the browser knowing where the player
     * is, which is the server's business.
     */
    public function step(Request $request, Resolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'regex:/^\d{1,4},\d{1,4}$/'],
        ]);

        [$x, $y] = array_map(intval(...), explode(',', $data['to']));

        return $this->settle(
            Navigator::fromConfig($resolver)->step($this->character($request), new Position($x, $y)),
            null,
        );
    }

    /**
     * Read the machine you are standing on.
     *
     * No target and no address: you cannot read a machine you are not at,
     * which is what makes walking the grid worth something. One press is one
     * pass, and a pass that learns nothing still spends one — see
     * App\Cyberspace\Probe.
     */
    public function scan(Request $request, Probe $probe): RedirectResponse
    {
        $result = $probe->scan($this->character($request));

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        if ($result->voided) {
            return back()->with('error', Text::get('cyberspace.scan.voided'));
        }

        // A pass just begun has found nothing yet and says when it will have.
        if ($result->isRunning()) {
            // The screen opens on the machine just pointed at, so a player sees
            // the read start rather than a sentence about it having started.
            return back()
                ->with('reading', $result->scan->address)
                ->with('status', Text::get('cyberspace.scan.begun', [
                    'when' => $result->scan->pass_ready_at->diffForHumans(['syntax' => CarbonInterface::DIFF_ABSOLUTE]),
                ]));
        }

        return back()->with('status', Text::plural(
            'cyberspace.scan.found',
            count($result->found),
            ['count' => count($result->found)],
        ));
    }

    /**
     * What the instrument has, as JSON, for the screen that watches it.
     *
     * A pass takes minutes, so the read has to be somewhere a player can look
     * WHILE it happens rather than a line that changes after a reload. This is
     * what the modal polls.
     *
     * IT SETTLES ON THE WAY PAST, which is the same lazy catch-up the map does
     * and the reason the screen updates the moment a pass matures without
     * anything having been scheduled: whoever asks is who resolves it.
     *
     * Keyed by ADDRESS rather than by position, so a read running on the far
     * side of the grid can be watched from wherever its owner is standing —
     * which is the whole point of a read that runs in the background.
     */
    public function reading(Request $request, Probe $probe): JsonResponse
    {
        $character = $this->character($request);

        $data = $request->validate([
            'address' => ['required', 'string', 'max:32'],
        ]);

        $probe->settleAll($character);

        $scan = SystemScan::query()
            ->where('character_id', $character->id)
            ->where('address', strtoupper($data['address']))
            ->live()
            ->with('operatingSystem', 'readingProcess.item')
            ->first();

        if ($scan === null) {
            // Spent, stale, or never started. Not an error: a file that has
            // gone is a thing the screen has to be able to say.
            return response()->json(['found' => false]);
        }

        $system = System::query()->where('address', $scan->address)->first();

        return response()->json([
            'found' => true,
            'address' => $scan->address,
            'name' => $system?->displayName(),
            'position' => (string) $scan->position(),
            'instrument' => $scan->readingProcess?->item?->displayName(),
            'running' => $scan->isRunning(),
            'progress' => round($scan->progress(), 3),
            'readyIn' => $scan->pass_ready_at?->diffForHumans(['syntax' => CarbonInterface::DIFF_ABSOLUTE]),
            'pass' => $scan->passes,
            'passes' => $probe->passesFor($character),
            'complete' => $scan->isComplete(),
            'facts' => $scan->facts(),
        ]);
    }

    public function jump(Request $request, Resolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'max:32'],
        ]);

        return $this->settle(
            Navigator::fromConfig($resolver)->jump($this->character($request), $data['address']),
            null,
        );
    }

    public function recall(Request $request, Bookmark $bookmark, Resolver $resolver): RedirectResponse
    {
        return $this->settle(
            Navigator::fromConfig($resolver)->recall($this->character($request), $bookmark),
            null,
        );
    }

    /**
     * Save a way back: a position, or an address.
     *
     * One route for both, because from the player's side it is one act. Which
     * one is being asked for is decided by `kind` rather than by which field
     * happens to be filled in, so a request carrying both is refused rather
     * than guessed at.
     */
    public function bookmark(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(BookmarkKind::values())],
            'at' => ['required_if:kind,waypoint', 'nullable', 'string', 'regex:/^\d{1,4},\d{1,4}$/'],
            'address' => ['required_if:kind,system', 'nullable', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:48'],
        ]);

        $character = $this->character($request);
        $keeper = BookmarkKeeper::fromConfig();

        if ($data['kind'] === BookmarkKind::Waypoint->value) {
            [$x, $y] = array_map(intval(...), explode(',', $data['at']));
            $result = $keeper->markPosition($character, new Position($x, $y), $data['label'] ?? null);
        } else {
            $result = $keeper->markAddress($character, $data['address'], $data['label'] ?? null);
        }

        return $result->succeeded()
            ? back()->with('status', Text::get('cyberspace.status.saved'))
            : back()->with('error', $result->refusal->message());
    }

    public function forget(Request $request, Bookmark $bookmark): RedirectResponse
    {
        $result = BookmarkKeeper::fromConfig()->forget($this->character($request), $bookmark);

        return $result->succeeded()
            ? back()->with('status', Text::get('cyberspace.status.forgotten'))
            : back()->with('error', $result->refusal->message());
    }

    /**
     * Say what came of trying to go somewhere.
     *
     * A refusal is a value rather than an exception, so every one of these
     * ends the same way: back to the map, with a sentence. An arrival with
     * nothing to report says nothing at all — a player who took one step does
     * not need to be told they took one step, and a banner on every move would
     * be noise the moment walking became the thing you do most.
     */
    private function settle(TravelResult $result, ?string $success): RedirectResponse
    {
        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        $said = $success ?? $this->reportSweep($result->sweep);

        return $said === null ? back() : back()->with('status', $said);
    }

    /**
     * What an arrival is worth mentioning.
     *
     * Only the two things a player could not see for themselves: that the
     * ground was new, and that something they remembered is no longer there.
     * The second is the more important of the two — it is the only way anyone
     * learns the world moved while they were away.
     */
    private function reportSweep(?SweepResult $sweep): ?string
    {
        if ($sweep === null) {
            return null;
        }

        if ($sweep->foundChange()) {
            return Text::plural('cyberspace.status.gone', $sweep->contactsLost);
        }

        return $sweep->foundGround()
            ? Text::plural('cyberspace.status.swept', $sweep->newlySwept)
            : null;
    }

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group.
        return $request->user()->character()->firstOrFail();
    }
}
