<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CODE CRAFTING (CRAFTING.md): a Design is an item with a recipe, and a craft is one build in progress. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designs', function (Blueprint $table): void {
            $table->comment('A recipe. An item is a design because one of these points at it, as it is a program because a programs row does.');
            $table->id();
            $table->foreignId('item_id')->comment('The design item a player holds: a Design Copy or a Design Original.')
                ->unique()->constrained('items')->cascadeOnDelete();
            $table->foreignId('product_item_id')->comment('What a craft from it makes: always a program. Deleting the product deletes the recipe.')
                ->constrained('items')->cascadeOnDelete();
            $table->unsignedInteger('craft_seconds')->comment('How long a build takes before any skill shortens it (a base the crafting_seconds.<kind> stat resolves).');
            $table->boolean('single_use')->default(false)->comment('A Design Copy: the design item is spent when a craft starts. False is a Design Original, which is kept.');
            $table->foreignId('skill_id')->nullable()->comment('A skill this design needs on top of the general and per-kind ones. NULL needs no more.')
                ->constrained('skills')->nullOnDelete();
            $table->unsignedTinyInteger('skill_level')->default(1)->comment('The level of skill_id it needs; unread while skill_id is NULL.');
            $table->timestamps();
        });

        Schema::create('design_components', function (Blueprint $table): void {
            $table->comment('One line of a recipe: this many of a component, optionally of a particular origin.');
            $table->id();
            $table->foreignId('design_id')->comment('The recipe.')->constrained('designs')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->comment('Where the line sits in the recipe, from 1.');
            $table->foreignId('component_item_id')->comment('The component item wanted. Restricted: a recipe must not lose a line to an item deleted from under it.')
                ->constrained('items')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->comment('How many, before crafting_cost scales it. At least one.');
            $table->unsignedTinyInteger('grade_min')->nullable()->comment('Only files taken off a system of at least this grade. NULL is any, files with no recorded grade included.');
            $table->unsignedTinyInteger('grade_max')->nullable()->comment('Only files taken off a system of at most this grade. NULL is any.');
            $table->string('os_slug', 100)->nullable()->comment('Only files taken off a system running this operating system (a skill slug, copied on the file). NULL is any.');
            $table->unsignedBigInteger('owner_id')->nullable()->comment('Only files taken off a system this entity owned. A plain id, no foreign key: the file remembers it. NULL is any.');
            $table->string('system_type', 20)->nullable()->comment('Only files taken off this type of system (a SystemType value). NULL is any.');
            $table->unsignedTinyInteger('ice_min')->nullable()->comment('Only files off a system whose ICE level was at least this. NULL is any.');
            $table->unsignedTinyInteger('ids_min')->nullable()->comment('Only files off a system whose IDS level was at least this. NULL is any.');
            $table->unique(['design_id', 'position']);
        });

        Schema::create('crafts', function (Blueprint $table): void {
            $table->comment('One build in progress. A character may have as many as their crafting_slots; components were spent when it was created.');
            $table->id();
            $table->foreignId('character_id')->comment('Whose build.')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_item_id')->comment('What it will make. A plain id, snapshotted: the design may be sold or destroyed after the build starts.');
            $table->string('name', 200)->comment('The product\'s name, snapshotted for the screen and the message.');
            $table->unsignedInteger('required_seconds')->comment('How much working time it needs, resolved with the character\'s skills when it started.');
            $table->unsignedInteger('progress_seconds')->default(0)->comment('Working time so far. Only advances while the equipped deck is ready.');
            $table->timestamp('settled_at')->comment('The moment progress was last brought up to date.');
            $table->timestamp('finished_at')->nullable()->comment('When progress reached required_seconds. A finished build whose product does not fit keeps its slot until it does.');
            $table->timestamps();
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crafts');
        Schema::dropIfExists('design_components');
        Schema::dropIfExists('designs');
    }
};
