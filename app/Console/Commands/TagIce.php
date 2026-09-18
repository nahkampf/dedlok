<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\DefeatTag;
use App\Models\Ice;
use Illuminate\Console\Command;

/**
 * Write what each piece of ICE does to somebody it beats, and what it does
 * while it is still fighting them.
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

    protected $description = 'Write the defeat and attack tags onto the twelve pieces of ICE';

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

    /**
     * What each level does WHILE it fights.
     *
     * FIVE OF THE TWELVE HOLD YOU, which is what makes the fact worth scanning
     * for: if every guard ensnared, the tag would be a property of ICE rather
     * than a thing to find out about one.
     *
     * The bottom two carry nothing, like the defeat grid — a street-corner
     * guard is something a bare character is meant to be able to walk away
     * from, and Ensnare is the tag that takes walking away off the table.
     * Level 3 carries the mildest rung so the mechanic is met early and
     * cheaply, at the top of the band a starting character can reach; III
     * belongs to ICE XII alone, the same reasoning that gives it Erase III.
     *
     * @var array<int, list<array{0: string, 1: ?int}>>
     */
    private const ATTACKS = [
        1 => [],
        2 => [],
        3 => [['ensnare', 1]],
        4 => [],
        5 => [],
        6 => [['ensnare', 1]],
        7 => [],
        8 => [['ensnare', 2]],
        9 => [],
        10 => [],
        11 => [['ensnare', 2]],
        12 => [['ensnare', 3]],
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

            $attacks = array_map(
                static fn (array $tag): array => ['tag' => $tag[0], 'level' => $tag[1]],
                self::ATTACKS[$ice->level] ?? [],
            );

            $rows[] = [
                $ice->name,
                $ice->level,
                implode(', ', array_map(
                    static fn (array $t): string => $t['tag'].($t['level'] === null ? '' : ' '.$t['level']),
                    $tags,
                )) ?: '—',
                implode(', ', array_map(
                    static fn (array $t): string => $t['tag'].($t['level'] === null ? '' : ' '.$t['level']),
                    $attacks,
                )) ?: '—',
                DefeatTag::Bounty->authoringChance($ice->level).'%',
            ];

            if (! $dryRun) {
                $ice->forceFill([
                    'defeat_tags' => $tags === [] ? null : $tags,
                    'attack_tags' => $attacks === [] ? null : $attacks,
                ])->save();
            }
        }

        // The bounty column is what a MACHINE carrying this piece will be
        // rolled at — see Defences. It is shown here because it is the other
        // half of what losing to this guard costs, and it is not on this row.
        $this->table(['ice', 'level', 'on defeat', 'while fighting', 'bounty odds per machine'], $rows);
        $this->info($dryRun ? 'Dry run. Nothing was written.' : 'Written.');

        return self::SUCCESS;
    }
}
