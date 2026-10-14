<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Chat\ChatRefused;
use App\Chat\Chats;
use App\Chat\Markup;
use App\Events\ChatLine;
use App\Models\Character;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    public function test_an_invitation_is_a_message_and_membership_comes_only_from_joining(): void
    {
        $owner = Character::factory()->create();
        $guest = Character::factory()->create();
        $outsider = Character::factory()->create();
        $room = $this->chats()->createRoom($owner, 'Private', false);

        $this->actingAs($outsider->user)->post(route('chat.invite', ['chat' => $room->getKey()]), ['handle' => $guest->handle])->assertNotFound();

        $this->actingAs($owner->user)->post(route('chat.say'), ['chat' => $room->getKey(), 'message' => "/invite {$guest->handle}"])->assertOk();
        $this->assertFalse($this->chats()->canSee($guest, $room), 'not a member until they answer');
        $this->assertSame(1, Notification::query()->where('user_id', $guest->user_id)->where('details->chat_invite', $room->getKey())->count());

        $this->actingAs($guest->user)->post(route('chat.accept', ['chat' => $room->getKey()]))->assertRedirect(route('chat.show', ['chat' => $room->getKey()]));
        $this->assertTrue($this->chats()->canSee($guest, $room));
        $this->assertSame(0, Notification::query()->where('user_id', $guest->user_id)->count(), 'the invitation message goes once answered');
        $joined = ChatMessage::query()->where('chat_id', $room->getKey())->where('is_event', true)->first();
        $this->assertSame("{$guest->handle} has joined the chat", $joined->message);

        // Nobody is let in by guessing the join URL.
        $this->actingAs($outsider->user)->post(route('chat.accept', ['chat' => $room->getKey()]))->assertRedirect(route('notifications.index'));
        $this->assertFalse($this->chats()->canSee($outsider, $room));
    }

    public function test_a_private_room_of_two_reads_as_the_other_person(): void
    {
        $a = Character::factory()->create();
        $b = Character::factory()->create();
        $room = $this->chats()->createRoom($a, 'Back room', false);
        $this->chats()->say($a, $room, "/invite {$b->handle}");
        $this->chats()->accept($b, $room->getKey());

        $name = fn (Character $who) => $this->chats()->tabs($who)->firstWhere(fn ($t) => $t['chat']->is($room))['name'];
        $this->assertSame($b->handle, $name($a));
        $this->assertSame($a->handle, $name($b));
    }

    public function test_no_thanks_leaves_it_at_that_and_commands_are_for_rooms(): void
    {
        $owner = Character::factory()->create();
        $guest = Character::factory()->create();
        $room = $this->chats()->createRoom($owner, 'Private', false);

        $this->chats()->say($owner, $room, "/invite {$guest->handle}");
        $this->actingAs($guest->user)->post(route('chat.decline', ['chat' => $room->getKey()]))->assertRedirect();
        $this->assertFalse($this->chats()->canSee($guest, $room));
        $this->assertSame(0, \DB::table('chat_invites')->count());

        $this->expectException(ChatRefused::class);
        $this->chats()->say($owner, $this->chats()->general(), "/invite {$guest->handle}");
    }

    public function test_leaving_removes_you_and_an_empty_room_goes(): void
    {
        $a = Character::factory()->create();
        $room = $this->chats()->createRoom($a, 'Brief', true);

        $result = $this->chats()->say($a, $room, '/leave');

        $this->assertSame($this->chats()->general()->getKey(), $result['chat']);
        $this->assertNull(Chat::query()->find($room->getKey()));

        $this->expectException(ChatRefused::class);
        $this->chats()->say($a, $this->chats()->general(), '/leave');
    }

    public function test_a_line_is_pushed_to_the_right_channels_and_listening_is_private(): void
    {
        Event::fake([ChatLine::class]);
        $a = Character::factory()->create();
        $b = Character::factory()->create();
        $room = $this->chats()->createRoom($a, 'Pair', false);
        $this->chats()->say($a, $room, 'psst');
        $this->chats()->say($a, $this->chats()->general(), 'hello all');

        Event::assertDispatched(ChatLine::class, 2);

        $private = new ChatLine($room, ChatMessage::query()->where('chat_id', $room->getKey())->firstOrFail());
        $this->assertSame(["private-chat-user.{$a->getKey()}"], array_map(fn ($c) => $c->name, $private->broadcastOn()));
        $this->assertStringContainsString('psst', $private->broadcastWith()['html']);

        // Somebody else's own channel is refused; their own is signed. (The null driver the suite runs on signs
        // anything, so this asks the Reverb one.)
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => 'i']);
        \Illuminate\Support\Facades\Broadcast::purge();
        require base_path('routes/channels.php'); // channels live on the driver, and this is a new one
        $this->actingAs($a->user)->post('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => "private-chat-user.{$a->getKey()}"])->assertOk();
        $this->actingAs($b->user)->post('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => "private-chat-user.{$a->getKey()}"])->assertForbidden();
    }

    public function test_leaving_tells_the_room(): void
    {
        $a = Character::factory()->create();
        $b = Character::factory()->create();
        $room = $this->chats()->createRoom($a, 'Pair', true);
        $this->chats()->join($b, $room);

        $this->chats()->say($b, $room, '/leave');

        $line = ChatMessage::query()->where('chat_id', $room->getKey())->where('is_event', true)->latest('id')->first();
        $this->assertSame("{$b->handle} has left the chat.", $line->message);
        $this->assertFalse($this->chats()->canSee($b, $room));
    }

    public function test_ignoring_hides_their_lines_and_their_unread_count(): void
    {
        $a = Character::factory()->create();
        $b = Character::factory()->create();
        $general = $this->chats()->general();

        $this->chats()->say($a, $general, "/ignore {$b->handle}");
        $this->chats()->say($b, $general, 'can you hear me');
        $this->chats()->say($b, $general, "/msg {$a->handle} hello?");

        $this->assertCount(0, $this->chats()->lines($general, $a));
        $this->assertCount(1, $this->chats()->lines($general, $b));
        $this->assertSame(0, $this->chats()->unreadFor($a));

        $this->chats()->say($a, $general, "/unignore {$b->handle}");
        $this->assertCount(1, $this->chats()->lines($general, $a));
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
