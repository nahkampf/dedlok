<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\DefeatTag;
use App\Models\Ice;
use Illuminate\Console\Command;

/**
 * Write what each piece of ICE does to somebody it beats.
 *
 * CONTENT, AND MEANT TO BE EDITED AFTERWARDS. This writes a starting
 * distribution, not a rule the rows must keep agreeing with — the grid below is
 * a design opinion and the `ice` table owns it from here.
 *
 * The shape of the grid: the bottom of the ladder carries NOTHING, because
 * losing to a street-corner guard should cost the waiting screen and no more.
 * Permanent damage does not appear until the grade 6-7 band. Erase III — the
 * one that wipes a deck of every program and every file — sits on exactly one
 * piece, because it is the most expensive thing in the tag set by some
 * distance and it should belong to the single worst thing in the world.
 *
 * BOUNTY IS ROLLED RATHER THAN CHOSEN, at odds that climb with level — a tenth
 * at ICE I, two-thirds at ICE XII. That is what keeps a player who has met one
 * grade 9 guard from knowing the next, and it is the only tag here whose
 * presence is not a decision. The roll is injected so a test can pin it.
 *
 * Idempotent in the sense that matters: it REPLACES a row's tags with the grid,
 * so running it twice gives the same authored tags — but it rolls Bounty again,
 * so running it twice is a different lottery. --keep-bounty leaves whatever was
 * already rolled.
 */
class TagIce extends Command
{
    protected $signature = 'cyberspace:tag-ice
                            {--dry-run : Print the grid and write nothing}
                            {--keep-bounty : Leave each row\'s existing bounty alone rather than rolling again}';

    protected $description = 'Write the defeat tags onto the twelve pieces of ICE';

    /**
     * What each level carries, before Bounty is rolled on top.
     *
     * @var array<int, list<array{0: string, 1: ?int}>>
     */
    private const GRID = [
        1 => [],
        2 => [],
        3 => [['neural_shock', 1]],
        4 => [['neural_shock', 1], ['skim', 1]],
        5 => [['neural_shock', 1], ['skim', 1]],
        6 => [['neural_shock', 2], ['skim', 1]],
        7 => [['neural_shock', 2], ['skim', 2], ['surge', 1]],
        8 => [['flatline', 1], ['skim', 2], ['surge', 1], ['erase', 1]],
        9 => [['flatline', 1], ['neural_shock', 2], ['surge', 1], ['erase', 1]],
        10 => [['flatline', 2], ['neural_shock', 3], ['surge', 2], ['erase', 2], ['skim', 3]],
        11 => [['flatline', 2], ['neural_shock', 3], ['surge', 3], ['erase', 2], ['skim', 3]],
        12 => [['flatline', 3], ['neural_shock', 3], ['surge', 3], ['erase', 3], ['skim', 3]],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keep = (bool) $this->option('keep-bounty');
        $roll = static fn (int $min, int $max): int => random_int($min, $max);

        $rows = [];

        foreach (Ice::query()->orderBy('level')->get() as $ice) {
            $tags = array_map(
                static fn (array $tag): array => ['tag' => $tag[0], 'level' => $tag[1]],
                self::GRID[$ice->level] ?? [],
            );

            $bounty = $keep
                ? $ice->carries(DefeatTag::Bounty)
                : $roll(1, 100) <= DefeatTag::Bounty->authoringChance($ice->level);

            if ($bounty) {
                $tags[] = ['tag' => DefeatTag::Bounty->value, 'level' => null];
            }

            $rows[] = [
                $ice->name,
                $ice->level,
                implode(', ', array_map(
                    static fn (array $t): string => $t['tag'].($t['level'] === null ? '' : ' '.$t['level']),
                    $tags,
                )) ?: '—',
                DefeatTag::Bounty->authoringChance($ice->level).'%',
            ];

            if (! $dryRun) {
                $ice->forceFill(['defeat_tags' => $tags === [] ? null : $tags])->save();
            }
        }

        $this->table(['ice', 'level', 'tags', 'bounty odds'], $rows);
        $this->info($dryRun ? 'Dry run. Nothing was written.' : 'Written.');

        return self::SUCCESS;
    }
}
