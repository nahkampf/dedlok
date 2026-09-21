<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Characters\HeatDecay;
use App\Models\Character;
use Illuminate\Console\Command;

/**
 * Heat comes off, slowly.
 *
 * THE OTHER HALF OF HEAT BEING A NUMBER TO MANAGE. It goes up when you are
 * loud and comes down when you are not, and neither half is fast: half a point
 * a night by default, so a completed trace takes two nights to shake and a
 * burn takes four.
 *
 * ## Ten past five, AFTER the bounty roll
 *
 * The order is the point. A character is rolled on the heat the night ended
 * with — what they did is what they answer for — and the cooling is the
 * morning after, not a discount applied before anybody looked. Same reasoning
 * the siphons have for paying out before the ageing roll takes the backdoor.
 *
 * ## The usual stance
 *
 * No stored clock, a missed night is never made up, `withoutOverlapping()`
 * because two passes would be a night's decay twice. A character with no heat
 * is not touched, which is also what keeps this off every row in the table.
 */
class CoolCharacters extends Command
{
    protected $signature = 'characters:cool
                            {--dry-run : Report what would come off and write nothing}
                            {--quiet-when-idle : Print nothing unless somebody cooled off}';

    protected $description = 'Take a night of heat off every character carrying any';

    public function handle(HeatDecay $decay): int
    {
        if ((bool) $this->option('dry-run')) {
            return $this->report($decay);
        }

        $cooled = $decay->tonight();

        if ($cooled === [] && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        foreach ($cooled as $entry) {
            $this->line(sprintf(
                '  %-24s %.1f -> %.1f',
                $entry['character']->handle,
                $entry['from'],
                $entry['to'],
            ));
        }

        $this->info(sprintf('%d character(s) cooled off.', count($cooled)));

        return self::SUCCESS;
    }

    /** What would come off tonight. Writes nothing. */
    private function report(HeatDecay $decay): int
    {
        $carrying = Character::query()->where('heat', '>', 0)->orderByDesc('heat')->get();

        if ($carrying->isEmpty()) {
            $this->info('Nobody is carrying any heat.');

            return self::SUCCESS;
        }

        foreach ($carrying as $character) {
            $this->line(sprintf(
                '  %-24s %.1f  -%.1f',
                $character->handle,
                (float) $character->heat,
                $decay->rateFor($character),
            ));
        }

        $this->info(sprintf('%d character(s) would cool off. Nothing was written.', $carrying->count()));

        return self::SUCCESS;
    }
}
