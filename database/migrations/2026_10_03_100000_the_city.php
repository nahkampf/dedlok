<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The physical half of the game: districts, the links between them, the
 * locations in them, and what a character has found. See CITY.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table): void {
            $table->comment('A district of the City. Authored content; a slug is permanent because other rows and config name it.');
            $table->id()->comment('Surrogate key.');
            $table->string('slug', 50)->unique()->comment('Stable machine name. Permanent. config/city.php names the starting district by it.');
            $table->string('name', 100)->comment('What a player reads on the city map.');
            $table->text('description')->nullable()->comment('Flavour shown on the district map. NULL is ordinary.');
            $table->string('color', 16)->default('steel')->comment('App\\City\\DistrictColor: a decoration token naming which district this is on the maps and lists. Says which, never what state.');
            $table->unsignedTinyInteger('map_x')->comment('Percentage across the city map, 0-100. Inset from the edges so a label is never cut off.');
            $table->unsignedTinyInteger('map_y')->comment('Percentage down the city map, 0-100.');
            $table->decimal('travel_weight', 4, 2)->default(1)->comment('Multiplier on how long crossing THIS district takes when travelling. 1 is the base. Read by the router in phase 2; nothing reads it yet.');
            $table->timestamp('created_at')->nullable()->comment('Laravel bookkeeping: when the row was created.');
            $table->timestamp('updated_at')->nullable()->comment('Laravel bookkeeping: when the row was last changed.');
        });

        Schema::create('district_links', function (Blueprint $table): void {
            $table->comment('An undirected link between two districts: the graph travel routes over. Stored once, lower id first; the writer canonicalises.');
            $table->foreignId('district_a_id')->comment('The lower of the two district ids.')->constrained('districts')->cascadeOnDelete();
            $table->foreignId('district_b_id')->comment('The higher of the two district ids.')->constrained('districts')->cascadeOnDelete();
            $table->primary(['district_a_id', 'district_b_id']);
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->comment('A place in a district. Authored content. Visibility is a read, never a stored state: see App\\Models\\Location::scopeVisibleTo().');
            $table->id()->comment('Surrogate key.');
            $table->foreignId('district_id')->comment('Which district it is in.')->constrained('districts')->cascadeOnDelete();
            $table->string('slug', 60)->unique()->comment('Stable machine name. Permanent.');
            $table->string('name', 100)->comment('What a player reads once they can see it.');
            $table->text('description')->nullable()->comment('Flavour. NULL is ordinary.');
            $table->unsignedTinyInteger('map_x')->comment('Percentage across the district map, 0-100.');
            $table->unsignedTinyInteger('map_y')->comment('Percentage down the district map, 0-100.');
            $table->string('discovery', 20)->default('open')->comment('App\\City\\DiscoveryRule: open, explorable or explored_by_flag.');
            $table->string('concealment', 20)->nullable()->comment('App\\City\\Concealment. Set exactly when discovery is explorable, NULL otherwise.');
            $table->string('required_flag', 60)->nullable()->comment('The character flag that makes it visible. Set exactly when discovery is explored_by_flag, NULL otherwise.');
            $table->timestamp('created_at')->nullable()->comment('Laravel bookkeeping: when the row was created.');
            $table->timestamp('updated_at')->nullable()->comment('Laravel bookkeeping: when the row was last changed.');

            $table->index('district_id');
        });

        Schema::create('character_locations', function (Blueprint $table): void {
            $table->comment('A row means this character has FOUND this explorable location by exploring. The only writer is App\\City\\Discovery.');
            $table->foreignId('character_id')->comment('Who found it.')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->comment('What they found.')->constrained()->cascadeOnDelete();
            $table->timestamp('found_at')->useCurrent()->comment('When it was found.');
            $table->primary(['character_id', 'location_id']);
        });

        Schema::create('character_flags', function (Blueprint $table): void {
            $table->comment('A permanent mark on a character: something that has happened to them. Set once, never unset. The only writer is App\\Characters\\Flags.');
            $table->foreignId('character_id')->comment('Whose flag.')->constrained()->cascadeOnDelete();
            $table->string('flag', 60)->comment('A lower-case slug. Locations name the flag they need in required_flag; nothing else enumerates them.');
            $table->timestamp('set_at')->useCurrent()->comment('When it was set.');
            $table->primary(['character_id', 'flag']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE districts ADD CONSTRAINT chk_districts_map CHECK (map_x <= 100 AND map_y <= 100 AND travel_weight > 0)');
            DB::statement("ALTER TABLE districts ADD CONSTRAINT chk_districts_color CHECK (color IN ('steel', 'cyan', 'brass', 'violet', 'clay', 'jade', 'moss'))");
            DB::statement('ALTER TABLE locations ADD CONSTRAINT chk_locations_map CHECK (map_x <= 100 AND map_y <= 100)');
            DB::statement("ALTER TABLE locations ADD CONSTRAINT chk_locations_discovery CHECK (
                discovery IN ('open', 'explorable', 'explored_by_flag')
                AND ((discovery = 'explorable') = (concealment IS NOT NULL))
                AND ((discovery = 'explored_by_flag') = (required_flag IS NOT NULL))
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('character_flags');
        Schema::dropIfExists('character_locations');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('district_links');
        Schema::dropIfExists('districts');
    }
};
