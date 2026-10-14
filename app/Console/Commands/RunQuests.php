<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Quests\QuestRunner;
use Illuminate\Console\Command;

/** The quest runner: the timed steps of quests. Scheduled every thirty minutes; safe to run by hand. */
class RunQuests extends Command
{
    protected $signature = 'quests:run';

    protected $description = 'Run the timed steps of quests';

    public function handle(QuestRunner $runner): int
    {
        foreach ($runner->run() as $task => $count) {
            $this->info("{$task}: {$count}");
        }

        return self::SUCCESS;
    }
}
