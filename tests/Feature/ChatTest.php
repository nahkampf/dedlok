<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Chat\ChatRefused;
use App\Chat\Chats;
use App\Chat\Markup;
use App\Models\Character;
use App\Models\Chat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function chats(): Chats
    {
        return app(Chats::class);
    }

    public function test_markup_escapes_first_and_only_then_draws_markdown(): void
    {
        $this->assertSame('<strong>&lt;b&gt;x&lt;/b&gt;</strong>', Markup::render('**<b>x</b>**'));
        $this->assertStringNotContainsString('<script', Markup::render('<script>alert(1)</script>'));
        $this->assertSame('<em>a</em> <s>b</s>', Markup::render('*a* ~~b~~'));
        $this->assertSame('<ul><li>one</li><li><strong>two</strong></li></ul>', Markup::render("- one\n- **two**"));
        $this->assertStringContainsString('data-copy="1F38E7C7"', Markup::render('go to addr:1f38e7c7 now'));
        $this->assertStringNotContainsString('data-copy', Markup::render('addr:nothex'));
    }

    public function test_html_is_stripped_before_it_is_stored(): void
    {
        $me = Character::factory()->create();
        $line = $this->chats()->post($me, $this->chats()->general(), 'hi <b>there</b><script>x()</script>');

        $this->assertSame('hi there', $line->message);
    }

    public function test_msg_goes_to_one_direct_line_from_either_side(): void
    {
        $a = Character::factory()->create();
        $b = Character::factory()->create();
        $general = $this->chats()->general();

        $first = $this->chats()->post($a, $general, "/msg {$b->handle} hello");
        $second = $this->chats()->post($b, $general, "/msg {$a->handle} hi back");

        $this->assertSame($first->chat_id, $second->chat_id);
        $this->assertSame(1, Chat::query()->where('kind', Chat::DIRECT)->count());
        $this->assertSame('hello', $first->message);
        $this->assertSame(1, $this->chats()->unreadFor($a), 'the reply is unread');
        $this->assertSame(0, $this->chats()->unreadFor($b), 'replying reads what came before');
    }

    public function test_msg_to_nobody_or_yourself_is_refused(): void
    {
        $a = Character::factory()->create();

        foreach (['/msg nobody-here hi', "/msg {$a->handle} hi", '/msg'] as $text) {
            try {
                $this->chats()->post($a, $this->chats()->general(), $text);
                $this->fail("should have refused: {$text}");
            } catch (ChatRefused) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_stranger_gets_404_on_a_private_chat_for_read_and_post(): void
    {
        $owner = Character::factory()->create();
        $stranger = Character::factory()->create();
        $room = $this->chats()->createRoom($owner, 'Back room', false);

        $this->actingAs($stranger->user)->get(route('chat.show', ['chat' => $room->getKey()]))->assertNotFound();
        $this->actingAs($stranger->user)->post(route('chat.say'), ['chat' => $room->getKey(), 'message' => 'let me in'])->assertNotFound();
        $this->actingAs($stranger->user)->post(route('chat.join', ['chat' => $room->getKey()]))->assertNotFound();
        $this->actingAs($owner->user)->get(route('chat.show', ['chat' => $room->getKey()]))->assertOk()->assertSee('Back room');
    }

    public function test_room_names_are_capped_and_a_public_room_can_be_joined(): void
    {
        $owner = Character::factory()->create();
        $other = Character::factory()->create();

        $this->actingAs($owner->user)->post(route('chat.rooms'), ['name' => str_repeat('x', 65)])->assertStatus(422);

        $id = $this->actingAs($owner->user)->post(route('chat.rooms'), ['name' => 'Lounge', 'public' => '1'])->assertOk()->json('chat');
        $this->actingAs($other->user)->post(route('chat.join', ['chat' => $id]))->assertOk();
        $this->assertTrue($this->chats()->canSee($other, Chat::query()->findOrFail($id)));
    }

    public function test_only_a_member_may_invite_and_the_invitee_has_it_unread(): void
    {
        $owner = Character::factory()->create();
        $guest = Character::factory()->create();
        $outsider = Character::factory()->create();
        $room = $this->chats()->createRoom($owner, 'Private', false);

        $this->actingAs($outsider->user)->post(route('chat.invite', ['chat' => $room->getKey()]), ['handle' => $guest->handle])->assertNotFound();

        $this->actingAs($owner->user)->post(route('chat.invite', ['chat' => $room->getKey()]), ['handle' => $guest->handle])->assertOk();
        $this->assertSame(1, $this->chats()->unreadFor($guest));

        // Opening it reads it.
        $this->actingAs($guest->user)->get(route('chat.show', ['chat' => $room->getKey()]))->assertOk();
        $this->assertSame(0, $this->chats()->unreadFor($guest));
    }

    public function test_the_unread_count_rides_on_the_inbox_poll_and_the_panel_draws_general(): void
    {
        $a = Character::factory()->create();
        $b = Character::factory()->create();
        $this->chats()->post($a, $this->chats()->general(), "/msg {$b->handle} psst");

        $this->actingAs($b->user)->getJson(route('notifications.unread'))->assertJsonPath('chat', 1);
        $this->actingAs($b->user)->get(route('chat.show'))->assertOk()->assertSee('General')->assertSee('data-chat-page', false);
        $this->actingAs($b->user)->get(route('chat.poll'))->assertOk()->assertDontSee('<html', false);
    }
}
