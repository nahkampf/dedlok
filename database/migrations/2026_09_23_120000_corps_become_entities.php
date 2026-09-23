<?php

declare(strict_types=1);

use App\Entities\EntityCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The companies that own machines become ENTITIES, and a few of them become
 * somebody you can work for.
 *
 * A rename rather than a second table. `corps` already owns 2,866 of the
 * 2,867 systems and `systems.owner_id` was already named generically, so a new
 * table would mean a system having two possible kinds of owner and every
 * reader asking which. The ids are untouched, which is the whole point: they
 * are written into thousands of rows.
 *
 * MySQL's RENAME TABLE carries the foreign key with it, so nothing on
 * `systems` is rewritten here.
 *
 * **Every existing row becomes a flavour corporation.** `interactable`
 * defaults to false for the same reason `archetypes.playable` and
 * `items.seed_quantity` default the way they do: nothing becomes real in this
 * game by being forgotten. Promoting one is authoring, and it happens in a
 * migration of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('corps', 'entities');

        Schema::table('entities', function (Blueprint $table): void {
            $table->string('category', 16)
                ->default(EntityCategory::Corporation->value)
                ->after('name')
                ->comment('What kind of organisation this is: '.implode(', ', EntityCategory::values()).'. Read as App\Entities\EntityCategory; a fifth kind is a case and a label, never a row.');

            $table->boolean('interactable')
                ->default(false)
                ->after('category')
                ->comment('Whether a player can deal with them: missions, standing, an Exchange, a loyalty store. FALSE is the default and the ordinary state — an entity nobody can deal with still owns machines, names them and appears on cards.');

            $table->text('description')
                ->nullable()
                ->after('business')
                ->comment('The long form, for the Exchange screen. NULL on every flavour row; only somebody you can work for needs more than the one-line business.');
        });

        // Said out loud rather than left to the column default, because the
        // default is what NEW rows get and this is about the 121 that exist.
        DB::table('entities')->update([
            'category' => EntityCategory::Corporation->value,
            'interactable' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->dropColumn(['category', 'interactable', 'description']);
        });

        Schema::rename('entities', 'corps');
    }
};
