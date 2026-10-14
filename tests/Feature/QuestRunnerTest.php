<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Flags;
use App\Journal\Journal;
use App\Models\Character;
use App\Models\Notification;
use App\Quests\QuestRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QuestRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legal_notice_goes_once_to_each_character_with_the_flag(): void
    {
        $read = Character::factory()->create();
        $other = Character::factory()->create();
        Flags::set($read, QuestRunner::NJORD_READ);

        $this->assertSame(['njord_jsc_notice' => 1], app(QuestRunner::class)->run());
        $this->assertSame(['njord_jsc_notice' => 0], app(QuestRunner::class)->run(), 'never again');

        $message = Notification::query()->where('user_id', $read->user_id)->sole();
        $this->assertSame('Joint Signals Command Legal Department', $message->title);
        $this->assertStringStartsWith('It has come to our attention', $message->body);
        $this->assertStringEndsWith('Have a nice day.', $message->body, 'the whole notice, not a clipped one');
        $this->assertSame(0, Notification::query()->where('user_id', $other->user_id)->count());

        $journal = app(Journal::class);
        $this->assertStringStartsWith('You got a very stern letter', $journal->visible($read)->first()->entries->first()->body);
        $this->assertSame(1, $journal->unseenCount($read->fresh()->forceFill(['tasks_seen_at' => now()->subDay()])));

        $journal->markSeen($read);
        $this->assertSame(0, $journal->unseenCount($read->fresh()));
    }
}
