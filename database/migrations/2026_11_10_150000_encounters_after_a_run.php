<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The `after_run` door: starts_on may say it. MySQL only; the CHECK is what refuses a value it has not been told about. */
return new class extends Migration
{
    public function up(): void
    {
        $this->doors("'travel','explore','place','grid_step','system','run','after_run','spot'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('encounters')->where('starts_on', 'after_run')->delete();
        }

        $this->doors("'travel','explore','place','grid_step','system','run','spot'");
    }

    private function doors(string $list): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE encounters DROP CHECK chk_encounters_starts_on');
        DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_starts_on CHECK (starts_on IN ({$list}))");
    }
};
