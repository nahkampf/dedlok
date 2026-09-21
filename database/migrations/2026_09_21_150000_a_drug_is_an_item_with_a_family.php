<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drugs: what they are, what taking one does to you, and what is in you now.
 *
 * ## A drug is an item with a `drugs` row
 *
 * The same shape software and deck hardware have. Nothing on `items` says an
 * item is a drug — the row is what says so — and its EFFECTS stay on
 * `items.modifiers` like an implant's, because a thing with a row carries its
 * modifiers on that row.
 *
 * ## The books are per FAMILY and per character, never per drug
 *
 * A character can be deep into opiates and clean of stimulants at the same
 * time, so `character_drug_use` is keyed on the pair. Two running totals rather
 * than an effect with a duration, which is why `character_modifiers` is the
 * wrong shape for them and this is its own table.
 *
 * ## A dose in effect is a row of its own, and that is the interesting one
 *
 * `character_doses` exists because `character_modifiers` cannot express it. A
 * modifier row names a definition in `config/effects.php` and takes ONE amount
 * from its data; a drug's effects are a LIST that lives on the item. So a dose
 * points at the item and a sixth modifier source reads the list off it — the
 * same way installed implants and running programs already work.
 *
 * POTENCY IS FROZEN WHEN THE DOSE IS TAKEN. Tolerance is a property of the
 * dose rather than of the person: what you swallowed is what you swallowed,
 * and a band that climbs an hour later does not reach back into your blood.
 * That is what lets this whole mechanic exist without teaching the resolver
 * that one modifier can scale another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drugs', function (Blueprint $table): void {
            $table->comment('The half of a drug that is not on `items`. An item with one of these rows is a drug; its effects stay on `items.modifiers`, like an implant\'s.');
            $table->id()->comment('Primary key.');
            $table->foreignId('item_id')
                ->comment('The catalogue row this is the drug half of.')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('family', 20)->comment('An App\Drugs\Family case. The books are kept per family, so this is what decides which figures a dose moves and which withdrawal it answers.');
            $table->unsignedInteger('duration_minutes')->comment('How long one dose is in effect. The dose row\'s expiry is worked out from this at the moment it is taken.');
            $table->decimal('addictive', 5, 2)->default(0)->comment('How far one dose drives the addiction figure up. Zero is a drug nobody gets hooked on, which is ordinary.');
            $table->decimal('tolerance_gain', 5, 2)->default(0)->comment('How far one dose drives the tolerance figure up. Zero is a drug that never stops working.');
            $table->timestamps();
        });

        Schema::create('character_drug_use', function (Blueprint $table): void {
            $table->comment('The books, one row per character per family. Two running totals rather than an effect with a duration, which is why these are not character_modifiers.');
            $table->id()->comment('Primary key.');
            $table->foreignId('character_id')
                ->comment('Whose books these are.')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('family', 20)->comment('An App\Drugs\Family case. Kept per family because somebody can be deep into one and clean of another.');
            $table->decimal('addiction', 6, 2)->default(0)->comment('How badly they need it. Above the configured threshold it is withdrawal, written as a character_modifiers row; taking one of the right family clears it.');
            $table->decimal('tolerance', 6, 2)->default(0)->comment('How little it does for them any more. Reads as a band that cuts what a NEW dose is worth, and buys a wider safe capacity at the same time.');
            $table->timestamps();

            $table->unique(['character_id', 'family']);
        });

        Schema::create('character_doses', function (Blueprint $table): void {
            $table->comment('What is in somebody right now. One row per dose taken, read by App\Stats\Sources\ActiveDoses; two doses of one drug are two rows and they add up by construction.');
            $table->id()->comment('Primary key.');
            $table->foreignId('character_id')
                ->comment('Who took it.')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('item_id')
                ->comment('Which drug. The effects are read off `items.modifiers` rather than copied here, so a retuned drug retunes what is already in somebody.')
                ->constrained()
                ->cascadeOnDelete();
            $table->decimal('potency', 4, 2)->default(1)->comment('What tolerance left of it, frozen at the moment it was taken: 1.00 is full strength and 0.70 is three bands in. Only the POSITIVE amounts are scaled by it — tolerance never softens what a drug does to you.');
            $table->timestamp('started_at')->comment('When it was taken. Kept so a resolution as of a past moment can refuse to credit a dose taken after it.');
            $table->timestamp('expires_at')->comment('When it stops. Filtered on read rather than swept: an expired dose is simply not counted.');

            $table->index(['character_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_doses');
        Schema::dropIfExists('character_drug_use');
        Schema::dropIfExists('drugs');
    }
};
