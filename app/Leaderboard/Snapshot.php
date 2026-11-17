<?php

declare(strict_types=1);

namespace App\Leaderboard;

use App\Models\Character;
use App\Models\LeaderboardEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The leaderboard, written and read.
 *
 * Every board is ranked whole, not only its first five, so a player's own place is a lookup. Equal values share a rank
 * (1, 2, 2, 4) and stand in alphabetical order. A value of 0 or less is not ranked.
 */
final class Snapshot
{
    public function __construct(private readonly Tally $tally = new Tally) {}

    /** Rewrite every board; the number of rows written. */
    public function write(): int
    {
        $handles = Character::query()->pluck('handle', 'id')->map(fn ($h) => mb_strtolower((string) $h))->all();
        $now = now();
        $written = 0;

        foreach (Board::cases() as $board) {
            $ranked = array_filter($this->tally->values($board), fn (int $v, int $id): bool => $v > 0 && isset($handles[$id]), ARRAY_FILTER_USE_BOTH);
            uksort($ranked, fn (int $a, int $b): int => [$ranked[$b], $handles[$a], $a] <=> [$ranked[$a], $handles[$b], $b]);

            $rows = [];
            $position = 0;
            $rank = 0;
            $previous = null;

            foreach ($ranked as $id => $value) {
                $position++;
                $rank = $value === $previous ? $rank : $position;
                $previous = $value;
                $rows[] = ['board' => $board->value, 'character_id' => $id, 'value' => $value, 'rank' => $rank, 'position' => $position, 'written_at' => $now];
            }

            DB::transaction(function () use ($board, $rows): void {
                LeaderboardEntry::query()->where('board', $board->value)->delete();

                foreach (array_chunk($rows, 500) as $chunk) {
                    LeaderboardEntry::query()->insert($chunk);
                }
            });

            $written += count($rows);
        }

        return $written;
    }

    /** @return Collection<int, LeaderboardEntry> the first rows of a board, with their characters */
    public function top(Board $board, int $limit = 5): Collection
    {
        return LeaderboardEntry::query()->with('character')->where('board', $board->value)->orderBy('position')->limit($limit)->get();
    }

    /** A character's rank on a board, or null when they are not ranked. */
    public function rankOf(Board $board, Character $character): ?int
    {
        return LeaderboardEntry::query()->where('board', $board->value)->where('character_id', $character->getKey())->value('rank');
    }

    /** When the snapshot was written, or null before the first one. */
    public function writtenAt(): ?CarbonImmutable
    {
        $at = LeaderboardEntry::query()->max('written_at');

        return $at === null ? null : CarbonImmutable::parse($at);
    }
}
