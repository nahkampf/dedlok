<?php

use App\Models\User;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Free Njordy" (achievement 37) is won when the `njord_released` flag is set, which only counts from now on: this hands it
 * to accounts whose character already has the flag. Quietly: no inbox message and no standing, as nobody did anything today.
 * Safe to run twice. No schema changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $users = DB::table('character_flags')
            ->join('characters', 'characters.id', '=', 'character_flags.character_id')
            ->where('character_flags.flag', 'njord_released')
            ->distinct()
            ->pluck('characters.user_id');

        foreach ($users as $userId) {
            DB::table('achievement_progress')->insertOrIgnore(['user_id' => $userId, 'metric' => 'njord_released', 'value' => 1]);
            $won = DB::table('user_achievements')->insertOrIgnore(['user_id' => $userId, 'achievement_id' => 37, 'unlocked_at' => $now]);

            if ($won > 0 && ($user = User::query()->find($userId)) !== null) {
                (new Notifier)->send($user, Category::Achievement, 'achievement_unlocked', [
                    'name' => (string) config('achievements.unlocks.37.name'),
                ], ['link' => ['word' => 'achievement', 'route' => 'achievements.index']]);
            }
        }
    }

    public function down(): void
    {
        // An unlock is a record that something happened; there is nothing to take back.
    }
};
