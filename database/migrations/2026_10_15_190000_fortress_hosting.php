<?php

declare(strict_types=1);

use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('fortress_system_id')->nullable()->index()
                ->comment('The Data Fortress this program is installed on, or NULL. Installed is another way of being elsewhere: out of the stash, never stacked, not decaying anywhere else. A plain systems.id.');
            $table->boolean('fortress_running')->default(false)->comment('Whether an installed program is running (drawing compute and RAM on the fortress). Meaningless unless fortress_system_id is set.');
            $table->boolean('fortress_halted')->default(false)->comment('Stopped by the fortress itself for want of resources, and so restarted by itself when they return (Kernel, ICE, IDS only). A stop by hand clears it.');
        });

        Schema::table('systems', function (Blueprint $table): void {
            $table->timestamp('abandoned_at')->nullable()->comment('When a Data Fortress went offline (its Kernel could no longer run on the Leechers\' totals). NULL is online. Past the grace period it is deleted.');
        });

        $this->kinds([...ProgramKind::values(), 'locator', 'pathfinder']);
    }

    public function down(): void
    {
        $this->kinds(array_values(array_diff([...ProgramKind::values(), 'locator', 'pathfinder'], ['kernel', 'fortress_ids', 'fortress_ice', 'utility', 'producer'])));

        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn('abandoned_at'));
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropIndex(['fortress_system_id']);
            $table->dropColumn(['fortress_system_id', 'fortress_running', 'fortress_halted']);
        });
    }

    /** @param  list<string>  $kinds */
    private function kinds(array $kinds): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `programs` DROP CHECK `chk_programs_kind`');
        DB::statement('ALTER TABLE `programs` ADD CONSTRAINT `chk_programs_kind` CHECK (`kind` IN ('.implode(',', array_map(static fn (string $k): string => "'{$k}'", $kinds)).'))');
    }
};
