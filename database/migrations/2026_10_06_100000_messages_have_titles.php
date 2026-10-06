<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An inbox row shows a title and opens to the full message, so a message needs one.
 *
 * NULLABLE, and nothing is backfilled: a message sent before this has no title and the screen
 * falls back to the label of its category. A stored sentence is never recomposed, and neither is
 * a title (the notification rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('title', 120)->nullable()->after('category')
                ->comment('The short line the inbox shows while the message is closed, composed when it was sent and never recomposed. NULL for a message from before titles existed: the screen shows its category instead.');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('title');
        });
    }
};
