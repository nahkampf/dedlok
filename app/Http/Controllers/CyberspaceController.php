<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\Backdoors;
use App\Cyberspace\BookmarkKeeper;
use App\Cyberspace\BookmarkKind;
use App\Cyberspace\ChainBuilder;
use App\Cyberspace\Finder;
use App\Cyberspace\Grid;
use App\Cyberspace\MapView;
use App\Cyberspace\Navigator;
use App\Cyberspace\Position;
use App\Cyberspace\Probe;
use App\Cyberspace\ScanExporter;
use App\Cyberspace\ScanExportRefusal;
use App\Cyberspace\SweepResult;
use App\Cyberspace\TravelRefusal;
use App\Cyberspace\TravelResult;
use App\Models\Bookmark;
use App\Models\Character;
use App\Models\System;
use App\Models\SystemContact;
use App\Models\SystemScan;
use App\Standing\StandingLedger;
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
    public function index(Request $request, Probe $probe, ChainBuilder $chains, Finder $finder): View
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
            // One sentence per system that learned something, by name: several reads can settle at once.
            $news = array_values(array_filter(array_map(
                fn ($result): ?string => $result->scan !== null && $result->found !== [] ? $this->newDataLine($result->scan) : null,
                $settled,
            )));

            $request->session()->now(
                $lost > 0 ? 'error' : 'status',
                $lost > 0
                    ? Text::plural('cyberspace.scan.lost', $lost, ['count' => $lost])
                    : ($news === [] ? Text::get('cyberspace.scan.nothing_new') : implode(' ', $news)),
            );
        }

        // The Locator's search: offered only while the program runs, and answered here because it is a read.
        $locator = null;

        if ($finder->available($character)) {
            $raw = trim((string) $request->query('find', ''));
            $query = $request->query->has('find') ? $finder->parse($raw) : null;
            $locator = ['name' => $finder->program($character)?->item?->displayName(), 'canTraverse' => $finder->canTraverse($character) && $character->isJackedIn(), 'raw' => $raw, 'query' => $query, 'result' => $query === null ? null : $finder->nearest($character, $query, $grid)];
        }

        return view('cyberspace.index', [
            'locator' => $locator,
            'character' => $character,
            // The route a run would take from here: the same call the briefing makes, so what this box shows is what
            // the next run gets. Not tied to a target, so every routing backdoor held is listed.
            'chain' => $chains->for($character),
            'chainCap' => $chains->hopsAllowed($character),
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
            // Told apart from having none at all: a scanner that is busy reading is running, and "you have no
            // scanner running" would be false the moment a read begins.
            'analyserRunning' => $probe->hasAnalyserRunning($character),
            /*
             * WHAT A RUN FROM HERE WOULD LOOK LIKE, before committing to one.
             *
             * The machine is read live rather than from the contact, because
             * this is the thing they are about to attack and a remembered one
             * may not be there — `RunStarter` will refuse that, and the screen
             * should not offer it either.
             *
             */
            'target' => $target = $character->isJackedIn()
                ? System::query()->at($character->gridPosition())->first()
                : null,
            'canRun' => $target !== null && $target->type->isInteractable() && ! $target->isLockedDown(),
            // What the character already holds in this system, for the Interact box: a backdoor row is access, so
            // it is read live off the machine they stand on.
            'heldBackdoor' => $target === null ? null : (new Backdoors)->heldOn($character, $target),
            // Who answers for what you are standing on, and what they make of
            // you. Read off the LIVE system rather than the contact: a
            // sighting remembers a name, and standing is with a row.
            'standing' => $target?->owner?->interactable
                ? (new StandingLedger)->standingFor($character, $target->owner)
                : 0.0,
            // The route and everything else about a run is the briefing's,
            // one screen along. This one only says whether there is anything
            // here to plan against.
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
     * One step of Autotraverse, toward a target. The page calls it every ten seconds; each call is an ordinary step
     * through the Navigator, so a sweep and a hunter's roll happen exactly as when walking by hand. Answers JSON:
     * where they stand, the steps left, or `stop` with the reason when something put an end to it.
     */
    public function traverse(Request $request, Finder $finder, Resolver $resolver): JsonResponse
    {
        $data = $request->validate(['x' => ['required', 'integer', 'min:0', 'max:9999'], 'y' => ['required', 'integer', 'min:0', 'max:9999']]);
        $character = $this->character($request);

        abort_unless($finder->canTraverse($character), 403);

        $target = new Position((int) $data['x'], (int) $data['y']);
        $from = $character->gridPosition();

        if ($from === null) {
            return response()->json(['stop' => true, 'message' => TravelRefusal::NotJackedIn->message()]);
        }

        if ($from->equals($target)) {
            return response()->json(['left' => 0]);
        }

        $result = Navigator::fromConfig($resolver)->step($character, Finder::stepToward($from, $target));

        if (! $result->succeeded()) {
            return response()->json(['stop' => true, 'message' => $result->refusal->message()]);
        }

        if ($result->wasAmbushed()) {
            return response()->json(['stop' => true, 'message' => Text::get('cyberspace.locate.traverse_stopped'), 'hunt' => route('hunt.show')]);
        }

        return response()->json(['x' => $result->position->x, 'y' => $result->position->y, 'left' => $result->position->distanceTo($target)]);
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
            // WHICH way it was lost. The instrument and the machine are two
            // different things to have gone wrong, and one message for both
            // sent players to inspect a deck that was working.
            return back()->with('error', $result->cause?->message() ?? Text::get('cyberspace.scan.voided'));
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

        return back()->with('status', $result->found === [] || $result->scan === null
            ? Text::get('cyberspace.scan.nothing_new')
            : $this->newDataLine($result->scan));
    }

    /** "System Silo - Veritas 123 [DEADBEEF] has new scan data": which system, by name and address. */
    private function newDataLine(SystemScan $scan): string
    {
        $system = System::query()->where('address', $scan->address)->first();

        return Text::get('cyberspace.scan.new_data', [
            'system' => $system?->displayName() ?? Text::get('cyberspace.scan.unknown_system'),
            'address' => $scan->address,
        ]);
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
    /**
     * Stop reading a machine.
     *
     * The pass it had begun stays spent — see Probe::abandon() — so this is
     * not a way to take a look at how a read is going and then undo it. What
     * it frees is the instrument.
     *
     * A read that has already finished or been lost is not an error: the
     * screen showing the button and the press that follows it are minutes
     * apart, and a scan can end in between.
     */
    public function abandon(Request $request, Probe $probe): RedirectResponse
    {
        $character = $this->character($request);

        $data = $request->validate([
            'address' => ['required', 'string', 'max:32'],
        ]);

        $abandoned = $probe->abandon($character, $data['address']);

        return back()->with(
            $abandoned ? 'status' : 'error',
            Text::get($abandoned ? 'cyberspace.reading.abandoned' : 'cyberspace.reading.abandon_nothing'),
        );
    }

    /**
     * File a finished read as something you can carry.
     *
     * THE ONE THING ON THIS SCREEN THAT COSTS SOMETHING. Every other button on
     * the reading dialog either watches the instrument or stops it; this one
     * spends the read to make an object out of it, and the machine stops being
     * scanned on the map the moment it lands. No confirmation, because the
     * button says what it does and the file it makes is not destroyed by
     * making it — it is in the stash, and destroying it is its own dialog.
     */
    public function export(Request $request, ScanExporter $exporter): RedirectResponse
    {
        $character = $this->character($request);

        $data = $request->validate([
            'address' => ['required', 'string', 'max:32'],
        ]);

        $result = $exporter->export($character, $data['address']);

        if ($result instanceof ScanExportRefusal) {
            return back()->with('error', $result->message());
        }

        return back()->with('status', Text::get('cyberspace.reading.exported', [
            'file' => $result->scanFile()?->filename() ?? strtoupper($data['address']),
        ]));
    }

    /**
     * Save the two standing instructions.
     *
     * A PLAY STYLE RATHER THAN A PER-RUN DECISION, which is why they live on
     * the character and are set from the map rather than from inside a run —
     * re-choosing them every time would train everybody to leave them alone,
     * and changing them mid-run would be changing the rules of a fight while
     * standing in it.
     *
     * Zero turns one off, which is why the floor is 0 and not 1: it saves a
     * second column whose only job would be to say whether the first one counts.
     */
    public function thresholds(Request $request): RedirectResponse
    {
        $character = $this->character($request);

        $data = $request->validate([
            'egress_trace_at' => ['required', 'integer', 'min:0', 'max:100'],
            'egress_si_at' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $character->forceFill($data)->save();

        return back()->with('status', Text::get('cyberspace.run.thresholds.saved'));
    }

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
            'complete' => $scan->isComplete(),
            'filed' => $scan->filed_at !== null,
            // The character, so the operating system can say whether they can
            // operate it — a fact that is also a gate.
            'facts' => $scan->facts($character),
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

        // Arrived, but not alone. Whatever the move would ordinarily have
        // said is moot — the hunt screen is the only place left to go, and
        // EnsureHuntIsResolved would send the next request there anyway.
        if ($result->wasAmbushed()) {
            return redirect()->route('hunt.show');
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
