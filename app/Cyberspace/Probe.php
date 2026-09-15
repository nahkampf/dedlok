<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Checks\CheckRoller;
use App\Models\Character;
use App\Models\System;
use App\Models\SystemScan;
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
 * NO ANALYSER IS REQUIRED, and that is a decision rather than an omission.
 * CYBERSPACE.md asks whether the probe should be gated on a running analyser
 * program and says to answer it before the probe is written: it is not, because
 * mapping is meant to be the safest thing in cyberspace and the way a new
 * player earns their first creds, and putting a program between them and it
 * raises the floor of the whole game to buy nothing. When an analyser exists it
 * should make a scan BETTER — more passes, or a bonus to the roll — which is a
 * modifier on a row and needs no gate at all.
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
        private readonly int $maxPasses,
        private readonly int $lifetimeHours,
        private readonly string $skillSlug,
    ) {}

    public static function fromConfig(?CheckRoller $roller = null): self
    {
        return new self(
            $roller ?? CheckRoller::fromConfig(),
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

        $scan = $this->fileFor($character, $system);

        if ($scan->isComplete()) {
            return ProbeResult::refused(ProbeRefusal::AlreadyKnown);
        }

        if ($scan->passes >= $this->maxPasses) {
            return ProbeResult::refused(ProbeRefusal::Exhausted);
        }

        return $this->pass($character, $system, $scan);
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
