<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first clinic. PLACEHOLDER PRICES, creds per point. Only the clinic is
 * seeded: a shop sells items, items are authored in the live admin, and a row
 * naming one here would point at something a fresh clone does not have.
 */
return new class extends Migration
{
    private const PRICES = ['hitpoints' => 4, 'synaptic_integrity' => 6, 'neural_damage' => 80, 'physical_trauma' => 80];

    public function up(): void
    {
        $location = DB::table('locations')->where('slug', 'back-alley-clinic')->value('id');

        if ($location === null) {
            return;
        }

        $now = now();

        foreach (self::PRICES as $service => $price) {
            DB::table('clinic_services')->insertOrIgnore([
                'location_id' => $location, 'service' => $service, 'price' => $price,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('clinic_services')->whereIn('location_id', DB::table('locations')->where('slug', 'back-alley-clinic')->select('id'))->delete();
    }
};
