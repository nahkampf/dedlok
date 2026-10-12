<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\EncounterOutcomes;
use App\City\OutcomeBranch;
use App\City\OutcomeKind;
use App\Journal\Journal;
use App\Models\Character;
use App\Models\Encounter;
use App\Models\EncounterNode;
use App\Models\EncounterOutcome;
use App\Models\JournalEntry;
use App\Models\JournalNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The journal on the Tasks & Missions screen, and the encounter outcomes that write it. */
final class JournalTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Journal $journal;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(['archetype' => 3]);
        $this->journal = new Journal;
    }

    private function outcome(OutcomeKind $kind, string $ref, array $more = []): EncounterOutcome
    {
        $encounter = Encounter::create(['slug' => 'j_'.(++$this->made), 'title' => 'x', 'starts_on' => 'travel', 'weight' => 1, 'once' => false, 'retired' => false]);
        $node = EncounterNode::create(['encounter_id' => $encounter->id, 'node_key' => 'n', 'body' => 'x', 'entry' => true, 'ends' => true]);

        return EncounterOutcome::create(['node_id' => $node->id, 'branch' => OutcomeBranch::Always, 'position' => 0, 'kind' => $kind, 'ref' => $ref] + $more);
    }

    private function page(): string
    {
        return $this->actingAs($this->character->user->fresh())->get(route('tasks.index'))->assertOk()->getContent();
    }

    public function test_a_note_a_line_or_both_can_be_written(): void
    {
        $this->assertSame(['note' => true, 'entry' => true], $this->journal->write($this->character, 'njord', 'NJORD', 'saw_the_files', 'I saw something I should not have.'));
        $this->assertSame(['note' => false, 'entry' => true], $this->journal->write($this->character, 'njord', null, 'asked_around', 'Nobody will say.'));
        $this->assertSame(['note' => true, 'entry' => false], $this->journal->write($this->character, 'other', 'Another', null, null));

        $this->assertSame(2, JournalNote::query()->count());
        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_writing_twice_adds_nothing_the_second_time(): void
    {
        $this->journal->write($this->character, 'njord', 'NJORD', 'line_a', 'text');
        $again = $this->journal->write($this->character, 'njord', 'NJORD', 'line_a', 'text');

        $this->assertSame(['note' => false, 'entry' => false], $again);
        $this->assertSame(1, JournalNote::query()->count());
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_a_line_with_no_note_to_go_under_is_not_written(): void
    {
        $this->assertSame(['note' => false, 'entry' => false], $this->journal->write($this->character, 'missing', null, 'a_line', 'text'));
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_the_screen_lists_notes_and_lines_newest_first_and_hides_what_is_done(): void
    {
        $this->journal->write($this->character, 'first', 'First note', 'a', 'Older line');
        $this->journal->write($this->character, 'first', null, 'b', 'Newer line');
        $this->journal->write($this->character, 'second', 'Second note', null, null);
        $this->journal->write($this->character, 'gone', 'Finished note', null, null);

        $this->journal->complete($this->character, 'gone');
        $this->journal->complete($this->character, 'a');

        $html = $this->page();

        $this->assertStringNotContainsString('Finished note', $html, 'a completed note is not shown');
        $this->assertLessThan(strpos($html, 'First note'), strpos($html, 'Second note'), 'notes newest first');
        $this->assertLessThan(strpos($html, 'Older line'), strpos($html, 'Newer line'), 'lines newest first');
        $this->assertStringContainsString('<s>Older line</s>', $html, 'a completed line is struck through');
        $this->assertStringNotContainsString('<s>Newer line</s>', $html);
    }

    public function test_the_two_outcome_kinds_write_and_complete(): void
    {
        $outcomes = app(EncounterOutcomes::class);

        $line = $outcomes->apply($this->character, $this->outcome(OutcomeKind::Journal, 'njord', ['text' => 'NJORD', 'entry_slug' => 'saw', 'entry_text' => 'Saw it.']));
        $this->assertSame('You make a note of it.', $line);
        $this->assertSame(1, JournalEntry::query()->count());

        $outcomes->apply($this->character, $this->outcome(OutcomeKind::JournalDone, 'saw'));
        $this->assertTrue((bool) JournalEntry::query()->where('slug', 'saw')->value('completed'));

        $outcomes->apply($this->character, $this->outcome(OutcomeKind::JournalDone, 'njord'));
        $this->assertTrue((bool) JournalNote::query()->where('slug', 'njord')->value('completed'));
    }

    public function test_the_editor_accepts_a_journal_outcome_and_refuses_an_empty_one(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $encounter = Encounter::create(['slug' => 'ed', 'title' => 'x', 'starts_on' => 'travel', 'weight' => 5, 'once' => false, 'retired' => false]);

        $save = fn (array $outcome) => $this->actingAs($admin)->put(route('admin.encounters.update', $encounter), [
            'title' => 'x', 'starts_on' => 'travel', 'weight' => 5, 'entry' => 0,
            'nodes' => [['key' => 'ed_a', 'body' => 'x', 'ends' => '1', 'outcomes' => [$outcome]]],
        ]);

        $save(['kind' => 'journal', 'ref' => 'njord', 'text' => 'NJORD', 'entry_slug' => 'saw', 'entry_text' => 'Saw it.'])->assertSessionHasNoErrors();
        $stored = $encounter->nodes()->first()->outcomes()->first();
        $this->assertSame('NJORD', $stored->text);
        $this->assertSame('saw', $stored->entry_slug);

        $save(['kind' => 'journal', 'ref' => 'njord'])->assertSessionHasErrors('nodes.0.outcomes.0.text');
        $save(['kind' => 'journal', 'ref' => 'njord', 'entry_text' => 'Saw it.'])->assertSessionHasErrors('nodes.0.outcomes.0.entry_slug');
        $save(['kind' => 'journal_done', 'ref' => 'saw'])->assertSessionHasNoErrors();
    }
}
