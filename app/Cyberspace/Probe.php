<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Checks\CheckRoller;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\System;
use App\Models\SystemScan;
use App\Programs\ProgramKind;
use App\Stats\Resolver;
use App\Stats\SkillStats;
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
 * ONE PASS IS ONE ROLL PER THING NOT YET KNOWN. A pass that learns nothing is
 * the ordinary outcome against something above a character's reach, and a pass
 * that learns half of it is why a scan is a row that can be added to rather
 * than a yes or a no. CYBERSPACE.md budgets 1-5 turns for scanning inside a
 * run; `cyberspace.scan.passes` is that budget, and it is what stops a free act
 * from being ground until it succeeds — **a failed scan costs the expiry
 * window, not a retry.**
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
 *  - the same, targeting `scan_passes` — another look at the machine before the
 *    picture is the picture you get.
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
        private readonly int $basePasses,
        private readonly int $lifetimeHours,
        private readonly string $skillSlug,
    ) {}

    public static function fromConfig(?CheckRoller $roller = null, ?Resolver $resolver = null): self
    {
        return new self(
            $roller ?? CheckRoller::fromConfig(),
            $resolver ?? app(Resolver::class),
            (int) Config::get('cyberspace.scan.passes', 5),
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
    public function scan(Character $character): ProbeResult
    {
        $at = $character->gridPosition();

        if ($at === null) {
            return ProbeResult::refused(ProbeRefusal::NotJackedIn);
        }

        $system = System::query()->at($at)->first();

        if ($system === null) {
            return ProbeResult::refused(ProbeRefusal::NothingThere);
        }

        if (! $system->type->isInteractable()) {
            return ProbeResult::refused(ProbeRefusal::NotScannable);
        }

        if (! $this->hasAnalyserRunning($character)) {
            return ProbeResult::refused(ProbeRefusal::NoAnalyser);
        }

        $scan = $this->fileFor($character, $system);

        if ($scan->isComplete()) {
            return ProbeResult::refused(ProbeRefusal::AlreadyKnown);
        }

        if ($scan->passes >= $this->passesFor($character)) {
            return ProbeResult::refused(ProbeRefusal::Exhausted);
        }

        return $this->pass($character, $system, $scan);
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
        if ($character->equipped_deck_id === null) {
            return false;
        }

        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->where('running_on_deck_id', $character->equipped_deck_id)
            ->whereHas('item.program', static fn ($query) => $query->where('kind', ProgramKind::Analyser->value))
            ->exists();
    }

    /**
     * How many looks this character gets at a machine.
     *
     * Resolved rather than read off config, because a better analyser buying
     * another pass is the clearest thing a version can be worth. The base is
     * the config number and everything else is a modifier — see the
     * `scan_passes` stat.
     */
    public function passesFor(Character $character): int
    {
        return $this->resolver
            ->resolve('scan_passes', (float) $this->basePasses, $character->modifiers())
            ->toInt();
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

        return new SystemScan([
            'character_id' => $character->getKey(),
            'address' => $system->address,
            'x' => $system->x,
            'y' => $system->y,
            'passes' => 0,
            'scanned_at' => now(),
            'expires_at' => now()->addHours($this->lifetimeHours),
        ]);
    }

    private function pass(Character $character, System $system, SystemScan $scan): ProbeResult
    {
        $reading = $this->reading($character);
        $difficulty = $this->difficultyOf($system);

        $found = [];
        $rolls = [];

        foreach ($scan->unknowns() as $unknown) {
            $outcome = $this->roller->roll($reading, $difficulty, 'scan');
            $rolls[$unknown] = $outcome;

            if (! $outcome->passed) {
                continue;
            }

            $found[] = $unknown;

            match ($unknown) {
                'grade' => $scan->grade = $system->grade,
                'os' => $scan->os_skill_id = $system->os_skill_id,
            };
        }

        $scan->passes++;

        DB::transaction(static fn () => $scan->save());

        return ProbeResult::scanned($scan, $found, $rolls);
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
