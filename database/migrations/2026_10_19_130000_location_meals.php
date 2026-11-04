<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A location with meals on a menu is a place to eat (App\Characters\Meal, docs/FATIGUE.md, *Food and fullness*). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_meals', function (Blueprint $table): void {
            $table->comment('A meal a location sells: a name, a price and what it is worth (a Meal). A location is a place to eat by having rows here. Replaced wholesale by App\\City\\FeatureEditor; the only buyer is App\\City\\Kitchen.');
            $table->id()->comment('Primary key.');
            $table->foreignId('location_id')->comment('The place that serves it.')->constrained()->cascadeOnDelete();
            $table->string('name', 100)->comment('What the player sees.');
            $table->unsignedInteger('price')->default(0)->comment('Creds. 0 is free.');
            $table->decimal('meal_fullness', 6, 2)->default(0)->comment('Fullness it adds.');
            $table->decimal('meal_fatigue', 6, 2)->default(0)->comment('Fatigue it removes.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_meals');
    }
};
