<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Party\Parties;
use App\Cyberspace\Party\PoolRefusal;
use App\Cyberspace\Party\RunPool;
use App\Cyberspace\RunEnding;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\RunPartyMember;
use App\Models\RunPoolItem;
use App\Programs\ProgramKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** The run pool (the Trade box on the co-op setup screen): offer, claim, return, and what happens when the run ends. */
final class RunPoolTest extends TestCase
{
    use CarriesAWorkingDeck, RefreshDatabase;

    private Character $owner;

    private Character $mate;

    private RunParty $party;

    private RunPool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = Character::factory()->create();
        $this->mate = Character::factory()->create();
        $this->equipReadyDeck($this->owner);
        $this->equipReadyDeck($this->mate);
        $this->owner = $this->owner->fresh();
        $this->mate = $this->mate->fresh();
        $this->party = RunParty::query()->create(['owner_character_id' => $this->owner->id, 'address' => 'ABCDEF12', 'x' => 1, 'y' => 1]);
        RunPartyMember::query()->create(['run_party_id' => $this->party->id, 'character_id' => $this->mate->id, 'joined_at' => now()]);
        $this->pool = app(RunPool::class);
    }

    private function give(Character $who, ProgramKind $kind = ProgramKind::Script, int $quantity = 1): InventoryItem
    {
        $item = Item::factory()->program($kind, qflops: 0.1, memory: 0.1, storage: 0.1, background: false)->create();

        return InventoryItem::create(['character_id' => $who->id, 'item_id' => $item->id, 'quantity' => $quantity]);
    }

    private function copies(Character $who, InventoryItem|Item $of): int
    {
        $id = $of instanceof Item ? $of->id : $of->item_id;

        return (int) InventoryItem::query()->where('character_id', $who->id)->where('item_id', $id)->sum('quantity');
    }

    public function test_an_offer_leaves_the_stash_and_a_claim_hands_a_copy_over(): void
    {
        $row = $this->give($this->owner, quantity: 2);

        $this->assertNull($this->pool->offer($this->owner, $row->id));
        $this->assertSame(1, $this->copies($this->owner, $row), 'one copy left the stash');
        $entry = RunPoolItem::query()->sole();
        $this->assertNull($entry->claimed_by_character_id);

        $this->assertSame(PoolRefusal::Yours, $this->pool->claim($this->owner, $entry->id), 'your own offering is yours to return');
        $this->assertNull($this->pool->claim($this->mate, $entry->id));
        $this->assertSame(1, $this->copies($this->mate, $row));
        $this->assertSame($this->mate->id, (int) $entry->fresh()->claimed_by_character_id);
        $this->assertSame(PoolRefusal::Gone, $this->pool->claim($this->mate, $entry->id), 'already claimed');
    }

    public function test_the_pool_takes_scripts_run_only_programs_and_keys_and_nothing_else(): void
    {
        foreach ([ProgramKind::Script, ProgramKind::Icebreaker, ProgramKind::Evasion] as $kind) {
            $this->give($this->owner, $kind);
        }
        $key = Item::factory()->create(['container' => Item::CONTAINER_DATA, 'size' => 4.0, 'key_grade' => 4]);
        InventoryItem::create(['character_id' => $this->owner->id, 'item_id' => $key->id, 'quantity' => 1]);

        $this->assertCount(4, $this->pool->eligible($this->owner, $this->party));

        $leecher = $this->give($this->owner, ProgramKind::Leecher);
        $analyser = $this->give($this->owner, ProgramKind::Analyser);
        $fortress = $this->give($this->owner, ProgramKind::Kernel);

        $eligible = $this->pool->eligible($this->owner, $this->party)->pluck('id');
        $this->assertFalse($eligible->contains($leecher->id), 'Leechers are left out (docs/TODO.md)');
        $this->assertFalse($eligible->contains($analyser->id));
        $this->assertFalse($eligible->contains($fortress->id));
        $this->assertSame(PoolRefusal::NotEligible, $this->pool->offer($this->owner, $leecher->id));
    }

    public function test_a_party_of_one_has_nobody_to_trade_with_and_a_ticked_or_running_copy_is_not_offered(): void
    {
        $row = $this->give($this->owner);
        RunPartyMember::query()->delete();
        $this->assertSame(PoolRefusal::Alone, $this->pool->offer($this->owner, $row->id));

        RunPartyMember::query()->create(['run_party_id' => $this->party->id, 'character_id' => $this->mate->id, 'joined_at' => now()]);
        $this->party->forceFill(['owner_loadout' => [$row->id]])->save();
        $this->assertSame(PoolRefusal::NotEligible, $this->pool->offer($this->owner, $row->id), 'ticked for the run');
    }

    public function test_returning_withdraws_an_unclaimed_copy_and_puts_a_claimed_one_back_in_the_pool(): void
    {
        $row = $this->give($this->owner);
        $this->pool->offer($this->owner, $row->id);
        $entry = RunPoolItem::query()->sole();

        $this->assertSame(PoolRefusal::NotYours, $this->pool->release($this->mate, $entry->id), 'only the offerer withdraws');
        $this->assertNull($this->pool->release($this->owner, $entry->id));
        $this->assertSame(1, $this->copies($this->owner, $row), 'it is back');
        $this->assertSame(0, RunPoolItem::query()->count());

        $this->pool->offer($this->owner, InventoryItem::query()->where('character_id', $this->owner->id)->where('item_id', $row->item_id)->first()->id);
        $entry = RunPoolItem::query()->sole();
        $this->pool->claim($this->mate, $entry->id);

        $this->assertSame(PoolRefusal::NotYours, $this->pool->release($this->owner, $entry->id), 'only the claimant returns a claimed copy');
        $this->assertNull($this->pool->release($this->mate, $entry->id));
        $this->assertSame(0, $this->copies($this->mate, $row));
        $this->assertNull($entry->fresh()->claimed_by_character_id, 'back in the pool');
    }

    public function test_the_launch_returns_what_nobody_claimed_and_keeps_what_the_runners_hold(): void
    {
        $a = $this->give($this->owner);
        $b = $this->give($this->owner);
        $this->pool->offer($this->owner, $a->id);
        $this->pool->offer($this->owner, $b->id);
        $this->pool->claim($this->mate, RunPoolItem::query()->where('item_id', $a->item_id)->value('id'));

        $this->pool->settleLaunch($this->party, [$this->owner->id, $this->mate->id]);

        $this->assertSame(1, $this->copies($this->owner, $b), 'the unclaimed one is back');
        $this->assertSame(1, $this->copies($this->mate, $a), 'the claimed one is theirs for the run');
        $this->assertSame(1, RunPoolItem::query()->count());
    }

    public function test_what_is_unused_at_the_end_goes_back_on_a_good_ending_and_is_destroyed_on_a_bad_one(): void
    {
        foreach ([[RunEnding::Egress, 1, 0], [RunEnding::Completed, 1, 0], [RunEnding::Defeat, 0, 0], [RunEnding::Burnout, 0, 0]] as [$ending, $backAtOfferer, $atClaimant]) {
            RunPoolItem::query()->delete();
            $row = $this->give($this->owner);
            $this->pool->offer($this->owner, $row->id);
            $this->pool->claim($this->mate, RunPoolItem::query()->sole()->id);
            $run = Run::query()->create(['character_id' => $this->mate->id, 'run_party_id' => $this->party->id, 'address' => 'ABCDEF12', 'x' => 1, 'y' => 1, 'purpose' => 'extraction', 'turn' => 0, 'started_at' => now()]);

            $this->pool->settleRun($this->mate, $run, $ending);

            $this->assertSame($backAtOfferer, $this->copies($this->owner, $row), $ending->value.': the offerer');
            $this->assertSame($atClaimant, $this->copies($this->mate, $row), $ending->value.': the claimant');
            $this->assertSame(0, RunPoolItem::query()->count());
        }
    }

    public function test_a_copy_that_was_used_has_nothing_to_return(): void
    {
        $row = $this->give($this->owner);
        $this->pool->offer($this->owner, $row->id);
        $this->pool->claim($this->mate, RunPoolItem::query()->sole()->id);
        InventoryItem::query()->where('character_id', $this->mate->id)->delete();
        $run = Run::query()->create(['character_id' => $this->mate->id, 'run_party_id' => $this->party->id, 'address' => 'ABCDEF12', 'x' => 1, 'y' => 1, 'purpose' => 'extraction', 'turn' => 0, 'started_at' => now()]);

        $this->pool->settleRun($this->mate, $run, RunEnding::Egress);

        $this->assertSame(0, $this->copies($this->owner, $row), 'spent, so not returned');
        $this->assertSame(0, RunPoolItem::query()->count());
    }

    public function test_leaving_or_disbanding_hands_everything_back(): void
    {
        $row = $this->give($this->owner, quantity: 2);
        $mine = $this->give($this->mate);
        $this->pool->offer($this->owner, $row->id);
        $this->pool->offer($this->mate, $mine->id);
        $this->pool->claim($this->mate, RunPoolItem::query()->where('offered_by_character_id', $this->owner->id)->value('id'));

        (new Parties)->leave($this->mate);

        $this->assertSame(2, $this->copies($this->owner, $row), 'the owner has both copies again');
        $this->assertSame(1, $this->copies($this->mate, $mine), 'and the mate has theirs');
        $this->assertSame(0, RunPoolItem::query()->count());

        $this->pool->offer($this->owner, InventoryItem::query()->where('character_id', $this->owner->id)->where('item_id', $row->item_id)->first()->id);
        (new Parties)->leave($this->owner);
        $this->assertSame(2, $this->copies($this->owner, $row), 'disbanding returns the rest before the cascade');
    }

    public function test_the_routes_offer_claim_and_return(): void
    {
        $row = $this->give($this->owner);

        $this->actingAs($this->owner->user)->post(route('run.party.pool.offer'), ['item' => $row->id])->assertRedirect(route('run.setup'));
        $entry = RunPoolItem::query()->sole();
        $this->actingAs($this->mate->user)->post(route('run.party.pool.claim', $entry->id))->assertRedirect(route('run.setup'));
        $this->assertSame($this->mate->id, (int) $entry->fresh()->claimed_by_character_id);
        $this->actingAs($this->mate->user)->post(route('run.party.pool.return', $entry->id))->assertRedirect(route('run.setup'));
        $this->assertNull($entry->fresh()->claimed_by_character_id);
    }

    public function test_the_trade_box_draws_the_offers_and_shows_only_with_somebody_to_trade_with(): void
    {
        $row = $this->give($this->owner);
        $this->pool->offer($this->owner, $this->give($this->owner)->id);
        $party = $this->party->fresh()->load('members.character', 'owner');

        $html = view('run._party_pool', ['party' => $party, 'character' => $this->mate])->render();
        $this->assertStringContainsString('Trade', $html);
        $this->assertDoesNotMatchRegularExpression('/data-party-pool-box\s+hidden/', $html, 'open with a joined mate');
        $this->assertStringContainsString($this->owner->handle, $html);
        $this->assertStringContainsString('Claim', $html);
        $this->assertStringNotContainsString('Add to run pool', $html, 'a mate with nothing eligible has nothing to add');

        $this->assertStringContainsString('Add to run pool', view('run._party_pool', ['party' => $party, 'character' => $this->owner])->render());

        RunPartyMember::query()->delete();
        $this->assertMatchesRegularExpression('/data-party-pool-box\s+hidden/', view('run._party_pool', ['party' => $this->party->fresh()->load('members.character', 'owner'), 'character' => $this->owner])->render());
        unset($row);
    }
}
