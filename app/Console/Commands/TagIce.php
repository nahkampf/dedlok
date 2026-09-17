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
 * BOUNTY IS NOT HERE, and that is deliberate. It belongs to the MACHINE rather
 * than to the guard — the same piece of ICE on two systems is two different
 * propositions, because one of them belongs to somebody who answers — so it is
 * rolled beside the ICE in `App\Cyberspace\Defences` and stored on
 * `systems.ice_bounty`. Nothing here writes it.
 *
 * Running this twice writes the same tags, because nothing in it is rolled.
 */
class TagIce extends Command
{
    protected $signature = 'cyberspace:tag-ice
                            {--dry-run : Print the grid and write nothing}';

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
        $rows = [];

        foreach (Ice::query()->orderBy('level')->get() as $ice) {
            $tags = array_map(
                static fn (array $tag): array => ['tag' => $tag[0], 'level' => $tag[1]],
                self::GRID[$ice->level] ?? [],
            );

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

        // The bounty column is what a MACHINE carrying this piece will be
        // rolled at — see Defences. It is shown here because it is the other
        // half of what losing to this guard costs, and it is not on this row.
        $this->table(['ice', 'level', 'tags', 'bounty odds per machine'], $rows);
        $this->info($dryRun ? 'Dry run. Nothing was written.' : 'Written.');

        return self::SUCCESS;
    }
}
