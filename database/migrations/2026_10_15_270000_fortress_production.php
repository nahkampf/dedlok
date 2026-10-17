<?php

declare(strict_types=1);

use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->json('produces')->nullable()->comment('What a Producer makes and how fast: {"credits": per hour, "components": speed, "programs": speed}; and for a Booster {"boost": per cent}. NULL for everything else.');
        });

        Schema::table('system_contents', function (Blueprint $table): void {
            $table->json('meta')->nullable()->comment('For an item kept in a Data Fortress: what the copy carried (a component\'s origin as extracted_file, a defence\'s numbers). NULL otherwise.');
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('fortress_installed_by')->nullable()->comment('Who installed this hosted program (a plain characters.id): their skills set how well a Producer works.');
        });

        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedBigInteger('output_area_id')->nullable()->comment('The storage area (fortress_areas.id) a fortress\'s producers deliver into; NULL is the owner\'s own storage.');
            $table->timestamp('production_at')->nullable()->comment('The moment production was last settled; the next settle works the time since.');
        });

        Schema::create('fortress_jobs', function (Blueprint $table): void {
            $table->comment('One thing a Data Fortress\'s producers are making, in the order queued (at most five per fortress).');
            $table->id()->comment('Primary key.');
            $table->unsignedBigInteger('system_id')->index()->comment('The fortress (a plain systems.id).');
            $table->string('kind', 12)->comment('components or program.');
            $table->unsignedBigInteger('design_item_id')->nullable()->comment('For a program: the Design (items.id) being made from. Its components are spent when work starts.');
            $table->decimal('work', 10, 3)->comment('Work units this needs at speed 1.');
            $table->decimal('progress', 10, 3)->default(0)->comment('Work done so far.');
            $table->boolean('started')->default(false)->comment('Whether the components were spent (so they are not spent twice).');
            $table->unsignedBigInteger('queued_by')->nullable()->comment('Whoever queued it (a plain characters.id).');
            $table->timestamps();
        });

        DB::getDriverName() === 'mysql' && $this->kinds([...ProgramKind::values(), 'locator', 'pathfinder']);
    }

    public function down(): void
    {
        Schema::dropIfExists('fortress_jobs');
        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn(['output_area_id', 'production_at']));
        Schema::table('inventory_items', fn (Blueprint $t) => $t->dropColumn('fortress_installed_by'));
        Schema::table('system_contents', fn (Blueprint $t) => $t->dropColumn('meta'));
        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn('produces'));

        DB::getDriverName() === 'mysql' && $this->kinds(array_values(array_diff([...ProgramKind::values(), 'locator', 'pathfinder'], ['booster'])));
    }

    /** @param  list<string>  $kinds */
    private function kinds(array $kinds): void
    {
        DB::statement('ALTER TABLE `programs` DROP CHECK `chk_programs_kind`');
        DB::statement('ALTER TABLE `programs` ADD CONSTRAINT `chk_programs_kind` CHECK (`kind` IN ('.implode(',', array_map(static fn (string $k): string => "'{$k}'", $kinds)).'))');
    }
};
