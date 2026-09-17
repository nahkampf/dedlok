<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Programs\ProcessRefusal;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * How many copies of one program a character may run at once.
 *
 * Two rules and they answer different questions. **May this software be
 * pointed at two things at all** is a property of the program — an icebreaker
 * fighting two pieces of ICE is not a thing skill should be able to buy — and
 * **how many may THIS character run** is a stat, one by default, raised by
 * Multithreading.
 */
final class ProgramThreadTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->equipReadyDeck($this->character);
    }

    private function runner(): ProgramRunner
    {
        return app(ProgramRunner::class);
    }

    /** A pile of the same program, so there is something to start twice. */
    private function pileOf(bool $multithreaded, int $copies = 3): InventoryItem
    {
        $item = Item::factory()->program(ProgramKind::Daemon, qflops: 1, memory: 1, background: true)->create();
        $item->program->forceFill(['multithreaded' => $multithreaded])->save();

        return InventoryItem::create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'quantity' => $copies,
        ]);
    }

    private function allowThreads(int $threads): void
    {
        Config::set('effects.threading', [
            'label' => 'Threading',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [
                ['op' => 'add', 'amount' => $threads - 1, 'target' => ['id' => 'program_threads']],
            ],
        ]);

        CharacterModifier::query()->create([
            'character_id' => $this->character->id,
            'definition_key' => 'threading',
        ]);
    }

    private function running(int $itemId): int
    {
        return InventoryItem::query()
            ->where('character_id', $this->character->id)
            ->where('item_id', $itemId)
            ->whereNotNull('running_on_deck_id')
            ->count();
    }

    // ---- one at a time, by default -----------------------------------------

    public function test_a_new_character_runs_one_copy_of_anything(): void
    {
        $pile = $this->pileOf(multithreaded: true);

        $this->assertTrue($this->runner()->start($this->character->fresh(), $pile)->succeeded());

        $second = $this->runner()->start($this->character->fresh(), $pile->fresh());

        $this->assertSame(ProcessRefusal::NoFreeThread, $second->refusal);
        $this->assertSame(1, $this->running($pile->item_id));
    }

    // ---- what the skill buys ------------------------------------------------

    public function test_a_thread_buys_a_second_copy(): void
    {
        $this->allowThreads(2);
        $pile = $this->pileOf(multithreaded: true);

        $this->assertTrue($this->runner()->start($this->character->fresh(), $pile)->succeeded());
        $this->assertTrue($this->runner()->start($this->character->fresh(), $pile->fresh())->succeeded());

        $third = $this->runner()->start($this->character->fresh(), $pile->fresh());

        $this->assertSame(ProcessRefusal::NoFreeThread, $third->refusal);
        $this->assertSame(2, $this->running($pile->item_id));
    }

    /**
     * A CEILING PER PROGRAM, NEVER A TOTAL. Two threads means two scanners or
     * two daemons, not two processes.
     */
    public function test_threads_are_counted_per_program(): void
    {
        $this->allowThreads(1);
        $one = $this->pileOf(multithreaded: true);
        $two = $this->pileOf(multithreaded: true);

        $this->assertTrue($this->runner()->start($this->character->fresh(), $one)->succeeded());
        $this->assertTrue($this->runner()->start($this->character->fresh(), $two)->succeeded());
    }

    // ---- what the software allows ------------------------------------------

    /** No amount of skill runs two of something that cannot multitask. */
    public function test_software_that_cannot_multitask_is_capped_at_one_however_good_you_are(): void
    {
        $this->allowThreads(4);
        $pile = $this->pileOf(multithreaded: false);

        $this->assertTrue($this->runner()->start($this->character->fresh(), $pile)->succeeded());

        $second = $this->runner()->start($this->character->fresh(), $pile->fresh());

        $this->assertSame(ProcessRefusal::NoFreeThread, $second->refusal);
        $this->assertSame(1, $this->running($pile->item_id));
    }

    /** Stopping one frees the thread it was in. */
    public function test_stopping_one_makes_room_for_another(): void
    {
        $pile = $this->pileOf(multithreaded: true);

        $first = $this->runner()->start($this->character->fresh(), $pile);
        $this->runner()->stop($this->character->fresh(), $first->process);

        $this->assertTrue($this->runner()->start($this->character->fresh(), $pile->fresh())->succeeded());
        $this->assertSame(1, $this->running($pile->item_id));
    }

    /** The shipped scanners are the software this was built for. */
    public function test_the_scanners_ship_able_to_multitask(): void
    {
        $scanner = Item::query()->where('slug', 'scanner-mk1')->first();

        $this->assertNotNull($scanner, 'the scanners arrive by migration');
        $this->assertTrue($scanner->program->multithreaded);
    }
}
