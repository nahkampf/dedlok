<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A finished read now files itself; `filed_at` is what stops it being filed twice, since filing no longer spends the read. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->timestamp('filed_at')->nullable()->after('used_at')->comment('When this read was made into a scan file. NULL is not yet. Filing does not spend the read; this is what makes it once.');
        });
    }

    public function down(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->dropColumn('filed_at');
        });
    }
};
