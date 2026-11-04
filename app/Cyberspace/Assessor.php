<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\Ice;
use App\Models\Skill;
use App\Models\SystemScan;
use App\Programs\ProgramKind;
use App\Stats\Resolver;
use App\Support\Text;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\HtmlString;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Rough odds for a run, from what the player has READ and nothing else (docs/ASSESSOR.md).
 *
 * EVERY FIGURE IS NULL WHEN A FACT IT NEEDS IS UNREAD, which the screens show as `[???]`. It takes a `SystemScan`, never the
 * `System` row: what the machine really has is not the player's to know. A fact read as "none here" (an IDS of 0, ICE level 0) is
 * known.
 *
 * NO FORMULA LIVES HERE. Each chance is the `Simulator` helper the roll itself calls (`osChanceFor`, `egressChanceFor`,
 * `unnoticedChance`), so a figure and the roll that follows cannot disagree. The overall plays the run out many times on a
 * PRIVATE seeded generator (never `mt_srand`: the game's own dice must stay unpredictable) using those same chances.
 *
 * Figures are exact here; `round()` blurs them to the Assessor program's step for display.
 */
final class Assessor
{
    public function __construct(private readonly Simulator $simulator, private readonly Resolver $resolver) {}

    /** The rounding step of the Assessor running on the equipped deck, or null when none runs. Asked once per request. */
    public static function step(?Character $character): ?int
    {
        if ($character === null) {
            return null;
        }

        $key = 'assessor.'.$character->getKey();
        $attributes = request()->attributes;

        if (! $attributes->has($key)) {
            $attributes->set($key, $character->runningProcesses()
                ->map(static fn ($row): ?int => $row->item?->program?->kind === ProgramKind::Utility ? $row->item->program->assess_step : null)
                ->filter()
                ->min());
        }

        return $attributes->get($key);
    }

    /** A chance as a percentage on the program's step, never 0 or 100 (a step of 20 cannot promise more than 80). */
    public static function round(float $chance, int $step): int
    {
        return (int) max($step, min(100 - $step, round($chance * 100 / $step) * $step));
    }

    /**
     * A chance as the screens show it: a tag holding the rounded percentage, green from 85, yellow from 50, red below, or
     * a plain tag with the unknown mark. HTML, built here from our own numbers and strings (nothing a player typed).
     */
    public static function tag(?float $chance, int $step): string
    {
        if ($chance === null) {
            return '<span class="tag num">'.e(Text::get('assessor.unknown')).'</span>';
        }

        $percent = self::round($chance, $step);
        $tone = $percent >= 85 ? 'ok' : ($percent >= 50 ? 'warn' : 'crit');

        return '<span class="tag tag--'.$tone.' num">'.e(Text::get('assessor.percent', ['chance' => $percent])).'</span>';
    }

    /** A line of text whose `:chance` is that tag: the sentence is escaped, the tag is not. */
    public static function line(string $key, string $tag): HtmlString
    {
        return new HtmlString(str_replace('@@chance@@', $tag, e(Text::get($key, ['chance' => '@@chance@@']))));
    }

    /** Chance of passing every watcher at the core (IDS, tracer, the ICE's own eyes). */
    public function unnoticed(Character $character, ?SystemScan $scan): ?float
    {
        $detections = $this->watchers($scan);

        return $detections === null ? null : $this->simulator->unnoticedChance($character, $detections);
    }

    /** Chance the IDS alone misses a pull of a file already under way. */
    public function unnoticedPull(Character $character, ?SystemScan $scan): ?float
    {
        return $scan?->ids_detection === null ? null : $this->simulator->unnoticedChance($character, [(int) $scan->ids_detection]);
    }

    /** Chance the core opens. */
    public function core(Character $character, ?SystemScan $scan): ?float
    {
        return $scan?->os_skill_id === null ? null : $this->simulator->osChanceFor($character, Skill::query()->find($scan->os_skill_id));
    }

    /** Chance an awake ICE lands a strike on a turn: 0 when the scan read none. */
    public function strike(?SystemScan $scan): ?float
    {
        $ice = $this->ice($scan);

        return $ice === false ? null : ($ice === null ? 0.0 : $ice->skill / 100);
    }

    /** Turns of the running icebreaker it takes to break the ICE, or null when the ICE is unread, there is none, or no damage is dealt. */
    public function turnsToBreak(Character $character, ?SystemScan $scan, ?float $integrity = null, ?float $damage = null): ?int
    {
        $ice = $this->ice($scan);

        if ($ice === false || $ice === null) {
            return null;
        }

        $damage ??= $this->damage($character);

        return $damage > 0 ? (int) ceil(($integrity ?? (float) $ice->integrity) / $damage) : null;
    }

    /**
     * The chance to finish the job and get out alive, or null when a fact it needs is unread.
     *
     * @param  float|null  $damage  the damage per turn to play with; null reads the character's own, whatever runs now (or is
     *                              assumed to, via `Character::$assumedRunning`)
     */
    public function overall(Character $character, ?SystemScan $scan, ?float $damage = null): ?float
    {
        if ($scan === null || $scan->grade === null) {
            return null;
        }

        $detections = $this->watchers($scan);
        $core = $this->core($character, $scan);
        $ice = $this->ice($scan);

        if ($detections === null || $core === null || $ice === false) {
            return null;
        }

        $damage ??= $this->damage($character);
        $touch = $this->simulator->unnoticedChance($character, $detections);
        $pull = $this->simulator->unnoticedChance($character, [(int) $scan->ids_detection]);
        $unnoticed = $touch * $pull ** (int) Config::get('assessor.assumed_pulls', 1);
        $armed = $damage > 0;

        // An ICE that will probably wake and cannot be broken ends the run. Past the trusted level it is assumed to sleep.
        $fights = $ice !== null && $armed;
        $asleep = $ice !== null && ! $armed;
        $chance = $this->play($character, $scan, $ice, $fights, $touch, $pull, $core, $damage);

        if ($asleep && $unnoticed < (float) Config::get('assessor.unnoticed_trusted', 0.9)) {
            return $chance * $unnoticed;
        }

        return $chance;
    }

    /** @return list<int>|null IDS, tracer and ICE detection, or null when any is unread */
    private function watchers(?SystemScan $scan): ?array
    {
        if ($scan === null || $scan->ids_detection === null || $scan->tracer_detection === null) {
            return null;
        }

        $ice = $this->ice($scan);

        return $ice === false ? null : [(int) $scan->ids_detection, (int) $scan->tracer_detection, (int) $ice?->detection];
    }

    /** The ICE the scan read: the row, null for none, false when unread (or the level matches no row). */
    private function ice(?SystemScan $scan): Ice|false|null
    {
        if ($scan === null || $scan->ice_level === null) {
            return false;
        }

        if ((int) $scan->ice_level === 0) {
            return null;
        }

        return Ice::query()->where('level', $scan->ice_level)->first() ?? false;
    }

    private function damage(Character $character): float
    {
        return $this->resolver->resolve('icebreaker_damage', 0.0, $character->modifiers())->value;
    }

    /**
     * Play the run out and count how often it is finished and left alive. The same order as `Simulator::takeTraced()`:
     * the action, then (unless it broke the ICE) the trace, then the ICE's strike.
     */
    private function play(Character $character, SystemScan $scan, ?Ice $ice, bool $fights, float $touch, float $pull, float $core, float $damage): float
    {
        $grade = (int) $scan->grade;
        $leave = $this->simulator->egressChanceFor($character, $grade);
        $leaveHeld = $ice === null ? $leave : $this->simulator->egressChanceFor($character, $grade, $ice);
        $rate = (int) $scan->tracer_rate;
        $pulls = (int) Config::get('assessor.assumed_pulls', 1);
        $sheetSi = (float) $character->current_synaptic_integrity;

        $rng = new Randomizer(new Mt19937(crc32($character->getKey().'|'.$scan->address.'|'.$scan->scanned_at?->getTimestamp().'|'.round($damage, 2))));
        $runs = (int) Config::get('assessor.runs', 400);
        $turns = (int) Config::get('assessor.max_turns', 40);
        $won = 0;

        for ($i = 0; $i < $runs; $i++) {
            $detected = $touched = $opened = $done = false;
            $awake = false;
            $integrity = $ice === null ? 0.0 : (float) $ice->integrity;
            $trace = 0;
            $si = $sheetSi;

            for ($turn = 1; $turn <= $turns; $turn++) {
                $broke = false;

                if ($done) {
                    if ($rng->nextFloat() < ($awake ? $leaveHeld : $leave)) {
                        $won++;

                        break;
                    }
                } elseif ($awake) {
                    $integrity -= $damage;

                    if ($integrity <= 0) {
                        $awake = false;
                        $broke = true;
                    }
                } else {
                    if (! $touched) {
                        $touched = true;

                        if ($rng->nextFloat() >= $touch) {
                            $detected = true;
                            $awake = $fights;
                        }
                    }

                    if (! $awake && ! $opened && $rng->nextFloat() < $core) {
                        $opened = true;
                    }

                    if (! $awake && $opened) {
                        $done = true;

                        for ($p = 0; $p < $pulls && ! $detected; $p++) {
                            if ($rng->nextFloat() >= $pull) {
                                $detected = true;
                                $awake = $fights;
                            }
                        }
                    }
                }

                if ($broke) {
                    continue;
                }

                if ($detected && $rate > 0 && ($trace += $rate) >= 100) {
                    break;
                }

                if ($awake && $rng->getInt(1, 100) <= $ice->skill && ($si -= (float) $ice->attack) <= 0) {
                    break;
                }
            }
        }

        return $won / $runs;
    }
}
