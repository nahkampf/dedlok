<?php

declare(strict_types=1);

namespace App\Leaderboard;

use App\Achievements\Metric;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/** What each board counts, per character. The only place the sums are worked out. */
final class Tally
{
    /** @return array<int, int> character id => value; characters with nothing may be absent */
    public function values(Board $board): array
    {
        return match ($board) {
            Board::Runs => $this->counted('runs', 'ending', 'completed'),
            Board::MurderHobos => $this->counted('fights', 'result', 'b_down'),
            Board::SkillPoints => DB::table('character_skills')->groupBy('character_id')
                ->selectRaw('character_id, sum(skill_points) as n')->pluck('n', 'character_id')->map(fn ($n) => (int) $n)->all(),
            Board::Explorers => $this->progress(Metric::GridExplored),
            Board::Scanners => $this->progress(Metric::SystemsScanned),
            Board::NetWorth => $this->netWorth(),
        };
    }

    /** @return array<int, int> */
    private function counted(string $table, string $column, string $value): array
    {
        return DB::table($table)->where($column, $value)->groupBy('character_id')
            ->selectRaw('character_id, count(*) as n')->pluck('n', 'character_id')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<int, int> an achievement counter, which belongs to the account, read for its one character */
    private function progress(Metric $metric): array
    {
        return DB::table('achievement_progress')->join('characters', 'characters.user_id', '=', 'achievement_progress.user_id')
            ->where('achievement_progress.metric', $metric->value)
            ->pluck('achievement_progress.value', 'characters.id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Wallet and bank, plus every item they own at its stock price (carried, installed, running or in storage), minus what
     * is still owed on the loan.
     *
     * @return array<int, int>
     */
    private function netWorth(): array
    {
        $worth = DB::table('characters')->pluck('creds', 'id')->map(fn ($n) => (int) $n)->all();

        foreach (DB::table('bank_accounts')->pluck('balance', 'character_id') as $id => $balance) {
            $worth[$id] = ($worth[$id] ?? 0) + (int) $balance;
        }

        InventoryItem::query()->with('item')->chunkById(500, function ($rows) use (&$worth): void {
            foreach ($rows as $row) {
                $worth[$row->character_id] = ($worth[$row->character_id] ?? 0) + (int) $row->quantity * $row->stockPrice();
            }
        });

        foreach (DB::table('loans')->whereIn('state', ['active', 'defaulted'])->selectRaw('character_id, sum(principal + interest) as owed')->groupBy('character_id')->get() as $loan) {
            $worth[$loan->character_id] = ($worth[$loan->character_id] ?? 0) - (int) $loan->owed;
        }

        return $worth;
    }
}
