<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notification centre: what happened while nobody was looking.
 *
 * THAT IS THE WHOLE TEST FOR WHETHER SOMETHING BELONGS HERE. A flash message
 * already covers everything a player did — they pressed the button, they are
 * looking at the answer. What has had nowhere to go until now is the other
 * half: a scan that finished an hour after they walked away, a backdoor a
 * nightly roll took off them, a skill that crossed a level while the tab was
 * shut. Three separate entries in TODO.md stood on this table not existing.
 *
 * IT BELONGS TO THE ACCOUNT, like achievements and for the same reason — the
 * rail it hangs in is the account's chrome, and `characters.user_id` is a
 * column every sender already holds, so a character-side sender costs no extra
 * query to address one.
 *
 * THE BODY IS RENDERED WHEN IT IS WRITTEN, not composed when it is read. A
 * notification is about a moment, and the things it names — a machine, a
 * skill, a level — are allowed to move, be renamed or stop existing
 * afterwards. Storing the sentence is what makes it still true tomorrow, which
 * is the same call `system_contacts` makes about a sighting. The game speaks
 * English only, so there is nothing a stored sentence costs.
 *
 * WHAT IS NOT HERE IS A SEVERITY. The category says where a message came from
 * and nothing about how loud it is: a notification is never an alert, because
 * anything that needs acting on THIS INSTANT belongs on the screen where the
 * acting happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->comment('One message per thing that happened to an account while nobody was looking. Ours, not Laravel\'s database notification channel.');
            $table->id()->comment('Primary key.');
            $table->foreignId('user_id')
                ->comment('Whose inbox. The account rather than the character, like achievements.')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('category', 20)->comment('An App\Notifications\Category case. Says where the message came from, never how urgent it is.');
            $table->string('body', 500)->comment('The sentence, rendered from config/strings.php at the moment it was written. Never recomposed: what it names is allowed to move or stop existing.');
            $table->timestamp('read_at')->nullable()->comment('When the player marked it read. NULL is unread, which is what the rail counts.');
            // Declared rather than `timestamps()`, which returns void and so
            // cannot be commented inline — and the standing rule is that the
            // comment lives in the migration that creates the column.
            $table->timestamp('created_at')->nullable()->comment('When it happened. The inbox is ordered by id rather than this: several messages can land in the same second.');
            $table->timestamp('updated_at')->nullable()->comment('Only ever moves when the message is marked read.');

            // The two questions asked of this table: the badge counts unread
            // ones, and the screen lists the newest first. Both are per
            // account, so both start at the same column.
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
