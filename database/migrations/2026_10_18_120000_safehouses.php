<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safehouses: places to wake up, and later to rest in (docs/FATIGUE.md). A type is authored and belongs to ONE district; many
 * characters may hold the same one. Only what the collapse wake-up needs exists yet: no renting, rent, storage or actions.
 * The cardboard box under a bridge is the lowest tier and what everybody falls back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safehouses', function (Blueprint $table): void {
            $table->comment('A kind of home, in one district. Authored content; several characters may hold the same row. Not rentable yet.');

            $table->id()->comment('Primary key.');
            $table->string('slug', 50)->unique()
                ->comment('Stable machine name. Permanent: config/safehouse.php names the fallback by it.');
            $table->string('title', 100)->comment('What the player sees.');
            $table->text('description')->nullable()->comment('Flavour text. NULL is ordinary.');
            $table->foreignId('district_id')
                ->comment('The district it stands in. RESTRICT: a district holding one cannot be deleted from under it.')
                ->constrained()->restrictOnDelete();
            $table->unsignedInteger('rent')->default(0)
                ->comment('Creds per 24 hours. 0 is free. Nothing charges it yet.');
            $table->unsignedSmallInteger('security')->default(0)
                ->comment('How hard it is for other players to break into. Stored; nothing reads it yet.');
            $table->unsignedSmallInteger('stealth')->default(0)
                ->comment('How hard it is for other players to locate someone in it. Stored; nothing reads it yet.');
            $table->decimal('rest_rate', 6, 2)->default(0)
                ->comment('Fatigue removed per minute of resting here, to two decimals. A comfortable home rests you more. Nothing reads it yet.');
            $table->timestamps();
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->foreignId('safehouse_id')->nullable()->after('district_id')
                ->comment('The home a collapse wakes them in. NULL is ordinary and means the fallback (config safehouse.fallback). Set NULL when the safehouse is deleted.')
                ->constrained()->nullOnDelete();
        });

        $district = DB::table('districts')->where('slug', 'knightsbridge-projects')->value('id');

        if ($district !== null) {
            DB::table('safehouses')->insert([
                'slug' => 'cardboard-box',
                'title' => 'Cardboard box under the bridge',
                'description' => 'Flattened boxes, a tarp, and a concrete roof that keeps most of the rain off. Nobody will take it from you. Nobody wants it.',
                'district_id' => $district,
                'rent' => 0,
                'security' => 0,
                'stealth' => 0,
                // A placeholder like every number in this feature, until rest is played.
                'rest_rate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('safehouse_id');
        });

        Schema::dropIfExists('safehouses');
    }
};
