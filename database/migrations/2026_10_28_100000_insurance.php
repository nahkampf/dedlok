<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insurance (docs/INSURANCE.md): what a place sells, what a character holds, and the first place that sells it.
 * PLACEHOLDER PRICES for a term, creds.
 */
return new class extends Migration
{
    private const PRICES = [
        'property' => [50 => 400, 70 => 900, 90 => 1800],
        'medical' => [50 => 250, 70 => 600, 90 => 1200],
    ];

    public function up(): void
    {
        Schema::create('insurance_offers', function (Blueprint $table): void {
            $table->comment('A policy a location sells: one row per kind and tier, with the price of a term. A location sells insurance by having rows here (App\\Insurance\\Insurance).');
            $table->id()->comment('Primary key.');
            $table->foreignId('location_id')->comment('The place that sells it.')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->comment('App\\Insurance\\InsuranceKind: property or medical.');
            $table->unsignedTinyInteger('share')->comment('The share of a loss it pays back, in percent.');
            $table->unsignedInteger('price')->comment('Creds for one term.');
            $table->timestamps();
            $table->unique(['location_id', 'kind', 'share']);
        });

        Schema::create('insurance_policies', function (Blueprint $table): void {
            $table->comment('One term of cover a character bought. A renewal is a new row that starts when the last one ends, so the latest ends_at is how far cover reaches. Only App\\Insurance\\Insurance writes it.');
            $table->id()->comment('Primary key.');
            $table->foreignId('character_id')->comment('Who holds it.')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->comment('App\\Insurance\\InsuranceKind: property or medical.');
            $table->unsignedTinyInteger('share')->comment('The share of a loss it pays back, in percent.');
            $table->timestamp('starts_at')->comment('When this term begins: now, or the end of the one it renews.');
            $table->timestamp('ends_at')->comment('When this term ends. Cover is in force from starts_at up to, not including, this.');
            $table->timestamp('reminded_at')->nullable()->comment('When the two-days-left message was sent. Null until it is.');
            $table->timestamp('lapsed_at')->nullable()->comment('When the lapsed message was sent. Null until it is.');
            $table->timestamps();
            $table->index(['character_id', 'kind', 'ends_at']);
        });

        $now = now();

        $district = DB::table('districts')->where('slug', 'eastside')->value('id');

        if ($district === null) {
            return;
        }

        DB::table('locations')->insertOrIgnore([
            'district_id' => $district,
            'slug' => 'vectra_indemnity_corp',
            'name' => 'Vectra Indemnity Corp',
            'description' => "One of the City's oldest and most trustworthy insurance companies, that covers even the risky business that net runners and street fixers deal with. The go-to source for reasonable property damage insurance.",
            'map_x' => 50,
            'map_y' => 60,
            'discovery' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $location = DB::table('locations')->where('slug', 'vectra_indemnity_corp')->value('id');

        foreach (self::PRICES as $kind => $tiers) {
            foreach ($tiers as $share => $price) {
                DB::table('insurance_offers')->insertOrIgnore([
                    'location_id' => $location, 'kind' => $kind, 'share' => $share, 'price' => $price,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policies');
        Schema::dropIfExists('insurance_offers');
        DB::table('locations')->where('slug', 'vectra_indemnity_corp')->delete();
    }
};
