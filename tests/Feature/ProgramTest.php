<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Decks\DeckEquipper;
use App\Decks\Hardware;
use App\Decks\ModuleFitter;
use App\Decks\Variant;
use App\Inventory\Imprint;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Programs\ProcessRefusal;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use App\Stats\Sources\RunningPrograms;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Software: the first items in the game that change a number.
 *
 * Two halves get pinned here. The mechanical one — running costs compute and
 * memory, and only while it runs — and the stacking one, which is the fiddly
 * part: idle copies pile up and running ones must not, or two processes become
 * one row nobody can stop independently.
 */
final class ProgramTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private ProgramRunner $runner;

    private InventoryItem $deck;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->runner = new ProgramRunner;
        $this->deck = $this->buildReadyDeck();
    }

    private function own(Item $item, int $quantity = 1): InventoryItem
    {
        return InventoryItem::create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
        ]);
    }

    /** A deck that passes isReady(), with generous compute and memory. */
    private function buildReadyDeck(): InventoryItem
    {
        $deckItem = Item::factory()->cyberdeck([
            'cpu_socket_k' => 1, 'ram_opto' => 1, 'nic' => 1, 'psu' => 1,
        ])->create();

        $deck = $this->own($deckItem);
        $fitter = app(ModuleFitter::class);

        $parts = [
            ['cpu', Hardware::Cpu, Variant::SocketK, 100],
            ['ram', Hardware::Ram, Variant::Opto, 100],
            ['nic', Hardware::Nic, null, 50],
            ['psu', Hardware::Psu, null, 2000],
        ];

        foreach ($parts as [$kind, $hardware, $variant, $measure]) {
            $fitter->fit(
                $this->character,
                $deck,
                $this->socket($deckItem, $kind),
                $this->own(Item::factory()->module($hardware, $variant, measure: $measure, draw: 10)->create()),
            );
        }

        app(DeckEquipper::class)->equip($this->character, $deck);
        $this->character->refresh();

        return $deck;
    }

    private function socket(Item $deckItem, string $kind)
    {
        return $deckItem->deckSlots()->where('kind', $kind)->firstOrFail();
    }

    private function programItem(
        ProgramKind $kind = ProgramKind::Daemon,
        float $qflops = 10,
        float $memory = 10,
        array $modifiers = [],
        ?bool $background = null,
    ): Item {
        return Item::factory()
            ->program($kind, qflops: $qflops, memory: $memory, modifiers: $modifiers, background: $background)
            ->create();
    }

    // ---- the point of the whole phase -----------------------------------

    /**
     * The first item in the game whose effect goes through the resolver. It has
     * to change a stat while it runs and change nothing at all while it sits in
     * the stash.
     */
    public function test_a_running_program_modifies_a_stat_and_an_idle_one_does_not(): void
    {
        $item = $this->programItem(modifiers: [
            ['op' => 'add', 'amount' => 6, 'target' => ['id' => 'focus']],
        ]);
        $row = $this->own($item);

        $this->assertSame(20.0, $this->character->fresh()->stat('focus')->value, 'owning it does nothing');

        $result = $this->runner->start($this->character, $row);

        $this->assertTrue($result->succeeded(), $result->refusal?->value ?? '');
        $this->assertSame(26.0, $this->character->fresh()->stat('focus')->value);
    }

    /** And it stops mattering the moment it stops running. */
    public function test_stopping_a_program_takes_its_modifier_away(): void
    {
        $row = $this->own($this->programItem(modifiers: [
            ['op' => 'add', 'amount' => 6, 'target' => ['id' => 'focus']],
        ]));

        $process = $this->runner->start($this->character, $row)->process;
        $this->assertSame(26.0, $this->character->fresh()->stat('focus')->value);

        $this->runner->stop($this->character, $process);

        $this->assertSame(20.0, $this->character->fresh()->stat('focus')->value);
    }

    /**
     * Because a check is a stat, a program that helps you scan is one row of
     * JSON and no new code anywhere.
     */
    public function test_a_program_can_move_a_check(): void
    {
        $skill = Skill::factory()->governedBy('perception', 'memory')->create(['slug' => 'probe']);
        $statId = SkillStats::checkId('probe');

        SkillStats::forget();
        Sheet::forgetSkills();

        $before = $this->character->fresh()->stat($statId)->value;

        $row = $this->own($this->programItem(ProgramKind::Analyser, background: true, modifiers: [
            ['op' => 'add', 'amount' => 12, 'target' => ['id' => $statId]],
        ]));

        $this->runner->start($this->character, $row);

        $this->assertSame($before + 12.0, $this->character->fresh()->stat($statId)->value);
        $this->assertNotNull($skill->id);
    }

    // ---- budgets ---------------------------------------------------------

    public function test_running_spends_compute_and_memory_only_while_it_runs(): void
    {
        $row = $this->own($this->programItem(qflops: 30, memory: 40));

        $this->assertEquals(
            ['qflops' => 0, 'memory' => 0],
            $this->runner->spentOn($this->character, (int) $this->deck->id),
            'an idle program spends nothing'
        );

        $this->runner->start($this->character, $row);

        $this->assertEquals(
            ['qflops' => 30, 'memory' => 40],
            $this->runner->spentOn($this->character, (int) $this->deck->id)
        );
    }

    public function test_a_program_may_cost_a_fraction_of_a_unit(): void
    {
        $light = $this->own($this->programItem(qflops: 0.4, memory: 0.4));

        $this->runner->start($this->character, $light);

        $this->assertEquals(['qflops' => 0.4, 'memory' => 0.4], $this->runner->spentOn($this->character, (int) $this->deck->id));
        $this->assertSame(0.4, $light->item->program->memory_cost);
    }

    public function test_a_program_that_does_not_fit_the_compute_budget_is_refused(): void
    {
        $this->runner->start($this->character, $this->own($this->programItem(qflops: 90, memory: 1)));

        $result = $this->runner->start($this->character, $this->own($this->programItem(qflops: 20, memory: 1)));

        $this->assertFalse($result->succeeded());
        $this->assertSame(ProcessRefusal::NotEnoughCompute, $result->refusal);
    }

    public function test_a_program_that_does_not_fit_the_memory_budget_is_refused(): void
    {
        $this->runner->start($this->character, $this->own($this->programItem(qflops: 1, memory: 90)));

        $result = $this->runner->start($this->character, $this->own($this->programItem(qflops: 1, memory: 20)));

        $this->assertSame(ProcessRefusal::NotEnoughMemory, $result->refusal);
    }

    /** Exactly filling the budget is allowed. Over it is not. */
    public function test_the_budget_boundary_is_inclusive(): void
    {
        $result = $this->runner->start($this->character, $this->own($this->programItem(qflops: 100, memory: 100)));

        $this->assertTrue($result->succeeded(), 'a program that exactly fills the deck fits in it');
    }

    // ---- what could be started -------------------------------------------

    public function test_startable_lists_what_a_press_would_start_and_nothing_else(): void
    {
        $fits = $this->own($this->programItem(qflops: 10, memory: 10));
        $this->own($this->programItem(qflops: 500, memory: 10));          // too much compute
        $this->own($this->programItem(background: false));                // run-only
        $this->runner->start($this->character, $this->own($this->programItem(qflops: 90, memory: 1)));   // running now

        $listed = $this->runner->startable($this->character->fresh());

        $this->assertSame([$fits->id], $listed->pluck('id')->all());

        // And the writer agrees with the list: what it offers is what starts.
        $this->assertTrue($this->runner->start($this->character->fresh(), $listed->first())->succeeded());
    }

    public function test_startable_is_empty_without_a_deck_and_the_screen_says_why(): void
    {
        $this->own($this->programItem());
        app(DeckEquipper::class)->unequip($this->character, false, app(ModuleFitter::class));

        $this->assertCount(0, $this->runner->startable($this->character->fresh()));

        $this->actingAs($this->character->user)->get(route('programs.startable'))
            ->assertOk()->assertSee('No deck equipped');
    }

    public function test_the_fragment_offers_a_run_button_for_each_and_the_modal_asks_for_it(): void
    {
        $row = $this->own($this->programItem(qflops: 10, memory: 10), 3);

        $this->actingAs($this->character->user)->get(route('programs.startable'))
            ->assertOk()->assertSee(route('inventory.run', $row), false)->assertSee('Run')->assertSee('×3', false);

        $this->actingAs($this->character->user)->get(route('inventory.index'))
            ->assertOk()->assertSee('data-startable="'.route('programs.startable').'"', false);
    }

    // ---- gates -----------------------------------------------------------

    /**
     * An icebreaker outside a run is a deck spending compute on nothing — and
     * worse, compute the run the player wanted will not have. The run starts
     * these, not the player.
     */
    public function test_a_run_only_program_cannot_be_started_by_hand(): void
    {
        $result = $this->runner->start($this->character, $this->own($this->programItem(ProgramKind::Icebreaker)));

        $this->assertSame(ProcessRefusal::RunOnly, $result->refusal);
    }

    public function test_a_run_only_program_starts_when_a_run_says_so(): void
    {
        $result = $this->runner->start(
            $this->character,
            $this->own($this->programItem(ProgramKind::Icebreaker)),
            duringRun: true,
        );

        $this->assertTrue($result->succeeded(), $result->refusal?->value ?? '');
    }

    public function test_software_needs_a_deck_to_run_on(): void
    {
        app(DeckEquipper::class)->unequip($this->character, false, app(ModuleFitter::class));
        $this->character->refresh();

        $result = $this->runner->start($this->character, $this->own($this->programItem()));

        $this->assertSame(ProcessRefusal::NoDeckEquipped, $result->refusal);
    }

    public function test_a_non_program_cannot_be_run(): void
    {
        $result = $this->runner->start($this->character, $this->own(Item::factory()->create()));

        $this->assertSame(ProcessRefusal::NotAProgram, $result->refusal);
    }

    public function test_somebody_elses_program_cannot_be_run(): void
    {
        $other = Character::factory()->create(['archetype' => 3]);
        $row = InventoryItem::create([
            'character_id' => $other->id,
            'item_id' => $this->programItem()->id,
            'quantity' => 1,
        ]);

        $this->assertSame(ProcessRefusal::NotOwned, $this->runner->start($this->character, $row)->refusal);
    }

    // ---- stacking, which is the fiddly half ------------------------------

    /**
     * The rule: idle copies pile up, running ones do not. Starting one out of a
     * stack of three has to leave two behind and give the runner its own row.
     */
    public function test_starting_one_out_of_a_stack_splits_it(): void
    {
        $item = $this->programItem();
        $stack = $this->own($item, quantity: 3);

        $process = $this->runner->start($this->character, $stack)->process;

        $this->assertNotNull($process);
        $this->assertSame(1, $process->quantity);
        $this->assertNull($process->stack_key, 'a running row never joins a pile');
        $this->assertSame(2, $stack->fresh()->quantity);
    }

    /**
     * Two running copies are two rows. If they merged, a player could not stop
     * one of them — which is the failure the stack_key rewrite existed to kill
     * for cyberdecks, one subsystem later.
     */
    public function test_two_running_copies_stay_two_rows(): void
    {
        // Two copies at once needs software that multitasks and a character
        // allowed two threads. Neither is what this test is about — it is
        // about the two rows staying two rows — so both are simply granted.
        Config::set('programs.threads', 2);

        $item = $this->programItem(qflops: 5, memory: 5);
        $item->program->forceFill(['multithreaded' => true])->save();

        $stack = $this->own($item, quantity: 2);

        $first = $this->runner->start($this->character, $stack)->process;
        $second = $this->runner->start($this->character, $stack->fresh())->process;

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, InventoryItem::query()->running()->count());
    }

    /**
     * Stopping MERGES back into the pile. Clearing the columns alone would give
     * the row the pile's stack_key, and that index is unique.
     */
    public function test_stopping_merges_back_into_the_pile(): void
    {
        $item = $this->programItem();
        $stack = $this->own($item, quantity: 3);

        $process = $this->runner->start($this->character, $stack)->process;
        $this->runner->stop($this->character, $process);

        $rows = InventoryItem::query()->where('item_id', $item->id)->get();

        $this->assertCount(1, $rows, 'one pile again, not a pile and an orphan');
        $this->assertSame(3, $rows->first()->quantity);
    }

    public function test_stopping_the_last_copy_leaves_one_idle_row(): void
    {
        $item = $this->programItem();
        $row = $this->own($item);

        $process = $this->runner->start($this->character, $row)->process;
        $this->runner->stop($this->character, $process);

        $rows = InventoryItem::query()->where('item_id', $item->id)->get();

        $this->assertCount(1, $rows);
        $this->assertFalse($rows->first()->isRunning());
    }

    // ---- being spoken for ------------------------------------------------

    /**
     * A fourth way of being spoken for, decided in scopeLoose() alongside
     * containment, fitting and being the equipped deck — so no listing had to
     * learn what a process is.
     */
    public function test_a_running_program_is_not_in_the_stash(): void
    {
        $item = $this->programItem();
        $row = $this->own($item);

        $this->assertTrue(InventoryItem::query()->loose()->where('item_id', $item->id)->exists());

        $this->runner->start($this->character, $row);

        $this->assertFalse(InventoryItem::query()->loose()->where('item_id', $item->id)->exists());
    }

    /** Unequipping is what stops everything. Nothing is destroyed. */
    public function test_stop_all_returns_every_program_to_its_owner(): void
    {
        $this->runner->start($this->character, $this->own($this->programItem(qflops: 5, memory: 5)));
        $this->runner->start($this->character, $this->own($this->programItem(qflops: 5, memory: 5)));

        $result = $this->runner->stopAll($this->character, $this->deck);

        $this->assertSame(2, $result->stopped);
        $this->assertSame(0, InventoryItem::query()->running()->count());
        $this->assertSame(2, InventoryItem::query()->loose()->whereHas('item.program')->count());
    }

    public function test_only_the_equipped_decks_processes_are_read(): void
    {
        $row = $this->own($this->programItem(modifiers: [
            ['op' => 'add', 'amount' => 6, 'target' => ['id' => 'focus']],
        ]));

        $this->runner->start($this->character, $row);
        $this->assertCount(1, (new RunningPrograms($this->character->fresh()))->modifiers());

        app(DeckEquipper::class)->unequip($this->character, false, app(ModuleFitter::class));

        $this->assertCount(
            0,
            (new RunningPrograms($this->character->fresh()))->modifiers(),
            'no deck carried means no software of yours is running'
        );
    }

    // ---- what RESTRICT obliges -------------------------------------------

    /**
     * `running_on_deck_id` is RESTRICT, so a deck with a live process cannot
     * be deleted at all — the destroy path has to stop everything first or the
     * player gets a constraint name instead of a confirmation.
     */
    public function test_destroying_a_deck_stops_its_processes_and_keeps_the_software(): void
    {
        $item = $this->programItem();
        $this->runner->start($this->character, $this->own($item));

        $this->actingAs($this->character->user)
            ->post(route('inventory.destroy', $this->deck))
            ->assertRedirect();

        $this->assertNull($this->deck->fresh(), 'the deck is gone');
        $this->assertSame(0, InventoryItem::query()->running()->count());
        $this->assertTrue(
            InventoryItem::query()->loose()->where('item_id', $item->id)->exists(),
            'the software went back to the stash rather than dying with the machine'
        );
    }

    /** Putting the machine down stops what was running on it. */
    public function test_unequipping_stops_every_process(): void
    {
        $this->runner->start($this->character, $this->own($this->programItem(qflops: 5, memory: 5)));
        $this->runner->start($this->character, $this->own($this->programItem(qflops: 5, memory: 5)));

        app(DeckEquipper::class)->unequip($this->character, false, app(ModuleFitter::class));

        $this->assertSame(0, InventoryItem::query()->running()->count());
        $this->assertSame(2, InventoryItem::query()->loose()->whereHas('item.program')->count());
    }

    /**
     * A process was admitted against a compute and memory budget. Change the
     * hardware and that budget changes, so everything stops rather than being
     * re-checked against a machine it never agreed to.
     */
    public function test_fitting_a_module_stops_every_process(): void
    {
        $this->runner->start($this->character, $this->own($this->programItem()));
        $this->assertSame(1, InventoryItem::query()->running()->count());

        $deckItem = Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'psu' => 1, 'expansion_pei' => 1])->create();
        $spare = $this->own($deckItem);

        app(ModuleFitter::class)->fit(
            $this->character,
            $spare,
            $this->socket($deckItem, 'expansion'),
            $this->own(Item::factory()->module(Hardware::Expansion, Variant::Pei, draw: 5)->create()),
        );

        // Not this deck's processes — but the rule is per deck, so prove the
        // one that DID change is the one that stopped.
        $this->assertSame(1, InventoryItem::query()->running()->count(), 'another deck is not affected');

        app(ModuleFitter::class)->strip($this->character, $this->deck);

        $this->assertSame(0, InventoryItem::query()->running()->count());
    }

    public function test_unfitting_a_module_stops_every_process(): void
    {
        $this->runner->start($this->character, $this->own($this->programItem()));

        app(ModuleFitter::class)->unfit(
            $this->character,
            $this->deck,
            $this->socket($this->deck->item, 'nic'),
        );

        $this->assertSame(0, InventoryItem::query()->running()->count());
        $this->assertSame(
            1,
            InventoryItem::query()->loose()->whereHas('item.program')->count(),
            'the software came back rather than dying with the loadout'
        );
    }

    // ---- the card and the route ------------------------------------------

    /**
     * One route for both directions, like inventory.place and deck.fit:
     * running and not running are the same question about the same row.
     */
    public function test_the_route_starts_a_program_and_then_stops_it(): void
    {
        $item = $this->programItem();
        $row = $this->own($item);

        $this->actingAs($this->character->user)
            ->post(route('inventory.run', $row))
            ->assertRedirect()
            ->assertSessionHas('status');

        $process = InventoryItem::query()->running()->firstOrFail();
        $this->assertSame($item->id, $process->item_id);

        $this->actingAs($this->character->user)
            ->post(route('inventory.run', $process))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, InventoryItem::query()->running()->count());
    }

    /**
     * Offered rather than hidden, so the refusal can teach: "the run starts
     * this, not you" says more than an absent button.
     */
    public function test_a_refusal_comes_back_as_a_sentence(): void
    {
        $row = $this->own($this->programItem(ProgramKind::Icebreaker));

        $this->actingAs($this->character->user)
            ->post(route('inventory.run', $row))
            ->assertRedirect()
            ->assertSessionHas('error', ProcessRefusal::RunOnly->message());
    }

    public function test_somebody_elses_program_is_not_found(): void
    {
        $other = Character::factory()->create(['archetype' => 3]);
        $row = InventoryItem::create([
            'character_id' => $other->id,
            'item_id' => $this->programItem()->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->character->user)
            ->post(route('inventory.run', $row))
            ->assertNotFound();
    }

    /**
     * A program says what it is FOR, not which inventory it happens to sit in.
     * "Data" is the one thing the player can already see from where it sits.
     */
    public function test_the_card_names_the_kind_and_the_costs(): void
    {
        $item = $this->programItem(ProgramKind::Decryptor, qflops: 12, memory: 8);
        $this->own($item);

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee(ProgramKind::Decryptor->label())
            ->assertSee(Text::get('programs.costs.compute', ['value' => 12]))
            ->assertSee(Text::get('programs.action.start'));
    }

    /**
     * A running program is not `loose()`, so it is not in the stash — the same
     * rule that keeps a fitted module and the equipped deck out. It is listed
     * and stopped on the DECK screen, beside the compute and memory it is
     * spending, which is the context the decision needs.
     */
    public function test_a_running_program_is_listed_and_stopped_on_the_deck_screen(): void
    {
        $item = $this->programItem(qflops: 12, memory: 8);
        $this->runner->start($this->character, $this->own($item));

        $process = InventoryItem::query()->running()->firstOrFail();

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $this->deck))
            ->assertOk()
            ->assertSee($item->displayName())
            ->assertSee(Text::get('programs.action.stop'))
            ->assertSee(route('inventory.run', $process), false)
            // The budget a start is checked against, where the starting happens.
            ->assertSee(Text::get('programs.budget', ['used' => 12, 'total' => 100]));

        // And it really has left the stash.
        $this->assertFalse(
            InventoryItem::query()->loose()->whereKey($process->getKey())->exists(),
            'a running program is spoken for, like a fitted module'
        );
    }

    /**
     * The layout's own processes dialog, reachable from every page — it
     * posts to the same `inventory.run` route the deck screen's Stop button
     * does, since `ProgramRunner::stop()` is the only writer either way.
     */
    public function test_a_running_program_is_stopped_from_the_processes_dialog(): void
    {
        $item = $this->programItem(qflops: 12, memory: 8);
        $this->runner->start($this->character, $this->own($item));

        $process = InventoryItem::query()->running()->firstOrFail();

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('<dialog id="processes"', false)
            ->assertSee($item->displayName())
            ->assertSee(Text::get('programs.action.stop'))
            ->assertSee(route('inventory.run', $process), false);

        $this->actingAs($this->character->user)
            ->post(route('inventory.run', $process))
            ->assertRedirect();

        $this->assertTrue(
            InventoryItem::query()->loose()->whereKey($process->getKey())->exists(),
            'stopping from the dialog merges the program back into the stash, same as the deck screen'
        );
    }

    /**
     * The other tab. Storage is split because a program and a file are not the
     * same kind of thing: one is something you run, the other something you
     * hold.
     */
    public function test_the_storage_tab_separates_programs_from_data(): void
    {
        $program = $this->programItem();
        $this->own($program);

        $skill = Skill::factory()->create(['slug' => 'quietfoot']);
        $book = Imprint::ensureFor($skill);
        $this->own($book);

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $this->deck))
            ->assertOk()
            ->assertSee(Text::get('programs.tabs.storage'))
            ->assertSee(Text::get('programs.storage.programs'))
            ->assertSee(Text::get('programs.storage.data'))
            ->assertSee($program->displayName())
            ->assertSee($book->displayName());
    }

    /** Putting a deck down stops everything, so an unequipped one says so. */
    public function test_an_unequipped_deck_explains_its_empty_list(): void
    {
        app(DeckEquipper::class)->unequip($this->character, false, app(ModuleFitter::class));

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $this->deck))
            ->assertOk()
            ->assertSee(Text::get('programs.not_equipped'));
    }
}
