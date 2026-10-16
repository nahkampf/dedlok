<?php

declare(strict_types=1);

use App\Missions\MissionAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Data transport: the Sealed data container item, the heat a job added, and the action CHECK widened (MySQL only). */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (! DB::table('items')->where('slug', 'sealed-data-container')->exists()) {
            DB::table('items')->insert([
                'slug' => 'sealed-data-container',
                'name' => 'Sealed data container',
                'flavor' => 'Somebody else\'s secrets in a box you cannot open. Do not lose it.',
                'container' => 'data',
                'tier' => 1,
                'rarity' => 'common',
                'base_cost' => 0,
                'on_market' => false,
                'seed_quantity' => 0,
                'stackable' => false,
                'size' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('missions', function (Blueprint $table): void {
            $table->decimal('heat_added', 5, 1)->default(0)->after('proceeds')
                ->comment('Transport only: the heat this job raised on acceptance, taken off again when it ends. Zero for every other kind.');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $actions = "'".implode("','", MissionAction::values())."'";

        DB::statement('ALTER TABLE `missions` DROP CHECK `chk_missions_action`');
        DB::statement("ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_action` CHECK (`action` IN ({$actions}))");
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table): void {
            $table->dropColumn('heat_added');
        });

        DB::table('items')->where('slug', 'sealed-data-container')->delete();
    }
};
