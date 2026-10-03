<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Implant surgery moves into the clinics. A clinic offers it by having a
 * `clinic_surgeries` row: a surgeon rating the implant's tier is rolled against,
 * the highest tier it will touch, a price per tier each way, and the regions of
 * the body it will operate on. A surgery is a timed City activity, so the
 * activity table learns who is on the table and what is being done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_surgeries', function (Blueprint $table): void {
            $table->comment('A clinic that does implant surgery, and how well. Replaced wholesale by App\\City\\FeatureEditor.');
            $table->foreignId('location_id')->primary()->comment('The clinic.')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rating')->comment('The surgeon\'s skill: the value the install check is rolled with, against a difficulty set by the implant\'s tier (config city.surgery.difficulty).');
            $table->unsignedTinyInteger('max_tier')->comment('The highest implant tier it will touch: 1 or 2.');
            $table->unsignedInteger('install_price')->comment('Creds per tier of the implant, to fit one.');
            $table->unsignedInteger('removal_price')->comment('Creds per tier of the implant, to take one out.');
            $table->timestamp('created_at')->nullable()->comment('Laravel bookkeeping: when the row was created.');
            $table->timestamp('updated_at')->nullable()->comment('Laravel bookkeeping: when the row was last changed.');
        });

        Schema::create('clinic_surgery_regions', function (Blueprint $table): void {
            $table->comment('Which body regions a clinic will operate on. A clinic with surgery and no rows operates on nothing.');
            $table->foreignId('location_id')->comment('The clinic.')->constrained()->cascadeOnDelete();
            $table->string('region', 10)->comment('App\\Implants\\BodyRegion value.');
            $table->primary(['location_id', 'region']);
        });

        Schema::table('city_activities', function (Blueprint $table): void {
            $table->string('operation', 10)->nullable()->after('mode')->comment('Surgery only: App\\City\\SurgeryOperation, install or remove.');
            $table->foreignId('subject_id')->nullable()->after('operation')->comment('Surgery only: the inventory row on the table. Deleting it cancels the operation.')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->after('subject_id')->comment('Surgery only: the clinic it is happening in.')->constrained('locations')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE clinic_surgery_regions ADD CONSTRAINT chk_clinic_surgery_regions_region CHECK (region IN ('head', 'body', 'arms', 'legs', 'spine'))");
            DB::statement('ALTER TABLE clinic_surgeries ADD CONSTRAINT chk_clinic_surgeries_tier CHECK (max_tier IN (1, 2))');
        }

        // The Back-Alley Clinic: a rough hand, nothing near the head or the spine.
        // PLACEHOLDERS, like every figure in the starter city.
        $clinic = DB::table('locations')->where('slug', 'back-alley-clinic')->value('id');

        if ($clinic !== null) {
            $now = now();
            DB::table('clinic_surgeries')->insert(['location_id' => $clinic, 'rating' => 22, 'max_tier' => 1, 'install_price' => 150, 'removal_price' => 60, 'created_at' => $now, 'updated_at' => $now]);

            foreach (['body', 'arms', 'legs'] as $region) {
                DB::table('clinic_surgery_regions')->insert(['location_id' => $clinic, 'region' => $region]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('city_activities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
            $table->dropConstrainedForeignId('subject_id');
            $table->dropColumn('operation');
        });

        Schema::dropIfExists('clinic_surgery_regions');
        Schema::dropIfExists('clinic_surgeries');
    }
};
