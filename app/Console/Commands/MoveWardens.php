<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\Wardens;
use Illuminate\Console\Command;

/** Every minute: the Wardens of every subnet take a step, and any that come near a runner look at them. */
class MoveWardens extends Command
{
    protected $signature = 'wardens:move';

    protected $description = 'Step every Warden, replace a missing one, and let them notice runners they come near';

    public function handle(): int
    {
        $moved = Wardens::fromConfig()->minute();

        $this->info("{$moved} warden(s) moved.");

        return self::SUCCESS;
    }
}
