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
            $table->string('access', 10)->default('private')->comment('Who a Data Fortress admits to its services: private, invited or public. Only meaningful for fortresses.');
        });

        Schema::create('fortress_members', function (Blueprint $table): void {
            $table->comment('A player the owner has invited to a Data Fortress, and the role they hold there.');
            $table->id()->comment('Primary key.');
            $table->unsignedBigInteger('system_id')->index()->comment('The fortress (a plain systems.id).');
            $table->foreignId('character_id')
                ->comment('The invited player.')
                ->constrained('characters')->cascadeOnDelete();
            $table->string('role', 20)->default('member')->comment('member or administrator; the permissions each carries are config (cyberspace.fortress.roles).');
            $table->timestamps();

            $table->unique(['system_id', 'character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fortress_members');
        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn('access'));
    }
};
