<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wardens (docs/CYBERSPACE.md, *Wardens*): hunters that live in a subnet, walk it, and attack whoever they notice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wardens', function (Blueprint $table): void {
            $table->comment('A Warden: a hunter that walks a subnet by itself. It steps once a minute and attacks a runner it notices within reach.');
            $table->id()->comment('Primary key.');
            $table->unsignedBigInteger('grid_id')->index()->comment('The subnet it walks (a plain grids.id).');
            $table->unsignedSmallInteger('x')->comment('Where it is now.');
            $table->unsignedSmallInteger('y')->comment('Where it is now.');
            $table->unsignedTinyInteger('grade')->comment('Its hunter grade, 1 to 5: how far it notices (cyberspace.wardens.reach), how hard it is to slip past, and what it fights with.');
            $table->timestamp('moved_at')->comment('When it last stepped. The next step is a minute after, taken lazily when somebody looks and by the minute clock.');
            $table->timestamps();
        });

        Schema::table('hunts', function (Blueprint $table): void {
            $table->unsignedBigInteger('warden_id')->nullable()->comment('The Warden this hunt is, when it is one; beating it removes the Warden. NULL for a hunter heat drew. A plain id, no foreign key.');
        });
    }

    public function down(): void
    {
        Schema::table('hunts', fn (Blueprint $t) => $t->dropColumn('warden_id'));
        Schema::dropIfExists('wardens');
    }
};
