<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Characters\Flags;
use App\Models\Character;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Set a flag on a character by hand: the only setter until quests and encounters exist. */
class SetFlag extends Command
{
    protected $signature = 'flag:set {handle : the character} {flag : a lower-case slug}';

    protected $description = 'Set a permanent flag on a character';

    public function handle(): int
    {
        $character = Character::query()->where('handle', $this->argument('handle'))->first();

        if ($character === null) {
            $this->error('No character has that handle.');

            return self::FAILURE;
        }

        try {
            $new = Flags::set($character, (string) $this->argument('flag'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($new ? 'Flag set.' : 'Already set.');

        return self::SUCCESS;
    }
}
