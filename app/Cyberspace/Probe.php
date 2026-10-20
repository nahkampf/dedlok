<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Characters\Fatigue;
use App\Checks\CheckRoller;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\System;
use App\Models\SystemScan;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Programs\ProgramKind;
use App\Stats\Resolver;
use App\Stats\SkillStats;
use App\Support\Trace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Finding out what a machine is. The only writer of `system_scans`.
 *
 * A grade spans magnitudes — the easiest system sits inside a starting
 * character's Synaptic Integrity and the hardest can flatline somebody at 200 —
 * so the difference between a target and an ending is a number you are meant to
 * discover BEFORE you approach. This is the discovering, and it is the first
 * thing in the game to roll a check.
 *
 * A PASS IS SOMETHING YOU START AND COME BACK TO. It takes minutes — about one
 * against a grade 1 and sixteen against a grade 9, before skill and software cut
 * it — so reading the bottom of the grid is something done in passing and
 * reading the top is an evening somebody decides to spend. It is the first
 * thing in the game that makes standing still in cyberspace cost anything.
 *
 * A READ RUNS IN THE BACKGROUND. Once begun it belongs to the INSTANCE of the
 * scanner doing it — a row in `inventory_items` with `running_on_deck_id` set —
 * and not to where its owner happens to be standing. Walk to the next machine,
 * jack out, sign off: the instrument is still pointed at it, and the answer is
 * waiting when you come back. What ends a read early is the instrument
 * stopping, which is the one thing a player chose.
 *
 * SO TWO INSTRUMENTS READ TWO MACHINES. Every running copy is its own process
 * with its own compute and memory, and how many of one program a character may
 * run at once is `ProgramRunner`'s question — one by default, more with
 * Multithreading, and only ever one for software that cannot multitask.
 *
 * SETTLING IS LAZY. A matured pass is resolved by whoever next looks — the map
 * screen settles EVERY read a character has, not only the one underfoot, since
 * the others are the whole point — rather than by a scheduler that has to have
 * been running. The answer is a function of the clock, so anything that reads
 * it can work it out.
 *
 * THE ROLL IS MADE WHEN THE PASS MATURES, never when it is begun. Nothing is
 * decided in advance and kept waiting to be revealed, so the character whose
 * modifiers are asked is the one who finished the read.
 *
 * ONE PASS IS ONE ROLL PER THING NOT YET KNOWN. A pass that learns nothing is
 * the ordinary outcome against something above a character's reach, and a pass
 * that learns half of it is why a scan is a row that can be added to rather
 * than a yes or a no. **A pass is spent when BEGUN**: walking away from a
 * half-finished read costs it, which is what stops a read being dodged by
 * starting five and finishing the one that looks best.
 *
 * ONE PRESS IS ONE READ, AND A READ RUNS UNTIL IT HAS THE PICTURE. A pass that
 * matures without finishing it starts the next one itself, with the same
 * instrument, and goes round for as long as the file is worth anything. THERE
 * IS NO PASS BUDGET: a counted allowance stopped the ordinary case — a machine
 * a character can read, given the minutes — at a half-filled file and a refusal
 * telling them to come back in two days, which is a wait rather than a decision.
 *
 * WHAT STOPS IT IS THE EXPIRY WINDOW. A scan is a file that perishes, so the
 * hours in `cyberspace.scan.lifetime_hours` are the budget, and a machine above
 * a character's reach spends all of them without ever completing — which is
 * what keeps a free act from being ground until it succeeds. The other two ways
 * a read stops are the instrument being unloaded and the machine going away.
 *
 * THE DIFFICULTY IS THE MACHINE'S, not the character's guess at it. A grade 9
 * is hard to read whether or not anybody knows it is a grade 9, which is the
 * only way round that does not leak the answer into the question.
 *
 * READING A MACHINE TAKES SOFTWARE, and the software has to be RUNNING. A
 * scanner is a tool rather than a talent: an analyser on the equipped deck is
 * what turns standing in front of a machine into finding out what it is, and it
 * spends compute and memory the whole time it is doing so. That is the answer
 * to the question CYBERSPACE.md said to settle before this class was written.
 *
 * WHAT A BETTER ANALYSER BUYS IS DATA, NOT CODE. Versions differ by what they
 * carry on their own row, through the two channels every program already has:
 *
 *  - `programs.modifiers` targeting `check.systemprofiling` — a steadier read,
 *    which reaches this class through the resolver without it knowing analysers
 *    exist at all;
 *  - the same, targeting `scan_duration` — the same picture, sooner, which is
 *    what a better instrument is worth now that the window rather than a pass
 *    count is what a read spends.
 *
 * So a Mk II is an item row and a Mk III is another one. Nothing here changes
 * when the catalogue grows, which is the whole reason the bonus is a modifier
 * rather than a column this class reads.
 *
 * WHAT A SCAN CAN FIND OUT IS WHAT A SYSTEM CURRENTLY HAS. Grade and operating
 * system today; the IDS, the ICE, the tracer and any cloaked systems join the
 * list as they are built, each as a nullable column and a line in
 * `SystemScan::unknowns()`. Nothing else has to change, which is the point of
 * the unknowns list being on the row rather than here.
 */
