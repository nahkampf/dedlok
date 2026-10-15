<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A clinic can treat a drug family's addiction or tolerance: twelve new `clinic_services.service` values
 * (`drug_<family>_addiction|tolerance`, 26 characters at most, inside the column's 30). Only the CHECK learns them.
 */
return new class extends Migration
{
    private const BASE = ['hitpoints', 'synaptic_integrity', 'neural_damage', 'physical_trauma'];

    private const FAMILIES = ['stimulant', 'opiate', 'sedative', 'psychedelic', 'nootropic', 'medical'];

    public function up(): void
    {
        $this->check(array_merge(self::BASE, ...array_map(
            static fn (string $f): array => ["drug_{$f}_addiction", "drug_{$f}_tolerance"],
            self::FAMILIES,
        )));
    }

    public function down(): void
    {
        DB::table('clinic_services')->where('service', 'like', 'drug\\_%')->delete();
        $this->check(self::BASE);
    }

    /** @param  list<string>  $values */
    private function check(array $values): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $list = "'".implode("', '", $values)."'";

        DB::statement("ALTER TABLE clinic_services MODIFY service VARCHAR(30) NOT NULL COMMENT 'App\\\\City\\\\ClinicService: hitpoints, synaptic_integrity, neural_damage, physical_trauma, or drug_<family>_addiction / drug_<family>_tolerance.'");
        DB::statement('ALTER TABLE clinic_services DROP CHECK chk_clinic_services_service');
        DB::statement("ALTER TABLE clinic_services ADD CONSTRAINT chk_clinic_services_service CHECK (service IN ({$list}))");
    }
};
