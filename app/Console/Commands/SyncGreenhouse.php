<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\City\GreenhouseBuys;
use Illuminate\Console\Command;

/** Make The Greenhouse buy what `city.greenhouse` says (see `App\City\GreenhouseBuys`). */
class SyncGreenhouse extends Command
{
    protected $signature = 'greenhouse:sync';

    protected $description = 'Make The Greenhouse buy every extracted file and component, and a Scan File, as config/city.php says';

    public function handle(GreenhouseBuys $buys): int
    {
        $result = $buys->sync();

        $this->info("{$result['synced']} listing(s) written.");

        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }

        if ($result['missing'] !== []) {
            $this->warn('No such item: '.implode(', ', $result['missing']));
        }

        return $result['notes'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
