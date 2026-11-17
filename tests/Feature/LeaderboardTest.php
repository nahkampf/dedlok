<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Achievements\Metric;
use App\Leaderboard\Board;
use App\Leaderboard\Snapshot;
use App\Leaderboard\Tally;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\LeaderboardEntry;
use App\Models\Loan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The leaderboard (docs/LEADERBOARD.md): the sums, the ranking, the page. */
final class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private function who(string $handle, int $creds = 0): Character
    {
        return Character::factory()->create(['handle' => $handle, 'creds' => $creds]);
    }

    /** @return list<array{string, int, int}> handle, rank, value */
    private function board(Board $board): array
    {
        return LeaderboardEntry::query()->with('character')->where('board', $board->value)->orderBy('position')->get()
            ->map(fn (LeaderboardEntry $e): array => [$e->character->handle, $e->rank, $e->value])->all();
    }

    private function completedRuns(Character $c, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('runs')->insert(['character_id' => $c->id, 'address' => 'A', 'x' => 0, 'y' => 0, 'purpose' => 'extraction', 'ending' => 'completed', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_equal_values_share_a_rank_and_stand_alphabetically_and_nothing_is_ranked_at_zero(): void
    {
        $this->completedRuns($this->who('Zed'), 3);
        $this->completedRuns($this->who('amy'), 3);
        $this->completedRuns($this->who('Bob'), 1);
        $this->who('Nobody');

        (new Snapshot)->write();

        $this->assertSame([['amy', 1, 3], ['Zed', 1, 3], ['Bob', 3, 1]], $this->board(Board::Runs));
    }

    public function test_the_page_shows_five_rows_with_their_true_ranks(): void
    {
        foreach (range(1, 7) as $n) {
            $this->completedRuns($this->who("Runner{$n}"), 10 - $n);
        }
        $last = Character::query()->where('handle', 'Runner7')->firstOrFail();

        (new Snapshot)->write();

        $this->assertCount(5, (new Snapshot)->top(Board::Runs));
        $this->assertSame(7, (new Snapshot)->rankOf(Board::Runs, $last));
    }

    public function test_net_worth_is_wallet_bank_and_items_less_the_loan_and_shows_an_approximation(): void
    {
        $rich = $this->who('Rich', 1000);
        DB::table('bank_accounts')->insert(['character_id' => $rich->id, 'balance' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $item = Item::factory()->create(['base_cost' => 200]);
        InventoryItem::query()->create(['character_id' => $rich->id, 'item_id' => $item->id, 'quantity' => 3]);
        Loan::query()->create(['character_id' => $rich->id, 'borrowed' => 400, 'principal' => 300, 'interest' => 50, 'payment' => 16, 'state' => 'active', 'next_due_at' => now()->addDay()]);
        $this->who('Broke');

        (new Snapshot)->write();

        $this->assertSame([['Rich', 1, 1000 + 500 + 600 - 350]], $this->board(Board::NetWorth));
        foreach ([[300, '300'], [1422, '1k'], [13100, '10k'], [17321, '20k'], [49000, '50k'], [89212, '100k'], [101232, '100k'], [154012, '200k'], [202312, '200k'], [550000, '500k'], [1100000, '1M'], [3160974, '3M'], [424242, '500k'], [2600000000, '3B']] as [$value, $shown]) {
            $this->assertSame($shown, Board::NetWorth->figure($value));
        }
        $this->assertSame('1,234', Board::Runs->figure(1234));
    }

    public function test_the_other_boards_read_their_counters(): void
    {
        $a = $this->who('Ada');
        DB::table('achievement_progress')->insert([
            ['user_id' => $a->user_id, 'metric' => Metric::GridExplored->value, 'value' => 40],
            ['user_id' => $a->user_id, 'metric' => Metric::SystemsScanned->value, 'value' => 7],
        ]);
        DB::table('character_skills')->insert([
            ['character_id' => $a->id, 'skill_id' => 1, 'skill_points' => 100, 'attained_level' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['character_id' => $a->id, 'skill_id' => 2, 'skill_points' => 50, 'attained_level' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        (new Snapshot)->write();

        $this->assertSame([['Ada', 1, 40]], $this->board(Board::Explorers));
        $this->assertSame([['Ada', 1, 7]], $this->board(Board::Scanners));
        $this->assertSame([['Ada', 1, 150]], $this->board(Board::SkillPoints));
    }

    public function test_attributes_sum_the_lasting_and_leave_out_the_temporary(): void
    {
        $a = $this->who('Ada');
        $plain = (new Tally)->values(Board::Attributes)[$a->id];
        $this->assertSame(array_sum($a->attributeScores()), $plain);

        CharacterModifier::query()->create(['character_id' => $a->id, 'definition_key' => 'fatigue_tired']);

        $this->assertSame($plain, (new Tally)->values(Board::Attributes)[$a->id]);
    }

    public function test_writing_again_replaces_the_board(): void
    {
        $a = $this->who('Ada');
        $this->completedRuns($a, 1);
        (new Snapshot)->write();
        $this->completedRuns($a, 1);
        (new Snapshot)->write();

        $this->assertSame([['Ada', 1, 2]], $this->board(Board::Runs));
    }

    public function test_the_command_is_scheduled_hourly_at_minute_twelve(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'leaderboard:snapshot'));

        $this->assertNotNull($event);
        $this->assertSame('12 * * * *', $event->expression);
    }

    public function test_the_page_lists_the_boards_shows_net_worth_approximately_and_says_where_you_stand(): void
    {
        $me = $this->who('Me', 10);
        $rich = $this->who('Richie', 424242);
        $this->completedRuns($me, 2);
        (new Snapshot)->write();

        $html = $this->actingAs($me->user)->get(route('leaderboard.index'))->assertOk()->getContent();

        $this->assertStringContainsString(Board::Runs->label(), $html);
        $this->assertStringContainsString(Board::MurderHobos->label(), $html);
        $this->assertStringContainsString('Me', $html);
        $this->assertStringContainsString('Richie', $html);
        $this->assertStringNotContainsString('424,242', $html);
        $this->assertStringContainsString('500k', $html);
    }
}
