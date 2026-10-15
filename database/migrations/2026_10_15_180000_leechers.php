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
        Schema::create('leeches', function (Blueprint $table): void {
            $table->comment('A Leecher hanging on a host system, lending one resource to its owner\'s Data Fortress. Live only while the host is up.');
            $table->id()->comment('Primary key.');
            $table->foreignId('character_id')
                ->comment('Whose Leecher it is.')
                ->constrained('characters')->cascadeOnDelete();
            $table->unsignedBigInteger('fortress_system_id')->index()
                ->comment('The fortress it feeds. A plain systems.id with no foreign key: the row is read live and a gone fortress just feeds nothing.');
            $table->unsignedBigInteger('host_system_id')->index()
                ->comment('The system it is installed on. Plain id, no foreign key; a burned host ends the leech.');
            $table->string('resource', 10)->comment('What it steals: compute, memory or storage.');
            $table->decimal('amount', 10, 1)->comment('How much, to one decimal place (qFLOPS, QB of RAM or QB of storage).');
            $table->timestamp('installed_at')->comment('When it went on.');
            $table->timestamps();

            $table->unique(['character_id', 'host_system_id'], 'uniq_leech_per_player_per_node');
        });

        $this->kinds(ProgramKind::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('leeches');
        $this->kinds(array_values(array_diff(ProgramKind::values(), ['leecher'])));
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
