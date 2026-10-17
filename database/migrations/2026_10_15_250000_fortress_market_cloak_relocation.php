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
        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedBigInteger('wallet')->default(0)->comment('A Data Fortress\'s own creds: the market\'s tax lands here, and the owner and administrators take it out. Every movement is a fortress_log row.');
            $table->unsignedTinyInteger('market_tax')->default(3)->comment('Per cent of every sale on the fortress market paid into its wallet. 0 to 50.');
            $table->unsignedBigInteger('market_area_id')->nullable()->comment('The one storage area (fortress_areas.id) the market lists from; NULL is none, so it sells nothing.');
            $table->unsignedSmallInteger('fortress_cloak')->nullable()->comment('The strength of the best running Cloak, synced by the fortress settle; NULL when none. A decloaker beats it with a contest to see the fortress at all.');
            $table->timestamp('relocated_at')->nullable()->comment('When a Data Fortress last moved by its Relocation program; the clock the next move is measured from. NULL until it first moves.');
        });

        Schema::create('fortress_listings', function (Blueprint $table): void {
            $table->comment('One file offered on a Data Fortress\'s market by the member who put it there, at their own price.');
            $table->id()->comment('Primary key.');
            $table->unsignedBigInteger('content_id')->unique()->comment('The file (system_contents.id, a plain id). A sold or withdrawn file takes its listing with it.');
            $table->unsignedBigInteger('system_id')->index()->comment('The fortress (a plain systems.id).');
            $table->unsignedBigInteger('seller_character_id')->comment('Whose price it is (a plain characters.id).');
            $table->unsignedInteger('price')->comment('Creds, set by the seller.');
            $table->timestamps();
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->timestamp('turn_at')->nullable()->comment('When this run last took a turn. A run against a Data Fortress takes one a fixed 30 seconds apart.');
        });

        DB::getDriverName() === 'mysql' && $this->kinds([...ProgramKind::values(), 'locator', 'pathfinder']);
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('turn_at'));
        Schema::dropIfExists('fortress_listings');
        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn(['wallet', 'market_tax', 'market_area_id', 'fortress_cloak', 'relocated_at']));

        DB::getDriverName() === 'mysql' && $this->kinds(array_values(array_diff([...ProgramKind::values(), 'locator', 'pathfinder'], ['market', 'cloak', 'relocation'])));
    }

    /** @param  list<string>  $kinds */
    private function kinds(array $kinds): void
    {
        DB::statement('ALTER TABLE `programs` DROP CHECK `chk_programs_kind`');
        DB::statement('ALTER TABLE `programs` ADD CONSTRAINT `chk_programs_kind` CHECK (`kind` IN ('.implode(',', array_map(static fn (string $k): string => "'{$k}'", $kinds)).'))');
    }
};
