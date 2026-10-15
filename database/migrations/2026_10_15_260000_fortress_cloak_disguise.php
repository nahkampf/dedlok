<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedTinyInteger('cloak_grade')->nullable()->comment('The grade a cloaked Data Fortress presents as to anyone without access. NULL until the owner sets a disguise.');
            $table->string('cloak_type', 10)->nullable()->comment('The type (silo, relay or compute) a cloaked Data Fortress presents as.');
            $table->unsignedBigInteger('cloak_owner_id')->nullable()->comment('The entity (a plain entities.id) a cloaked Data Fortress presents as owned by.');
        });
    }

    public function down(): void
    {
        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn(['cloak_grade', 'cloak_type', 'cloak_owner_id']));
    }
};
