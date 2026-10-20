<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Notification;
use App\Notifications\Category;
use App\Support\PlainText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Writing to another player: the inbox's "New message", and what a message is allowed to contain. */
final class PlayerMessageTest extends TestCase
{
    use RefreshDatabase;

    private Character $me;

    private Character $them;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = Character::factory()->create(['handle' => 'Sender_One', 'archetype' => 3]);
        $this->them = Character::factory()->create(['handle' => 'Recipient_Two', 'archetype' => 3]);
    }

    private function send(array $data)
    {
        return $this->actingAs($this->me->user)->post(route('messages.store'), $data);
    }

    public function test_the_inbox_offers_new_message_and_the_screen_has_the_four_things(): void
    {
        $this->actingAs($this->me->user)->get(route('notifications.index'))->assertOk()->assertSee('New message')->assertSee(route('messages.create'), false);

        $this->actingAs($this->me->user)->get(route('messages.create'))->assertOk()
            ->assertSee('name="to"', false)->assertSee('name="title"', false)->assertSee('name="message"', false)
            ->assertSee('Send')->assertSee('data-suggest', false)->assertSee('<datalist id="recipients">', false);
    }

    public function test_a_message_arrives_in_the_recipients_inbox_from_the_sender(): void
    {
        $this->send(['to' => 'Recipient_Two', 'title' => 'Hello', 'message' => "Line one.\nLine two."])
            ->assertRedirect(route('notifications.index'))->assertSessionHas('status');

        $note = Notification::query()->where('user_id', $this->them->user_id)->sole();
        $this->assertSame(Category::Personal, $note->category);
        $this->assertSame('Hello', $note->title);
        $this->assertSame("Line one.\nLine two.", $note->body);
        $this->assertSame(['from' => 'Sender_One'], $note->details);
        $this->assertNull($note->read_at);

        $page = $this->actingAs($this->them->user)->get(route('notifications.index'))->assertOk()->assertSee('From: Sender_One')->getContent();
        $this->assertStringContainsString(route('messages.create', ['to' => 'Sender_One']), html_entity_decode($page));
    }

    public function test_markup_and_script_are_stripped_from_every_field_before_it_is_stored(): void
    {
        $this->send([
            'to' => 'Recipient_Two',
            'title' => '<b>Hi</b> <script>alert(1)</script>there',
            'message' => "<img src=x onerror=alert(1)>Be careful &lt;script&gt;alert(2)&lt;/script&gt;\n<a href=\"javascript:alert(3)\">click</a> I <3 you\u{202E}",
        ])->assertRedirect();

        $note = Notification::query()->sole();
        $this->assertSame('Hi there', $note->title);
        $this->assertSame("Be careful \nclick I <3 you", $note->body);
        $this->assertStringNotContainsString('<script', $note->body);
        $this->assertStringNotContainsString('<img', $note->body);
        $this->assertStringNotContainsString('<a ', $note->body);
        $this->assertStringNotContainsString('alert(2)', $note->body, 'a script smuggled in as entities is removed too');
        $this->assertStringNotContainsString("\u{202E}", $note->body, 'no bidi override');
        $this->assertStringContainsString('I <3 you', $note->body, 'a heart is text');
    }

    public function test_the_reader_prints_it_escaped_even_if_something_got_through(): void
    {
        Notification::create([
            'user_id' => $this->them->user_id, 'category' => Category::Personal, 'title' => '<i>t</i>', 'body' => '<script>alert(1)</script>',
            'details' => ['from' => '<b>x</b>'],
        ]);

        $html = $this->actingAs($this->them->user)->get(route('notifications.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_it_is_refused_when_empty_after_cleaning_or_when_the_recipient_is_wrong(): void
    {
        $this->send(['to' => 'Recipient_Two', 'title' => '<b></b>', 'message' => 'hi'])->assertSessionHasErrors('title');
        $this->send(['to' => 'Recipient_Two', 'title' => 'T', 'message' => '<script>x</script>'])->assertSessionHasErrors('message');
        $this->send(['to' => 'Nobody_Here', 'title' => 'T', 'message' => 'hi'])->assertSessionHasErrors('to');
        $this->send(['to' => 'Sender_One', 'title' => 'T', 'message' => 'hi'])->assertSessionHasErrors('to');
        $this->send(['to' => 'Recipient_Two', 'title' => str_repeat('a', 101), 'message' => 'hi'])->assertSessionHasErrors('title');
        $this->send(['to' => 'Recipient_Two', 'title' => 'T', 'message' => str_repeat('a', 501)])->assertSessionHasErrors('message');

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_a_placeholder_in_somebodys_words_is_not_filled_in(): void
    {
        $this->send(['to' => 'Recipient_Two', 'title' => 'About :handle', 'message' => 'Use :place or :handle'])->assertRedirect();

        $note = Notification::query()->sole();
        $this->assertSame('About :handle', $note->title);
        $this->assertSame('Use :place or :handle', $note->body);
    }

    public function test_the_suggestions_are_handles_only_and_not_your_own(): void
    {
        Character::factory()->create(['handle' => 'Recipient_Three', 'archetype' => 3]);

        $names = $this->actingAs($this->me->user)->getJson(route('messages.recipients', ['q' => 'recip']))->assertOk()->json();
        $this->assertSame(['Recipient_Three', 'Recipient_Two'], $names);

        $this->assertSame([], $this->actingAs($this->me->user)->getJson(route('messages.recipients', ['q' => 'Sender']))->json(), 'never your own');
        $this->assertSame([], $this->actingAs($this->me->user)->getJson(route('messages.recipients', ['q' => '']))->json());
        // A wildcard typed in is text, not a pattern.
        $this->assertSame([], $this->actingAs($this->me->user)->getJson(route('messages.recipients', ['q' => '%']))->json());
    }

    public function test_it_is_for_signed_in_players_and_throttled(): void
    {
        $this->post(route('messages.store'), ['to' => 'x', 'title' => 'x', 'message' => 'x'])->assertRedirect(route('login'));

        foreach (range(1, 15) as $i) {
            $this->send(['to' => 'Recipient_Two', 'title' => "T{$i}", 'message' => 'hi'])->assertRedirect();
        }
        $this->send(['to' => 'Recipient_Two', 'title' => 'One too many', 'message' => 'hi'])->assertStatus(429);
    }

    public function test_the_cleaner_on_its_own(): void
    {
        $this->assertSame('', PlainText::clean('<script>evil'));
        $this->assertSame('ipt>alert(1)ipt>', PlainText::clean('<scr<b>ipt>alert(1)</scr</b>ipt>'));
        $this->assertSame('a b', PlainText::clean("a \n\t  b"));
        $this->assertSame("a\n\nb", PlainText::clean("a\n\n\n\n\nb", multiline: true));
        $this->assertSame('', PlainText::clean(null));
    }

    public function test_a_reply_is_titled_re_and_quotes_the_message_below(): void
    {
        $this->send(['to' => 'Recipient_Two', 'title' => 'Hello', 'message' => "line one\nline two"])->assertRedirect();
        $message = Notification::query()->where('user_id', $this->them->user_id)->firstOrFail();

        $page = $this->actingAs($this->them->user)->get(route('messages.create', ['to' => 'Sender_One', 're' => $message->id]))->assertOk();
        $page->assertSee('value="Re: Hello"', false);
        $page->assertSee("\n\n&gt; line one\n&gt; line two", false);

        // Replying to a reply does not stack prefixes, and somebody else's message is not quoted.
        $message->update(['title' => 'Re: Hello']);
        $this->actingAs($this->them->user)->get(route('messages.create', ['re' => $message->id]))->assertSee('value="Re: Hello"', false);
        $this->actingAs($this->me->user)->get(route('messages.create', ['re' => $message->id]))->assertDontSee('line one');
    }
}
