<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Combat\FightRefusal;
use App\Combat\Fights;
use App\Models\Character;
use App\Models\Npc;
use Illuminate\Console\Command;

/**
 * Open a fight between a character and an opponent from the editor.
 *
 * The only caller of `Fights::open()` until encounters can start one (docs/COMBAT.md, step 5), the way
 * `flag:set` was the only writer of a flag until quests existed. It opens the fight and nothing more:
 * the character is then locked into it until it is settled, and plays it on the fight screen.
 */
final class StartFight extends Command
{
    protected $signature = 'fight:start {handle : the character} {npc : the slug of an opponent from the editor}';

    protected $description = 'Open a fight between a character and an opponent. They are locked into it until it is settled';

    public function handle(Fights $fights): int
    {
        $character = Character::query()->where('handle', $this->argument('handle'))->first();

        if ($character === null) {
            $this->error('No character with that handle.');

            return self::FAILURE;
        }

        $npc = Npc::query()->where('slug', $this->argument('npc'))->first();

        if ($npc === null) {
            $this->error('No opponent with that slug.');

            return self::FAILURE;
        }

        $fight = $fights->open($character, $npc);

        if ($fight instanceof FightRefusal) {
            $this->error($fight->message());

            return self::FAILURE;
        }

        $this->info("{$character->handle} is in a fight with {$npc->name}. They are locked into it until it is settled.");

        return self::SUCCESS;
    }
}
