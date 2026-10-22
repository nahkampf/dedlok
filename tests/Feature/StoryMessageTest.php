<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\EncounterOutcomes;
use App\City\OutcomeBranch;
use App\City\OutcomeKind;
use App\Models\Character;
use App\Models\Encounter;
use App\Models\EncounterNode;
use App\Models\EncounterOutcome;
use App\Models\Notification;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** An encounter's `message` outcome: straight to the inbox, or held back for a number of seconds. */
final class StoryMessageTest extends TestCase
{
    use RefreshDatabase;

    private function send(Character $who, int $delay): void
    {
        $encounter = Encounter::create(['slug' => 'story', 'title' => 'x', 'starts_on' => 'travel', 'weight' => 1, 'once' => false, 'retired' => false]);
        $node = EncounterNode::create(['encounter_id' => $encounter->id, 'node_key' => 'n', 'body' => 'x', 'entry' => true, 'ends' => true]);
        $outcome = EncounterOutcome::create([
            'node_id' => $node->id, 'branch' => OutcomeBranch::Always, 'position' => 0, 'kind' => OutcomeKind::Message,
            'ref' => 'NJORD', 'text' => 'PLS HELP', 'entry_text' => 'We are trapped.', 'amount' => $delay,
        ]);

        app(EncounterOutcomes::class)->apply($who, $outcome);
    }

    public function test_a_message_with_no_delay_is_in_the_inbox_at_once(): void
    {
        $who = Character::factory()->create();
        $this->send($who, 0);

        $note = Notification::query()->where('user_id', $who->user_id)->firstOrFail();
        $this->assertSame(Category::Story, $note->category);
        $this->assertSame('PLS HELP', $note->title);
        $this->assertSame('We are trapped.', $note->body);
        $this->assertSame(['from' => 'NJORD'], $note->details);
    }

    public function test_a_delayed_message_waits_until_its_time(): void
    {
        $who = Character::factory()->create();
        $this->send($who, 3600);

        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(0, app(Notifier::class)->deliverDue());

        $this->travel(3601)->seconds();

        $this->assertSame(1, app(Notifier::class)->deliverDue());
        $this->assertSame(1, Notification::query()->where('user_id', $who->user_id)->count());
        $this->assertSame(0, app(Notifier::class)->deliverDue());
    }
}
