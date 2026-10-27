<?php

declare(strict_types=1);

use App\Support\Text;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('priority', 10)->default('standard')->after('category')
                ->comment('An App\Notifications\Priority case: standard or important. Decides which inbox box it is in and the colour of the rail badge.');
        });

        // Messages already sent: what a player wrote, a story and the grants are Important by category, the rest by the title
        // their key would have had (a placeholder in a title matches anything).
        $titles = collect((array) config('notifications.important'))
            ->map(fn (string $key): string => Text::get("notifications.titles.{$key}"))
            ->reject(fn (string $title): bool => str_starts_with($title, 'notifications.'))
            ->map(fn (string $title): string => (string) preg_replace('/:\w+/', '%', $title));

        DB::table('notifications')
            ->where(function ($q) use ($titles): void {
                $q->whereIn('category', ['personal', 'story', 'support', 'wallet']);

                foreach ($titles as $pattern) {
                    $q->orWhere('title', 'like', $pattern);
                }
            })
            ->update(['priority' => 'important']);
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }
};
