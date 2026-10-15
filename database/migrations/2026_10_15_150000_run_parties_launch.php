<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_parties', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->comment('When the owner started the run. NULL while still forming at the door.');
        });

        Schema::table('run_party_members', function (Blueprint $table): void {
            $table->json('loadout')->nullable()->comment('Inventory row ids of the run-only software this member goes in with, chosen when they accept. NULL is none.');
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('run_party_id')->nullable()->index()->comment('The party this run belongs to, or NULL for a solo run. Plain id, no foreign key: a run remembers what happened after the party row is gone.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropIndex(['run_party_id']);
            $table->dropColumn('run_party_id');
        });
        Schema::table('run_party_members', fn (Blueprint $t) => $t->dropColumn('loadout'));
        Schema::table('run_parties', fn (Blueprint $t) => $t->dropColumn('started_at'));
    }
};