final class Probe
{
    public function __construct(
        private readonly CheckRoller $roller,
        private readonly Resolver $resolver,
        private readonly int $lifetimeHours,
        private readonly string $skillSlug,
        private readonly Fatigue $fatigue = new Fatigue,
    ) {}

    public static function fromConfig(?CheckRoller $roller = null, ?Resolver $resolver = null): self
    {
        return new self(
            $roller ?? CheckRoller::fromConfig(),
            $resolver ?? app(Resolver::class),
            (int) Config::get('cyberspace.scan.lifetime_hours', 48),
            (string) Config::get('cyberspace.scan.skill', 'systemprofiling'),
        );
    }

    /**
     * Scan whatever this character is standing on.
     *
     * The position is the target: there is no addressing here, because you
     * cannot read a machine you are not at. That is what makes walking the grid
     * worth something and what keeps a scan honest about having been there.
     */
    public function scan(Character $character, ?CarbonInterface $now = null): ProbeResult
    {
        return Trace::scope($character, 'scan.start', fn () => $this->scanTraced($character, $now), []);
    }

    private function scanTraced(Character $character, ?CarbonInterface $now = null): ProbeResult
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $at = $character->gridPosition();

        if ($at === null) {
            return ProbeResult::refused(ProbeRefusal::NotJackedIn);
        }

        $system = System::query()->at($at)->first();

        if ($system === null) {
            return ProbeResult::refused(ProbeRefusal::NothingThere);
        }

        if (! $system->type->isScannable()) {
            return ProbeResult::refused(ProbeRefusal::NotScannable);
        }

        /*
         * EVERYTHING THAT HAS MATURED IS SETTLED FIRST, not only the read on
         * this machine. A pass that finished an hour ago on the far side of
         * the grid is still holding its instrument until somebody looks, and
         * the instrument is what a second read needs.
         */
        $settled = $this->settleAll($character, $now);
        $scan = $this->fileFor($character, $system);

        if ($scan->isRunning($now)) {
            return ProbeResult::refused(ProbeRefusal::Busy);
        }

        // A read on THIS machine that has just been lost is reported rather
        // than immediately replaced: pressing Scan again is the player's
        // decision to spend another pass, and they should make it knowing the
        // last one is gone.
        foreach ($settled as $result) {
            if ($result->voided && $result->scan?->address === $system->address) {
                return $result;
            }
        }

        if ($this->freeAnalyser($character) === null) {
            return ProbeResult::refused(
                $this->hasAnalyserRunning($character)
                    // Every scanner they have running is already reading
                    // something. Another machine needs another instance, which
                    // needs the compute and a thread to put it in.
                    ? ProbeRefusal::AllBusy
                    : ProbeRefusal::NoAnalyser,
            );
        }

