<?php

declare(strict_types=1);

use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Locators and Pathfinders become one kind, Utility, whose abilities are flags (`locates`, `walks`, `jumps`) on the program.
 * The hosted kind that was called `utility` (it never had an effect) is renamed `fortress_daemon` first, so the word is free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->boolean('locates')->default(false)->comment('A Utility that finds the nearest system you have met that fits a description; the map offers its search while it runs.');
            $table->boolean('walks')->default(false)->comment('A Utility that walks you to what a Locator found (Autotraverse). Its speed is traverse_seconds.');
            $table->boolean('jumps')->default(false)->comment('A Utility that puts a Jump button beside addresses while it runs on the equipped deck.');
        });

        $mysql = DB::getDriverName() === 'mysql';

        // Free the word first, then fold the two old kinds into it.
        $mysql && DB::statement('ALTER TABLE `programs` DROP CHECK `chk_programs_kind`');
        DB::table('programs')->where('kind', 'utility')->update(['kind' => 'fortress_daemon']);
        DB::table('programs')->where('kind', 'locator')->update(['kind' => 'utility', 'locates' => true]);
        DB::table('programs')->where('kind', 'pathfinder')->update(['kind' => 'utility', 'locates' => true, 'walks' => true]);
        $mysql && $this->check(ProgramKind::values());
    }

    public function down(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $mysql && DB::statement('ALTER TABLE `programs` DROP CHECK `chk_programs_kind`');
        DB::table('programs')->where('kind', 'utility')->where('walks', true)->update(['kind' => 'pathfinder']);
        DB::table('programs')->where('kind', 'utility')->where('locates', true)->update(['kind' => 'locator']);
        DB::table('programs')->where('kind', 'utility')->update(['kind' => 'daemon']);
        DB::table('programs')->where('kind', 'fortress_daemon')->update(['kind' => 'utility']);
        $mysql && $this->check(array_values(array_diff([...ProgramKind::values(), 'locator', 'pathfinder'], ['fortress_daemon'])));

        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn(['locates', 'walks', 'jumps']));
    }

    /** @param  list<string>  $kinds */
    private function check(array $kinds): void
    {
        DB::statement('ALTER TABLE `programs` ADD CONSTRAINT `chk_programs_kind` CHECK (`kind` IN ('.implode(',', array_map(static fn (string $k): string => "'{$k}'", $kinds)).'))');
    }
};
