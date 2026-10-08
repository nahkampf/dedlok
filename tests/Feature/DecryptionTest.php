<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Decryption\DecryptionQueue;
use App\Decryption\DecryptionRefusal;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\DecryptionEntry;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Skill;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Stats\Modifier;
use App\Stats\Resolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** Breaking stolen files: the queue, the cycle clock, and the one roll at the end of each cycle. */
final class DecryptionTest extends TestCase
{
    use CarriesAWorkingDeck, RefreshDatabase;

    private Character $character;

    private Skill $cinder;

    private Item $dump;

    private float $die = 0.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfMinute());

        // Pinned: 0.0 passes every check, 0.99 fails all but a certainty.
        $this->app->bind(CheckRoller::class, fn (): CheckRoller => CheckRoller::fromConfig(fn (): float => $this->die));

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->cinder = Skill::factory()->create(['slug' => 'cinder', 'category' => 'Encryption', 'rank' => 2]);
        $this->dump = Item::query()->where('slug', 'encrypted-data-dump')->firstOrFail();
    }

    private function inject(): void
    {
        app(SkillInjector::class)->grant($this->character, $this->cinder, 1);
    }

    private function file(?int $skillId = null, string $label = 'Access logs'): InventoryItem
    {
        return InventoryItem::create([
            'character_id' => $this->character->id, 'item_id' => $this->dump->id, 'quantity' => 1,
            'extracted_file' => ['label' => $label, 'size_qb' => 2, 'encryption_skill_id' => $skillId ?? $this->cinder->id, 'value_sealed' => 40, 'value_broken' => 90, 'grade' => 1],
        ]);
    }

    private function startDecryptor(): InventoryItem
    {
        $this->equipReadyDeck($this->character);
        $item = Item::factory()->program(ProgramKind::Decryptor)->create();
        $row = $this->ownedBy($this->character, $item);
        $result = app(ProgramRunner::class)->start($this->character->fresh(), $row);
        $this->assertNotNull($result->process, 'the decryptor starts');

        return $result->process;
    }

    private function queue(): DecryptionQueue
    {
        return DecryptionQueue::make();
    }

    public function test_the_gate_is_the_injected_skill_and_only_unopened_files_queue(): void
    {
        $file = $this->file();

        $this->assertSame(DecryptionRefusal::NoSkill, $this->queue()->enqueue($this->character->fresh(), $file));

        $this->inject();
        $this->assertInstanceOf(DecryptionEntry::class, $this->queue()->enqueue($this->character->fresh(), $file));
        $this->assertSame(DecryptionRefusal::AlreadyQueued, $this->queue()->enqueue($this->character->fresh(), $file));

        $open = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => Item::query()->where('slug', 'memory-dump')->value('id'), 'quantity' => 1, 'extracted_file' => $file->extracted_file]);
        $this->assertSame(DecryptionRefusal::AlreadyOpen, $this->queue()->enqueue($this->character->fresh(), $open));

        $bare = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $this->dump->id, 'quantity' => 1, 'extracted_file' => ['label' => 'x', 'size_qb' => 1, 'encryption_skill_id' => null, 'value_sealed' => 1, 'value_broken' => 2]]);
        $this->assertSame(DecryptionRefusal::Unbreakable, $this->queue()->enqueue($this->character->fresh(), $bare));
    }

    public function test_injected_but_untrained_is_not_enough_and_a_modifier_shortens_the_cycle(): void
    {
        app(SkillInjector::class)->grant($this->character, $this->cinder, 0);
        $file = $this->file();

        $this->assertSame(DecryptionRefusal::NoSkill, $this->queue()->enqueue($this->character->fresh(), $file), 'level 0 is below the gate');

        CharacterSkill::query()->where('character_id', $this->character->id)->update(['attained_level' => 1, 'skill_points' => $this->cinder->pointsForLevel(1)]);
        $this->assertInstanceOf(DecryptionEntry::class, $this->queue()->enqueue($this->character->fresh(), $file));

        $this->assertSame(300, DecryptionQueue::cycleSeconds($this->character->fresh()));

        $faster = app(Resolver::class)->resolve('decryption_cycle_seconds', 300.0, [
            Modifier::fromArray(['op' => 'percentage_of_base', 'amount' => -10, 'target' => ['id' => 'decryption_cycle_seconds']]),
        ])->toInt();
        $this->assertSame(270, $faster);
    }

    public function test_chance_and_time_are_targetable_for_one_encryption_or_all_of_them(): void
    {
        $this->cinder->update(['modifiers' => array_map(fn (int $l): array => ['op' => 'add', 'level' => $l, 'amount' => 2, 'target' => ['id' => 'decryption_chance.cinder']], range(1, 5))]);
        $cryptology = Skill::query()->where('slug', 'cryptology')->firstOrFail();
        $precomputation = Skill::query()->where('slug', 'precomputation')->firstOrFail();

        app(SkillInjector::class)->grant($this->character, $this->cinder, 3);
        app(SkillInjector::class)->grant($this->character, $cryptology, 2);
        app(SkillInjector::class)->grant($this->character, $precomputation, 5);
        $character = $this->character->fresh();

        // +2% a level of its own encryption (3 levels) and +2% a level of Cryptology (2 levels): 10% in all.
        $this->assertEqualsWithDelta(1.10, DecryptionQueue::chanceMultiplier($character, $this->cinder), 0.0001);
        // -2% a level of Precomputation, five levels, off 300 seconds.
        $this->assertSame(270, DecryptionQueue::cycleSeconds($character));

        // Another encryption gets only the global part.
        $other = Skill::factory()->create(['slug' => 'halcyon3', 'category' => 'Encryption', 'rank' => 4]);
        $this->assertEqualsWithDelta(1.04, DecryptionQueue::chanceMultiplier($character, $other), 0.0001);
    }

    public function test_ghostkey_is_a_cinder_only_decryptor_and_a_limited_one_waits_for_other_files(): void
    {
        $ghostkey = Item::query()->where('slug', 'ghostkey')->firstOrFail();
        $program = $ghostkey->program;

        $this->assertSame(['cinder'], $program->decrypts);
        $this->assertSame([ProgramKind::Decryptor, 3, 2, 2, 0, 25, 'common', true], [
            $program->kind, $program->qflops_cost, $program->memory_cost, $program->storage_size,
            $ghostkey->base_cost, $ghostkey->seed_quantity, $ghostkey->rarity->value, (bool) $ghostkey->on_market,
        ]);

        $this->inject();
        $halcyon = Skill::factory()->create(['slug' => 'halcyon3', 'category' => 'Encryption', 'rank' => 4]);
        app(SkillInjector::class)->grant($this->character, $halcyon, 1);

        $other = $this->file($halcyon->id, 'Hard one');
        $entry = $this->queue()->enqueue($this->character->fresh(), $other);

        $this->equipReadyDeck($this->character);
        $this->assertNotNull(app(ProgramRunner::class)->start($this->character->fresh(), $this->ownedBy($this->character, $ghostkey))->process);

        $this->queue()->settle($this->character->fresh());
        $this->assertNull($entry->fresh()->cycle_started_at, 'Ghostkey cannot touch Halcyon-3, so nothing is counting');

        $this->queue()->remove($this->character->fresh(), $entry);
        $cinder = $this->queue()->enqueue($this->character->fresh(), $this->file());
        $this->queue()->settle($this->character->fresh());
        $this->assertNotNull($cinder->fresh()->cycle_started_at, 'and it works on Cinder');
    }

    private function startAnother(Item $program): InventoryItem
    {
        $row = $this->ownedBy($this->character, $program);

        return app(ProgramRunner::class)->start($this->character->fresh(), $row)->process;
    }

    public function test_two_decryptors_break_two_files_at_once_and_never_the_same_one(): void
    {
        $this->inject();
        $first = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'One'));
        $second = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'Two'));
        $third = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'Three'));

        config(['programs.threads' => 2]);
        $this->equipReadyDeck($this->character);
        $ghostkey = Item::query()->where('slug', 'ghostkey')->firstOrFail();
        $this->startAnother($ghostkey);
        $this->startAnother($ghostkey);
        $this->queue()->settle($this->character->fresh());

        $workers = DecryptionEntry::query()->orderBy('position')->pluck('worker_id', 'id');
        $this->assertNotNull($workers[$first->id]);
        $this->assertNotNull($workers[$second->id]);
        $this->assertNull($workers[$third->id], 'two hands, three files: the third waits');
        $this->assertNotSame($workers[$first->id], $workers[$second->id], 'one decryptor never works two files');

        $this->die = 0.0;
        $this->travel(6)->minutes();
        $this->assertSame(2, $this->queue()->settle($this->character->fresh()), 'both broke in the same cycle');
        $this->assertSame(1, DecryptionEntry::query()->count());
        $this->assertNotNull($third->fresh()->worker_id, 'a freed decryptor takes the next file in line');
    }

    public function test_stopping_one_of_two_decryptors_frees_only_its_file(): void
    {
        $this->inject();
        $first = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'One'));
        $second = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'Two'));
        config(['programs.threads' => 2]);
        $this->equipReadyDeck($this->character);
        $ghostkey = Item::query()->where('slug', 'ghostkey')->firstOrFail();
        $a = $this->startAnother($ghostkey);
        $this->startAnother($ghostkey);
        $this->queue()->settle($this->character->fresh());

        $stopped = DecryptionEntry::query()->where('worker_id', $a->id)->sole();
        $other = DecryptionEntry::query()->where('worker_id', '!=', $a->id)->sole();
        $clock = $other->cycle_started_at;

        app(ProgramRunner::class)->stop($this->character->fresh(), $a);

        $this->assertNull($stopped->fresh()->worker_id);
        $this->assertNull($stopped->fresh()->cycle_started_at);
        $this->assertTrue($other->fresh()->cycle_started_at->equalTo($clock), 'the other decryptor keeps its cycle');
    }

    public function test_the_queue_can_be_reordered_and_a_worked_file_keeps_its_decryptor(): void
    {
        $this->inject();
        $a = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'A'));
        $b = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'B'));
        $c = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'C'));
        $this->startDecryptor();
        $this->queue()->settle($this->character->fresh());
        $worker = $a->fresh()->worker_id;
        $clock = $a->fresh()->cycle_started_at;

        $this->assertTrue($this->queue()->reorder($this->character->fresh(), [$c->id, $b->id, $a->id]));

        $this->assertSame([$c->id, $b->id, $a->id], DecryptionEntry::query()->orderBy('position')->pluck('id')->all());
        $this->assertSame($worker, $a->fresh()->worker_id, 'the file already being worked stays with its decryptor');
        $this->assertTrue($a->fresh()->cycle_started_at->equalTo($clock));

        $this->assertSame(DecryptionRefusal::BadOrder, $this->queue()->reorder($this->character->fresh(), [$c->id, $b->id]), 'every entry, once');
        $this->assertSame(DecryptionRefusal::BadOrder, $this->queue()->reorder($this->character->fresh(), [$c->id, $b->id, $a->id + 99]));
    }

    public function test_a_waiting_file_moved_to_the_front_is_what_a_freed_decryptor_takes(): void
    {
        $this->inject();
        $a = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'A'));
        $b = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'B'));
        $c = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'C'));
        $this->startDecryptor();
        $this->queue()->settle($this->character->fresh());

        $this->queue()->reorder($this->character->fresh(), [$a->id, $c->id, $b->id]);

        $this->die = 0.0;
        $this->travel(6)->minutes();
        $this->queue()->settle($this->character->fresh());

        $this->assertNotNull($c->fresh()->worker_id, 'C was moved ahead of B');
        $this->assertNull($b->fresh()->worker_id);
    }

    public function test_the_stash_card_says_where_a_file_stands(): void
    {
        $this->inject();
        $file = $this->file();
        $this->actingAs($this->character->user);

        $card = fn (): string => view('components.item-detail', ['entry' => $file->fresh()->load('item'), 'item' => $file->fresh()->item])->render();
        $this->assertStringContainsString(route('decryption.store', $file), $card(), 'a sealed file offers Decrypt');

        $this->queue()->enqueue($this->character->fresh(), $file);
        $html = $card();
        $this->assertStringNotContainsString(route('decryption.store', $file), $html);
        $this->assertStringContainsString('Queued for decryption (1)', $html);

        $this->startDecryptor();
        $this->queue()->settle($this->character->fresh());
        $this->assertStringContainsString('Being decrypted', $card());
    }

    public function test_nothing_runs_without_a_decryptor_and_the_clock_starts_when_one_does(): void
    {
        $this->inject();
        $entry = $this->queue()->enqueue($this->character->fresh(), $this->file());

        $this->travel(30)->minutes();
        $this->queue()->settle($this->character->fresh());
        $this->assertNull($entry->fresh()->cycle_started_at, 'no decryptor, no clock');

        $this->startDecryptor();
        $this->queue()->settle($this->character->fresh());
        $this->assertNotNull($entry->fresh()->cycle_started_at);
    }

    public function test_a_passed_cycle_turns_the_file_into_a_memory_dump_in_place_and_says_so(): void
    {
        $this->inject();
        $file = $this->file();
        $this->queue()->enqueue($this->character->fresh(), $file);
        $this->startDecryptor();
        $this->queue()->settle($this->character->fresh());

        $this->die = 0.0;
        $this->travel(6)->minutes();
        $this->assertSame(1, $this->queue()->settle($this->character->fresh()));

        $this->assertSame('memory-dump', $file->fresh()->item->slug, 'the same row, now open');
        $this->assertSame(90, $file->fresh()->extractedFile()->valueBroken, 'its numbers carry over');
        $this->assertSame(0, DecryptionEntry::query()->count());
        $this->assertSame('Decrypted: Access logs', Notification::query()->sole()->title);
    }

    public function test_a_failed_cycle_costs_only_the_cycle_and_a_catch_up_rolls_every_one_missed(): void
    {
        $this->inject();
        $file = $this->file();
        $entry = $this->queue()->enqueue($this->character->fresh(), $file);
        $this->startDecryptor();
        $this->queue()->settle($this->character->fresh());
        $started = $entry->fresh()->cycle_started_at;

        $this->die = 0.999;
        $this->travel(12)->minutes();
        $this->assertSame(0, $this->queue()->settle($this->character->fresh()));

        $this->assertSame('encrypted-data-dump', $file->fresh()->item->slug, 'a failure destroys nothing');
        $this->assertTrue($entry->fresh()->cycle_started_at->equalTo($started->addMinutes(10)), 'two whole cycles spent, two minutes left over');
    }

    public function test_the_next_file_inherits_the_moment_the_last_one_broke(): void
    {
        $this->inject();
        $first = $this->file(label: 'First');
        $second = $this->file(label: 'Second');
        $this->queue()->enqueue($this->character->fresh(), $first);
        $next = $this->queue()->enqueue($this->character->fresh(), $second);
        $this->startDecryptor();
        $this->queue()->settle($this->character->fresh());
        $started = DecryptionEntry::query()->where('position', 1)->value('cycle_started_at');

        $this->die = 0.0;
        $this->travel(5)->minutes();
        $this->queue()->settle($this->character->fresh());

        $head = $next->fresh();
        $this->assertSame(1, $head->position);
        $this->assertTrue($head->cycle_started_at->equalTo(CarbonImmutable::parse($started)->addMinutes(5)));
    }

    public function test_stopping_the_decryptor_credits_the_time_it_ran_and_closes_the_cycle(): void
    {
        $this->inject();
        $file = $this->file();
        $entry = $this->queue()->enqueue($this->character->fresh(), $file);
        $decryptor = $this->startDecryptor();
        $this->queue()->settle($this->character->fresh());

        $this->die = 0.0;
        $this->travel(7)->minutes();
        app(ProgramRunner::class)->stop($this->character->fresh(), $decryptor);

        $this->assertSame('memory-dump', $file->fresh()->item->slug, 'the cycle that finished while it ran counted');

        $other = $this->file(label: 'Other');
        $queued = $this->queue()->enqueue($this->character->fresh(), $other);
        $this->assertNull($queued->fresh()->cycle_started_at, 'nothing is running, so nothing is counting');
    }

    public function test_removing_the_file_being_worked_on_is_refused_while_jacked_in_and_others_keep_the_clock(): void
    {
        $this->inject();
        $head = $this->queue()->enqueue($this->character->fresh(), $this->file());
        $tail = $this->queue()->enqueue($this->character->fresh(), $this->file(label: 'Tail'));
        $this->startDecryptor();
        $this->queue()->settle($this->character->fresh());
        $clock = $head->fresh()->cycle_started_at;

        $this->assertTrue($this->queue()->remove($this->character->fresh(), $tail));
        $this->assertTrue($head->fresh()->cycle_started_at->equalTo($clock), 'taking out a waiting file leaves the cycle alone');

        $this->character->forceFill(['grid_x' => 1, 'grid_y' => 1])->save();
        $this->assertSame(DecryptionRefusal::LockedIn, $this->queue()->remove($this->character->fresh(), $head->fresh()));
    }
}