        if ($scan->isComplete()) {
            // A read that has been exported (and so perhaps sold) is not the end of it: pressing Scan again is a
            // RESCAN, a new file from nothing, so a job that wants a file of this system can be served again. One
            // that has NOT been exported is still the player's to export first, since a rescan would replace it.
            if ($scan->filed_at === null) {
                return ProbeResult::refused(ProbeRefusal::AlreadyKnown);
            }

            Trace::note('scan.rescan', ['address' => $system->address, 'replaces' => $scan->getKey()]);
            $scan->delete();
            $scan = $this->blankFile($character, $system);
        }

        $result = $this->begin($character, $system, $scan, $now);

        // Starting a read tires. A refused one has not started.
        if ($result->succeeded()) {
            $this->fatigue->charge($character, 'scan');
        }

        return $result;
    }

    /**
     * Settle every read this character has that has matured.
     *
     * The map calls this rather than settling the one underfoot, because the
     * others are the point: a read runs while its owner is somewhere else, and
     * the screen they come back to is where they find out what it came to.
     *
     * @return list<ProbeResult>
     */
    public function settleAll(Character $character, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());

        $matured = SystemScan::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('pass_ready_at')
            ->where('pass_ready_at', '<=', $now)
            ->get();

        $results = [];

        foreach ($matured as $scan) {
            $result = $this->settle($character, $scan, $now);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Resolve a pass that has matured, if one has.
     *
     * Safe to call on anything, which is the point: the map screen calls it on
     * the way in without knowing whether there is anything to settle, and a
     * scan with nothing running is left exactly as it is.
     */
    public function settle(Character $character, ?SystemScan $scan, ?CarbonInterface $now = null): ?ProbeResult
    {
        return Trace::scope($character, 'scan.settle', fn () => $this->settleTraced($character, $scan, $now), ['scan' => Trace::describe($scan)]);
    }

    private function settleTraced(Character $character, ?SystemScan $scan, ?CarbonInterface $now = null): ?ProbeResult
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $last = null;

        /*
         * A READ CATCHES UP, IT DOES NOT ADVANCE ONE PASS PER VISIT.
         *
         * Settling is lazy — nothing is scheduled, and a matured pass waits
         * for somebody to look. That is the design and it is fine. What is
         * NOT fine is a read that resolves exactly one pass however long its
         * owner was away: press Scan, come back an hour later, and find that
         * a five-pass read has managed two of them. "One press is one read"
         * stops being true the moment the read needs five presses to go round,
         * even if the presses are called page loads.
         *
         * So this walks the clock forward instead. Each pass begins when the
         * one before it MATURED rather than when somebody happened to open the
         * screen, and the loop keeps going while the pass it just started has
         * also already finished. An hour away is an hour of reading.
         *
         * It terminates because every turn either stops the read or begins a
         * pass whose maturity is strictly later, and a pass maturing past the
         * file's expiry is not begun at all.
         *
         * ponytail: the walk is one pass at a time, so an hour of catch-up is
         * an hour's worth of saves and lookups. Bounded by the expiry window
         * over the pass duration — 180 at the top of the grid, 2880 at the
         * bottom. Hoist the system and instrument lookups out of the loop and
         * save once at the end if it ever bites.
         */
        while ($scan !== null && $scan->pass_ready_at !== null && ! $scan->pass_ready_at->isAfter($now)) {
            $result = $this->settleOnce($character, $scan, $now);

            if ($result === null) {
                break;
            }

            $last = $result;

            // A lost read stops here rather than starting another: the player
            // decides whether to spend the next pass, which is the same rule
            // `scan()` follows when it reports one instead of replacing it.
            if ($result->voided) {
                break;
            }

            $scan = $result->scan;
        }

        return $last;
    }

    /** One pass, resolved. The loop above is what makes a read catch up. */
    private function settleOnce(Character $character, ?SystemScan $scan, CarbonImmutable $now): ?ProbeResult
    {
        if ($scan === null || $scan->pass_ready_at === null || $scan->pass_ready_at->isAfter($now)) {
            return null;
        }

        $system = System::query()->where('address', $scan->address)->first();

        // The machine it was reading has gone — burned, or relocated and
        // wearing a new address. The instrument has nothing to report and the
        // pass is spent, which is the honest outcome rather than a refund.
        if ($system === null) {
            return $this->void($scan, VoidCause::TargetGone);
        }

        /*
         * THE SCANNER HAS TO STILL BE RUNNING AT THE END.
         *
         * A read is minutes of an instrument pointed at a machine, not a
         * button that pays out later: unloading it halfway through loses what
         * it had, and the pass is spent all the same. That is what makes the
         * compute an analyser spends a real cost — it has to be spent for the
         * whole read, on a deck that could be running something else.
         *
         * Checked HERE rather than when the pass began, which is the only
         * place it can be: what a character was carrying minutes ago is not
         * what they are carrying now.
         */
        if (! $this->stillReading($scan)) {
            return $this->void($scan, VoidCause::InstrumentStopped);
        }

        return $this->resolve($character, $system, $scan, $now);
    }

    /**
     * Is there a scanner running on the deck they are carrying?
     *
     * Running, not owned: an analyser in the stash is a file, and the compute
     * and memory it spends while running are what a scan costs. Only the
     * EQUIPPED deck counts, for the reason every other question about a
     * character's software does — a program running on a spare deck is
     * spending that deck's budget and nobody's attention.
     */
    public function hasAnalyserRunning(Character $character): bool
    {
        return $this->analysers($character)->exists();
    }

    /**
     * Is the instrument that began this read still on it?
     *
     * Three ways it is not, and all three are the same event to a player.
     * The row is GONE, because stopping a stackable program merges it back
     * into its pile and deletes it — the pointer nulls itself. The row is no
     * longer RUNNING, which is what stopping an unstackable one leaves behind.
     * Or it is running again but was restarted, which `started_at` gives away:
     * an instrument that was off for part of a read did not finish it, and
     * without this a player could free the compute for a quarter of an hour
     * and switch it back on before looking.
     */
    private function stillReading(SystemScan $scan): bool
    {
        if ($scan->reading_process_id === null || $scan->pass_started_at === null) {
            return false;
        }

        $process = InventoryItem::query()->find($scan->reading_process_id);

        return $process !== null
            && $process->running_on_deck_id !== null
            && $process->started_at !== null
            && ! $process->started_at->isAfter($scan->pass_started_at);
    }

    /**
     * Is the copy that just finished a pass still loaded?
     *
     * Asked before a read goes round again, and it is deliberately the weaker
     * question than `stillReading()` — that one asks whether the SAME instance
     * ran the whole pass, which is about a read that is already under way.
     * This one only asks whether there is still something to read WITH.
     */
    private function instrumentIsRunning(?int $instrument): bool
    {
        if ($instrument === null) {
            return false;
        }

        $process = InventoryItem::query()->find($instrument);

        return $process !== null && $process->running_on_deck_id !== null;
    }

    /**
     * A running scanner that is not already reading something.
     *
     * The instrument is the scarce thing, not the software: a second machine
     * needs a second instance, which costs another lot of compute and a thread
     * to put it in. Null means every scanner they have going is busy — or
     * there are none.
     */
    public function freeAnalyser(Character $character): ?InventoryItem
    {
        $reading = SystemScan::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('reading_process_id')
            ->whereNotNull('pass_ready_at')
            ->pluck('reading_process_id')
            ->all();

        return $this->analysers($character)->whereKeyNot($reading)->first();
    }

    /**
     * Has one been running SINCE a moment — without interruption?
     *
     * `inventory_items.started_at` is what makes this answerable without
     * recording a history: starting a program writes the moment, so an
     * analyser that was stopped and started again during the read carries a
     * later one and fails this. **A read that was interrupted is a read that
     * was lost**, and asking only whether one is running NOW would let a
     * player free the compute for fifteen minutes and turn it back on before
     * looking.
     *
     * Swapping to a different analyser mid-read fails it too, and correctly:
     * the instrument that finishes the read has to be the one that started it.
     */
    public function hasAnalyserRunningSince(Character $character, ?\DateTimeInterface $since): bool
    {
        if ($since === null) {
            return $this->hasAnalyserRunning($character);
        }

        return $this->analysers($character)->where('started_at', '<=', $since)->exists();
    }

    /** @return Builder<InventoryItem> */
    private function analysers(Character $character): Builder
    {
        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->where('running_on_deck_id', $character->equipped_deck_id ?? 0)
            ->whereHas('item.program', static fn ($query) => $query->where('kind', ProgramKind::Analyser->value));
    }

    /** What the odds are of reading this machine, for a screen that wants to say so. */
    public function chanceFor(Character $character, System $system): float
    {
        return $this->roller->chance($this->reading($character), $this->difficultyOf($system), 'scan');
    }

    /**
     * The file this character has on this machine, started if there is none.
     *
     * A spent or expired file is REPLACED rather than added to: the whole point
     * of perishing is that what it said stopped being true, so carrying its
     * answers forward would be worse than having none.
     */
    private function fileFor(Character $character, System $system): SystemScan
    {
        $existing = SystemScan::query()
            ->where('character_id', $character->getKey())
            ->where('address', $system->address)
            ->first();

        if ($existing !== null && ! $existing->isSpent()) {
            return $existing;
        }

        $existing?->delete();

        return $this->blankFile($character, $system);
    }

    private function blankFile(Character $character, System $system): SystemScan
    {
        return new SystemScan([
            'character_id' => $character->getKey(),
            'address' => $system->address,
            'grid_id' => $system->position()->grid,
            'x' => $system->x,
            'y' => $system->y,
            'passes' => 0,
            'scanned_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addHours($this->lifetimeHours),
        ]);
    }

    /**
     * Point the instrument at it and start the clock.
     *
     * The pass is counted here rather than when it finishes, because it is
     * spent either way — see the class docblock.
     */
    private function begin(
        Character $character,
        System $system,
        SystemScan $scan,
        CarbonImmutable $now,
        ?int $instrument = null,
    ): ProbeResult {
        $scan->passes++;
        $scan->pass_started_at = $now;
        $scan->pass_ready_at = $now->addSeconds($this->durationFor($character, $system));
        // WHICH instrument, not merely that there was one. With two scanners
        // running on two machines, "is a scanner going?" stops being the
        // question — this read is finished by the copy that started it or by
        // nobody. A continuing read names the copy already on the machine,
        // which `freeAnalyser()` would not offer, since it is reading this.
        $scan->reading_process_id = $instrument ?? $this->freeAnalyser($character)?->getKey();

        DB::transaction(static fn () => $scan->save());

        return ProbeResult::begun($scan);
    }

    /**
     * Stop reading this machine, because the player said so.
     *
     * NOT A REFUND. The pass it had begun is spent, the same way a pass lost
     * to an unloaded scanner is — passes are spent when BEGUN, and a player who
     * could take one back by pressing a button would have a free look at
     * whether a read was going well.
     *
     * What it DOES buy is the instrument. Clearing the pointer frees the
     * analyser to be aimed at something else immediately, which is the whole
     * reason to abandon one: a scanner grinding away at a machine you have
     * lost interest in is compute you are not spending on the machine you have
     * moved on to. The program keeps running — abandoning a read is not
     * unloading the software.
     *
     * The file itself SURVIVES, with whatever it had already found out. It
     * goes stale on its own clock like any other scan, so coming back to an
     * abandoned read means continuing it rather than starting again.
     *
     * Returns false when there was nothing to abandon, which is an ordinary
     * answer: a read can finish, or be voided, between the screen rendering
     * the button and somebody pressing it.
     */
    public function abandon(Character $character, string $address): bool
    {
        $scan = SystemScan::query()
            ->where('character_id', $character->id)
            ->where('address', strtoupper($address))
            ->live()
            ->first();

        if ($scan === null || $scan->reading_process_id === null) {
            return false;
        }

        $scan->forceFill([
            'pass_started_at' => null,
            'pass_ready_at' => null,
            'reading_process_id' => null,
        ])->save();

        return true;
    }

    /**
     * The read came to nothing. Stop the clock and keep the pass spent.
     *
     * One place for both ways of losing one — the machine stopped answering,
     * or the instrument stopped listening — because what the player is told
     * and what the row ends up holding are the same in each case.
     */
    private function void(SystemScan $scan, VoidCause $cause): ProbeResult
    {
        $scan->forceFill([
            'pass_started_at' => null,
            'pass_ready_at' => null,
            'reading_process_id' => null,
        ])->save();

        return ProbeResult::voided($scan, $cause);
    }

    /** The roll itself, made at the moment the pass matures. */
    private function resolve(Character $character, System $system, SystemScan $scan, CarbonImmutable $now): ProbeResult
    {
        $reading = $this->reading($character);
        $difficulty = $this->difficultyOf($system);

        $found = [];
        $rolls = [];

        /*
         * A CLOAK IS TESTED ONCE PER FILE, before anything else is read. The owner and anybody admitted are never fooled; for
         * anybody else it is one roll of the reading against the Cloak's stealth. Seen through, the facts below come from the
         * real fortress; held, from the false face, and the file never learns there was a disguise.
         */
        $source = $system;

        if (FortressCloak::active($system)) {
            $scan->cloak_broken ??= FortressAccess::admitted($character, $system)
                || $this->roller->roll($reading, FortressCloak::stealth($system), 'scan')->passed;
            $source = $scan->cloak_broken ? $system : FortressCloak::face($system);
        }

        Trace::note('scan.resolve', [
            'address' => $system->address, 'reading' => $reading, 'difficulty' => $difficulty,
            'unknowns' => $scan->unknowns(), 'scan' => Trace::describe($scan),
        ]);

        foreach ($scan->unknowns() as $unknown) {
            $outcome = $this->roller->roll($reading, $difficulty, 'scan');
            $rolls[$unknown] = $outcome;

            if (! $outcome->passed) {
                continue;
            }

            $found[] = $unknown;

            match ($unknown) {
                'grade' => $scan->grade = $source->grade,
                'os' => $scan->os_skill_id = $source->os_skill_id,
                // A machine with no intrusion detection answers ZERO rather
                // than staying null: "there is none" is a finding, and it is
                // most of what somebody scans a machine to hear.
                'ids' => $scan->ids_detection = $source->ids_detection,
                'tracer' => [
                    $scan->tracer_detection = $source->tracer_detection,
                    $scan->tracer_rate = $source->tracer_rate,
                ],
                // The LEVEL, never the row id: a scan outlives the machine and
                // must not point at a catalogue row that could be deleted.
                // Zero says the machine has no guard, which is a finding.
                //
                // The bounty comes with it, in the same roll: knowing there is
                // an ICE XII and knowing the owner answers for it are one
                // assessment, and half of it is half a decision.
                'ice' => [
                    $scan->ice_level = $source->ice?->level ?? 0,
                    $scan->ice_bounty = $source->carriesBounty(),
                ],
                // Nothing can read a cloak, so ScanFact::Cloaked is not
                // discoverable and the loop above never yields it. Named all
                // the same: the day it becomes readable, this is a pointed
                // failure naming the one place to write the read, rather than
                // an UnhandledMatchError from a match that quietly fell short.
                'cloaked' => throw new \LogicException(
                    'ScanFact::Cloaked became discoverable with no read behind it.'
                ),
            };
        }

        /*
         * THE PASS THAT FINISHED THE PICTURE, and only that one.
         *
         * `resolve()` runs against an incomplete file, so an empty `$found`
         * cannot have completed anything and a complete file after a pass that
         * found something is a file this pass completed. No extra column and
         * nothing to keep in step.
         *
         * A SYSTEM RE-READ AFTER ITS FILE EXPIRED COUNTS AGAIN, deliberately:
         * a scan is a file that perishes, and pretending otherwise would mean
         * storing every address ever read for ever. Reading the same system a
         * thousand times costs a thousand systems' worth of passes and
         * minutes, so there is nothing here worth farming.
         */
        if ($found !== [] && $scan->isComplete()) {
            (new Achievements)->record($character, Metric::SystemsScanned);

            // THE READ IS THE FIRST THING THE INBOX WAS BUILT FOR. A pass
            // takes minutes and a read takes as many as it needs, so the
            // player who pressed Scan is usually somewhere else by the time it
            // answers — and the sweep exists precisely so something NOTICES,
            // which is only worth anything if there is somewhere to say it.
            //
            // THE FILE ITSELF RIDES ALONG, as the rows the scanner window and the briefing show: a message is
            // never recomposed and the system may have moved or burned by the time anybody reads it, so what
            // the read found is kept with the sentence that says it finished.
            (new Notifier)->send($character, Category::Cyberspace, 'scan_complete', [
                'system' => $system->displayName(),
                'address' => $system->address,
            ], ['facts' => $scan->facts($character), 'address' => $system->address]);
        }

        $instrument = $scan->reading_process_id;

        // WHEN THIS PASS ACTUALLY FINISHED, kept before it is cleared. A
        // continuing read starts its next pass from here rather than from
        // `$now`, so the minutes somebody spent away are minutes the
        // instrument spent reading instead of minutes it spent waiting to be
        // noticed.
        $maturedAt = $scan->pass_ready_at !== null
            ? CarbonImmutable::instance($scan->pass_ready_at)
            : $now;

        $scan->pass_started_at = null;
        $scan->pass_ready_at = null;
        // The instrument is free again the moment it has an answer.
        $scan->reading_process_id = null;

        /*
         * AN INSTRUMENT KEEPS READING UNTIL IT HAS THE PICTURE.
         *
         * One press is one READ rather than one pass: a scanner pointed at a
         * machine goes round again by itself, and stops when the file is whole,
         * when the file is no longer worth filling, or when somebody unloads
         * the instrument. Pressing Scan five times was never a decision.
         *
         * THE EXPIRY IS THE ONLY COUNTER. A pass beginning after the file has
         * gone stale would be an instrument reading into a row `fileFor()` is
         * about to delete, so the read stops at the window's edge — and that
         * window is also what bounds the catch-up walk above. A machine nobody
         * can read costs two days of an analyser rather than five passes.
         */
        $continuing = ! $scan->isComplete()
            && ($scan->expires_at === null || $maturedAt->isBefore($scan->expires_at))
            && $this->instrumentIsRunning($instrument);

        if ($continuing) {
            return ProbeResult::scanned($this->begin($character, $system, $scan, $maturedAt, $instrument)->scan, $found, $rolls);
        }

        DB::transaction(static fn () => $scan->save());

        // The read files itself. No room on the deck is not an error: the press on the dialog remains.
        if ($found !== [] && $scan->isComplete()) {
            app(ScanExporter::class)->export($character, $system->address);
        }

        return ProbeResult::scanned($scan, $found, $rolls);
    }

    /**
     * How long one pass at this machine takes, in seconds.
     *
     * The base is minutes per grade from config — one for the bottom of the
     * grid, sixteen for the top — and everything that cuts it is a modifier on
     * `scan_duration`: levels of System Profiling, and a better instrument.
     */
    public function durationFor(Character $character, System $system): int
    {
        $minutes = (array) Config::get('cyberspace.scan.minutes', []);
        $base = (float) ($minutes[$system->grade] ?? reset($minutes) ?: 1) * 60;

        return $this->resolver
            ->resolve('scan_duration', $base, $character->modifiers())
            ->toInt();
    }

    /** What the character brings to reading a machine. */
    private function reading(Character $character): float
    {
        return $character->stat(SkillStats::checkId($this->skillSlug))->value;
    }

    /**
     * How hard this machine is to read.
     *
     * Per grade from config rather than a formula, so the middle of the curve
     * can be bent without rewriting either end. An ungraded machine reads as
     * the easiest thing there is rather than throwing: a world seeded before
     * grades existed is a content gap, not a crash.
     */
    private function difficultyOf(System $system): float
    {
        $table = (array) Config::get('cyberspace.scan.difficulty', []);

        return (float) ($table[$system->grade] ?? reset($table) ?: 0);
    }
}
