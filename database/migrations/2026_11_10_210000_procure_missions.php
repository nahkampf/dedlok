<?php

declare(strict_types=1);

use App\Missions\MissionAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Procure: an Extraction-class job that names no address. The desk wants components from systems one owner holds, and the
 * job is handed in as the components themselves. What it wants is a row per component (`mission_requirements`), and the
 * action CHECK learns the word: MySQL would otherwise refuse every Procure offer, which SQLite tests cannot show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_requirements', function (Blueprint $table): void {
            $table->comment('One component a Procure job wants, from systems one owner holds. Handing the job in spends this many matching components.');
            $table->id()->comment('Surrogate key.');
            $table->foreignId('mission_id')->comment('The job asking.')->constrained('missions')->cascadeOnDelete();
            $table->foreignId('component_item_id')->comment('The component item wanted. Restricted: a job must not lose a line to an item deleted from under it.')
                ->constrained('items')->restrictOnDelete();
            $table->unsignedTinyInteger('quantity')->comment('How many of it, at least one.');
            $table->unsignedBigInteger('owner_id')->comment('The entity whose systems the component must have come off. A plain id, no foreign key: the file remembers it.');
            $table->string('owner_name', 100)->comment('The owner\'s name, copied at the offer so the card reads right after a rename.');
            $table->timestamp('created_at')->nullable()->comment('When the row was written.');
            $table->timestamp('updated_at')->nullable()->comment('When the row was last changed.');
            $table->unique(['mission_id', 'component_item_id']);
        });

        $this->actions(MissionAction::values());
    }

    public function down(): void
    {
        DB::table('missions')->where('action', 'procure')->delete();

        $this->actions(array_values(array_diff(MissionAction::values(), ['procure'])));

        Schema::dropIfExists('mission_requirements');
    }

    /** @param list<string> $actions */
    private function actions(array $actions): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $list = "'".implode("','", $actions)."'";

        DB::statement('ALTER TABLE `missions` DROP CHECK `chk_missions_action`');
        DB::statement("ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_action` CHECK (`action` IN ({$list}))");
    }
};
