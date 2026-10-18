<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safehouse_leads', function (Blueprint $table): void {
            $table->comment('A safehouse a character has sensed while exploring and is working out. Written only by App\\Safehouses\\Leads. Gone when the holder stops holding that home.');
            $table->id()->comment('Surrogate key.');
            $table->foreignId('character_id')->comment('Whose lead: the one who sensed the place.')->constrained()->cascadeOnDelete();
            $table->foreignId('target_id')->comment('The character who holds the home. Unknown to the finder until discovery passes the safehouse stealth.')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('safehouse_id')->comment('Which kind of home it is. A lead is stale, and deleted, when the target no longer holds it.')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number')->comment('"Suspicious place <number>", counted per finder and district.');
            $table->decimal('discovery', 8, 2)->default(0)->comment('How much the finder has worked out. Above the safehouse stealth, the owner is known.');
            $table->decimal('security', 8, 2)->default(0)->comment('How much the finder has scoped out. Above the safehouse security, breaking in has no penalty.');
            $table->boolean('inside')->default(false)->comment('The finder has broken in and is standing inside. Cleared by leaving, or by leaving the district.');
            $table->timestamps();

            $table->unique(['character_id', 'target_id']);
        });

        Schema::table('city_activities', function (Blueprint $table): void {
            $table->foreignId('lead_id')->nullable()->after('location_id')->comment('Stakeout and scouting out only: the lead being worked. Deleting it ends the activity.')->constrained('safehouse_leads')->cascadeOnDelete();
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('raid_protected_until');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->timestamp('raid_protected_until')->nullable()->after('rent_due_at')->comment('Unused; see safehouse_leads.');
        });

        Schema::table('city_activities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lead_id');
        });

        Schema::dropIfExists('safehouse_leads');
    }
};
