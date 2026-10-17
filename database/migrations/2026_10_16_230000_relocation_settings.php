<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedSmallInteger('relocate_hours')->nullable()->comment('Hours between the moves of a Data Fortress with a Relocation program running; set from the fortress settings (1, 4, 8, 16, 24 or 48). NULL is cyberspace.fortress.relocate_hours (24).');
            $table->boolean('relocate_tell_owner')->default(true)->comment('Whether the fortress\'s owner is messaged the new position when it relocates.');
            $table->boolean('relocate_tell_admins')->default(true)->comment('Whether members holding the administrator role are messaged the new position when it relocates.');
            $table->boolean('relocate_tell_members')->default(true)->comment('Whether the other members (invitees with access, not administrators) are messaged the new position when it relocates.');
        });
    }

    public function down(): void
    {
        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn(['relocate_hours', 'relocate_tell_owner', 'relocate_tell_admins', 'relocate_tell_members']));
    }
};
