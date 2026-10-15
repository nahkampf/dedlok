<?php

declare(strict_types=1);

use App\Cyberspace\SystemType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedBigInteger('owner_character_id')->nullable()->index()
                ->comment('The player who founded this system (a Data Fortress), or NULL for everything an entity owns. A plain id with no foreign key: a fortress outlives a deleted character the way a sighting outlives its system.');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $in = "'".implode("','", SystemType::values())."'";

        foreach (['systems' => 'chk_systems_type', 'system_contacts' => 'chk_contacts_type'] as $table => $constraint) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK (`type` IN ({$in}))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (DB::table('systems')->where('type', 'fortress')->exists() || DB::table('system_contacts')->where('type', 'fortress')->exists()) {
                throw new RuntimeException('There are data fortresses on the grid; remove them before narrowing the type vocabulary.');
            }

            $in = "'".implode("','", array_diff(SystemType::values(), ['fortress']))."'";

            foreach (['systems' => 'chk_systems_type', 'system_contacts' => 'chk_contacts_type'] as $table => $constraint) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK (`type` IN ({$in}))");
            }
        }

        Schema::table('systems', function (Blueprint $table): void {
            $table->dropIndex(['owner_character_id']);
            $table->dropColumn('owner_character_id');
        });
    }
};
