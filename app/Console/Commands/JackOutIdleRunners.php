<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Jack out tree runners who have stopped taking turns (docs/RUN_TREE.md), with no damage: a solo runner after
 * `cyberspace.tree.idle_minutes.solo` (10) minutes without a turn, and a party runner left alone (every other run of the
 * party over) after `alone` (5), counted from their last turn or the moment they were left, whichever is later. A runner
 * whose party is still in has rounds that resolve without them, so the party's own clock looks after them.
 */
class JackOutIdleRunners extends Command
{
    protected $signature = 'cyberspace:idle-runs {--quiet-when-idle : Print nothing unless somebody was jacked out}';

    protected $description = 'Jack out tree runners who have stopped taking turns';

    public function handle(): int
    {
        $simulator = Simulator::fromConfig();
        $now = CarbonImmutable::now();
        $out = 0;

        Run::query()->live()->whereNotNull('node')->each(function (Run $run) use ($simulator, $now, &$out): void {
            $last = $run->acted_at ?? $run->started_at ?? $now;
            $limit = (int) Config::get('cyberspace.tree.idle_minutes.solo', 10);

            if ($run->run_party_id !== null) {
                $mates = Run::query()->where('run_party_id', $run->run_party_id)->whereKeyNot($run->getKey());

                if ((clone $mates)->live()->exists()) {
                    return;
                }

                $left = (clone $mates)->max('ended_at');
                $last = $left !== null && CarbonImmutable::parse($left)->greaterThan($last) ? CarbonImmutable::parse($left) : $last;
                $limit = (int) Config::get('cyberspace.tree.idle_minutes.alone', 5);
            }

            if ($last->addMinutes($limit)->greaterThan($now)) {
                return;
            }

            $character = Character::query()->find($run->character_id);

            if ($character !== null && $simulator->jackOutIdle($character, $run)) {
                $out++;
            }
        });

        if ($out > 0 || ! $this->option('quiet-when-idle')) {
            $this->info("{$out} inactive runner(s) jacked out");
        }

        return self::SUCCESS;
    }
}
