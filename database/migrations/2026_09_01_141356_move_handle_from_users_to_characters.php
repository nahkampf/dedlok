<?php

use App\Rules\ValidHandle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The handle moves to characters.handle and users.name goes away.
 *
 * users.name held the handle but was never unique, while characters.handle is.
 * Keeping both invited them to disagree. This backfills a character for every
 * user that lacks one, carrying their name across, before dropping the column —
 * otherwise the drop would destroy the only copy of each player's handle.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $taken = DB::table('characters')->pluck('handle')->all();

            $orphans = DB::table('users')
                ->whereNotIn('id', DB::table('characters')->select('user_id'))
                ->get(['id', 'name']);

            foreach ($orphans as $user) {
                $handle = $this->uniqueHandle((string) $user->name, $user->id, $taken);
                $taken[] = $handle;

                DB::table('characters')->insert([
                    'user_id' => $user->id,
                    'handle' => $handle,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });

        // Copy the handles back so a rollback is not silently lossy.
        foreach (DB::table('characters')->get(['user_id', 'handle']) as $character) {
            DB::table('users')->where('id', $character->user_id)->update(['name' => $character->handle]);
        }
    }

    /**
     * Derive a handle that satisfies the character rules and is not yet taken.
     *
     * A legacy name may be too long, contain characters the rule rejects, or
     * collide with another user's — users.name was never unique. Falling back
     * to the user id guarantees the migration cannot fail on data it inherits.
     *
     * @param  list<string>  $taken
     */
    private function uniqueHandle(string $name, int $userId, array $taken): string
    {
        $candidate = ValidHandle::normalize(trim($name));

        if ($candidate === '' || ! ValidHandle::passes($candidate)) {
            $candidate = 'player'.$userId;
        }

        if (! in_array($candidate, $taken, true)) {
            return $candidate;
        }

        $suffix = 2;
        do {
            $trimmed = mb_substr($candidate, 0, ValidHandle::MAX_LENGTH - mb_strlen((string) $suffix));
            $next = $trimmed.$suffix;
            $suffix++;
        } while (in_array($next, $taken, true));

        return $next;
    }
};
