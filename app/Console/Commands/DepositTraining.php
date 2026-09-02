<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Character;
use App\Skills\TrainingDeposit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The training heartbeat. Scheduled every minute; see routes/console.php.
 *
 * Missing a run costs nothing. Each character advances from its own stored
 * clock to now, so a cycle that never ran is made up by the next one — the
 * schedule is a trigger, not the source of truth.
 *
 * Anything worth keeping goes through Log:: as well as the console. A scheduled
 * command's stdout cannot reach the container log (see routes/console.php), so
 * on the heartbeat the console half is written to nothing at all.
 */
class DepositTraining extends Command
{
    protected $signature = 'training:deposit
                            {--character= : Only this character id}
                            {--quiet-when-idle : Print nothing unless something happened}';

    protected $description = 'Credit elapsed training time to every character with a queued skill';

    public function handle(): int
    {
        $deposit = TrainingDeposit::make();

        $characters = Character::whereHas('trainingQueue')
            ->when($this->option('character'), fn ($query, $id) => $query->whereKey($id));

        $touched = 0;
        $points = 0;
        $levels = 0;
        $completed = 0;
        $stalled = 0;

        $characters->each(function (Character $character) use ($deposit, &$touched, &$points, &$levels, &$completed, &$stalled): void {
            $result = $deposit->advance($character);

            $points += $result->pointsAwarded;
            $levels += count($result->levelsReached);
            $completed += count($result->entriesCompleted);
            $stalled += $result->stalled ? 1 : 0;

            if ($result->didSomething()) {
                $touched++;
            }

            foreach ($result->levelsReached as [$slug, $level]) {
                $this->line("  <info>{$character->handle}</info> reached [{$slug}] level {$level}");

                Log::info('Skill level reached', [
                    'character' => $character->id,
                    'skill' => $slug,
                    'level' => $level,
                ]);
            }
        });

        if ($touched === 0 && $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        $summary = sprintf(
            '%d character(s) advanced, %s points, %d level(s), %d entr(ies) completed%s',
            $touched,
            number_format($points),
            $levels,
            $completed,
            $stalled > 0 ? ", {$stalled} stalled on zero attributes" : '',
        );

        $this->line($summary);

        // An idle minute is not worth 1,440 log lines a day; a stall is, because
        // it means a character is queued for training that can never finish.
        if ($touched > 0 || $stalled > 0) {
            Log::info("Training deposit: {$summary}");
        }

        return self::SUCCESS;
    }
}
