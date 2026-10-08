<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** `ProgramKind::Script` is a kind, so the CHECK that lists the kinds has to admit it. MySQL only; SQLite never had the constraint. */
return new class extends Migration
{
    public function up(): void
    {
        $this->kinds(['icebreaker', 'decryptor', 'evasion', 'analyser', 'daemon', 'script']);
    }

    public function down(): void
    {
        DB::table('programs')->where('kind', 'script')->delete();

        $this->kinds(['icebreaker', 'decryptor', 'evasion', 'analyser', 'daemon']);
    }

    /** @param  list<string>  $kinds */
    private function kinds(array $kinds): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `programs` DROP CHECK `chk_programs_kind`');
        DB::statement('ALTER TABLE `programs` ADD CONSTRAINT `chk_programs_kind` CHECK (`kind` IN ('.implode(',', array_map(static fn (string $k): string => "'{$k}'", $kinds)).'))');
    }
};
