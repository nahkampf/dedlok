<?php

use App\Support\PatchNotes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every account that predates the patch notes a place to read from.
 *
 * NULL now means "show nothing", so an existing player left at NULL would never
 * see a note. Each is placed at the newest release dated BEFORE the day they
 * were last active, so everything from that day on opens for them once.
 *
 * THERE IS NO LAST-ACTIVE RECORD, so it is reconstructed from what only a
 * player causes: starting a run or a program, buying, accepting a mission,
 * queueing training, taking a dose, reading a message. Nothing a nightly job
 * writes counts — `characters.updated_at` moves on its own. No trace at all
 * falls back to when the account was made.
 *
 * Data only; the schema is untouched, and `down()` has nothing to put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Oldest first, so the loop below keeps the last one before the day.
        $notes = array_reverse(PatchNotes::all());

        DB::table('users')->whereNull('last_seen_changelog')->orderBy('id')
            ->each(function (object $user) use ($notes): void {
                $active = substr((string) ($this->lastActive((int) $user->id) ?? $user->created_at ?? now()), 0, 10);

                $seen = '0.0.0';
                foreach ($notes as $note) {
                    if ($note['date'] < $active) {
                        $seen = $note['version'];
                    }
                }

                DB::table('users')->where('id', $user->id)->update(['last_seen_changelog' => $seen]);
            });
    }

    public function down(): void
    {
        // Nothing to undo: a NULL told us nothing we could restore.
    }

    private function lastActive(int $userId): ?string
    {
        $character = DB::table('characters')->where('user_id', $userId)->value('id');

        $moments = [
            DB::table('notifications')->where('user_id', $userId)->max('read_at'),
        ];

        if ($character !== null) {
            $moments[] = DB::table('runs')->where('character_id', $character)->max('started_at');
            $moments[] = DB::table('market_purchases')->where('character_id', $character)->max('purchased_at');
            $moments[] = DB::table('missions')->where('character_id', $character)->max('accepted_at');
            $moments[] = DB::table('training_queue')->where('character_id', $character)->max('created_at');
            $moments[] = DB::table('character_doses')->where('character_id', $character)->max('started_at');
            $moments[] = DB::table('inventory_items')->where('character_id', $character)->max('started_at');
        }

        $moments = array_filter($moments);

        return $moments === [] ? null : (string) max($moments);
    }
};
