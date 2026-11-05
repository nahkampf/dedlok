<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->string('run_outcome', 8)->nullable()->after('run_purpose')
                ->comment('For the after_run door: only a run that ended in success (RunEnding::Completed) or failure (any other). NULL is both.');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_run_outcome CHECK (run_outcome IS NULL OR run_outcome IN ('success','failure'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE encounters DROP CHECK chk_encounters_run_outcome');
        }

        Schema::table('encounters', fn (Blueprint $t) => $t->dropColumn('run_outcome'));
    }
};
