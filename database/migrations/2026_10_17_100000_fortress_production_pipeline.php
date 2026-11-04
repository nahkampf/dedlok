<?php

declare(strict_types=1);

use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Producers become two kinds, Decryptors and Assemblers (docs/CYBERSPACE.md, *Production*). The shared job queue goes, an Assembler is
 * assigned one Design on its own row, and each type gets an input and an output storage area in place of the single output area.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedBigInteger('decryptor_in_area_id')->nullable()->comment('The storage area (fortress_areas.id, a plain id) a fortress\'s Decryptors take Encrypted Data Dumps from; NULL is the owner\'s storage.');
            $table->unsignedBigInteger('decryptor_out_area_id')->nullable()->comment('The storage area its Decryptors put components in; NULL is the owner\'s storage.');
            $table->unsignedBigInteger('assembler_in_area_id')->nullable()->comment('The storage area its Assemblers take Designs and components from; NULL is the owner\'s storage.');
            $table->unsignedBigInteger('assembler_out_area_id')->nullable()->comment('The storage area its Assemblers put what they make in; NULL is the owner\'s storage.');
        });

        DB::table('systems')->update(['decryptor_out_area_id' => DB::raw('output_area_id'), 'assembler_out_area_id' => DB::raw('output_area_id')]);

        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn('output_area_id'));
        Schema::dropIfExists('fortress_jobs');

        Schema::create('fortress_assemblies', function (Blueprint $table): void {
            $table->comment('What one hosted Assembler is set to make: a Design, how many runs, and the run in progress. One row per Assembler.');
            $table->id()->comment('Primary key.');
            $table->foreignId('inventory_item_id')->unique()->comment('The installed Assembler (inventory_items.id). The row goes when it is taken down.')->constrained('inventory_items')->cascadeOnDelete();
            $table->unsignedBigInteger('design_item_id')->nullable()->comment('The Design (items.id) it makes from; NULL is idle. A plain id: a deleted Design simply stops the Assembler.');
            $table->unsignedInteger('runs')->default(1)->comment('How many runs to make; 0 is for ever (until the components or Copies run out).');
            $table->unsignedInteger('runs_done')->default(0)->comment('Runs finished since the Design was set.');
            $table->boolean('started')->default(false)->comment('Whether a run is in progress (its components were spent), so they are not spent twice.');
            $table->decimal('work_minutes', 10, 2)->default(0)->comment('The minutes the run in progress takes, snapshotted when it started.');
            $table->decimal('progress_minutes', 10, 2)->default(0)->comment('Minutes worked on it so far.');
            $table->timestamps();
        });

        // Free the word, then swap the kind in the list.
        DB::table('programs')->where('kind', 'producer')->update(['kind' => 'fortress_daemon']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `programs` DROP CHECK `chk_programs_kind`');
            DB::statement('ALTER TABLE `programs` ADD CONSTRAINT `chk_programs_kind` CHECK (`kind` IN ('.implode(',', array_map(static fn (string $k): string => "'{$k}'", ProgramKind::values())).'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fortress_assemblies');

        Schema::create('fortress_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('system_id')->index();
            $table->string('kind', 12);
            $table->unsignedBigInteger('design_item_id')->nullable();
            $table->decimal('work', 10, 3);
            $table->decimal('progress', 10, 3)->default(0);
            $table->boolean('started')->default(false);
            $table->unsignedBigInteger('queued_by')->nullable();
            $table->timestamps();
        });

        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedBigInteger('output_area_id')->nullable();
        });
        DB::table('systems')->update(['output_area_id' => DB::raw('decryptor_out_area_id')]);
        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn(['decryptor_in_area_id', 'decryptor_out_area_id', 'assembler_in_area_id', 'assembler_out_area_id']));

        if (DB::getDriverName() === 'mysql') {
            DB::table('programs')->whereIn('kind', ['fortress_decryptor', 'fortress_assembler'])->update(['kind' => 'fortress_daemon']);
            DB::statement('ALTER TABLE `programs` DROP CHECK `chk_programs_kind`');
            DB::statement('ALTER TABLE `programs` ADD CONSTRAINT `chk_programs_kind` CHECK (`kind` IN ('.implode(',', array_map(static fn (string $k): string => "'{$k}'", [...array_diff(ProgramKind::values(), ['fortress_decryptor', 'fortress_assembler']), 'producer'])).'))');
        }
    }
};
