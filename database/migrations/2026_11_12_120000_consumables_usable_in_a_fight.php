<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A consumable may be allowed in a fight: usable from the belt as a round's action, by a skill check. Off for everything that exists. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumables', function (Blueprint $table): void {
            $table->boolean('usable_in_fight')->default(false)->after('effects')
                ->comment('Whether it may be used from the belt inside a fight, as that round\'s action and by a defence-against-pursuit check. Off: only usable from the stash, outside one. Written by App\\Items\\ConsumableEditor.');
        });
    }

    public function down(): void
    {
        Schema::table('consumables', function (Blueprint $table): void {
            $table->dropColumn('usable_in_fight');
        });
    }
};
