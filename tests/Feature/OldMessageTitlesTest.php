<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Notifications\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The backfill that gives messages sent before titles existed the title they would have had. */
final class OldMessageTitlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_sentences_are_read_back_into_titles_and_the_rest_keep_the_category(): void
    {
        $user = User::factory()->create();
        $make = fn (string $body, ?string $title = null): int => Notification::create(['user_id' => $user->id, 'category' => Category::Cyberspace, 'body' => $body, 'title' => $title])->id;

        $ids = [
            'Siphon report' => $make('Siphons ran: 1,200 ¤'),
            'Scan complete' => $make('Your scan of Silo - Veritas 123 completed. The specifications are saved to a file.'),
            'Scan complete (old wording)' => $make('Your read of Data silo - Mashpriborintorg 383 finished. Everything that machine will give up is in the file.'),
            'Pistols, level 3' => $make('Pistols reached level 3.'),
            'Sold: DUMMY' => $make('Sold: 3 × DUMMY, for 900 ¤.'),
            'Cash from Pi' => $make('Pi sent you 500 ¤.'),
            'Arrived in Old Town' => $make('You arrived in Old Town.'),
        ];
        $unknown = $make('Some sentence nobody wrote a rule for.');
        $kept = $make('Anything at all.', 'A real title');
        $renamed = $make('Siphons ran: 5 ¤', 'Siphons ran');

        (require database_path('migrations/2026_10_06_130000_old_messages_get_titles.php'))->up();

        foreach ($ids as $title => $id) {
            $this->assertSame(str_replace(' (old wording)', '', $title), Notification::find($id)->title, $title);
        }
        $this->assertNull(Notification::find($unknown)->title);
        $this->assertSame('A real title', Notification::find($kept)->title);
        $this->assertSame('Siphon report', Notification::find($renamed)->title);
    }
}
