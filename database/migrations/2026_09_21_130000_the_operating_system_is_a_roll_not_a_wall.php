<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `runs.os_locked` changed meaning, so its comment has to change with it.
 *
 * IT USED TO BE THE VERDICT: did the runner hold the machine's operating
 * system at level 1, decided once at the door. That made an unfamiliar system
 * a wall — every action against it failed for ever — and made levels 2 to 5
 * worth nothing at all on a run.
 *
 * NOW IT IS THE STATE OF THE DOOR. It starts true whenever the machine runs an
 * operating system at all, and a core action rolls the runner's LEVEL against
 * it until one lands, at which point the machine is open for the rest of the
 * run. Same column, same name, same question a reader asks of it — "can this
 * run touch the core" — so there is nothing to migrate but the sentence.
 *
 * No default is restated and no type changes: `->change()` on a comment alone
 * would rewrite the column for nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->boolean('os_locked')->default(false)
                ->comment('Is the core still shut to this run? True from the start whenever the machine runs an operating system, and cleared by a successful roll of the runner\'s LEVEL in it — see App\Cyberspace\Simulator::opensTheCore(). NEVER SHOWN as a refusal: a failed core action is indistinguishable from bad luck, because naming the OS would hand over a scan fact for free. The chance itself is on the run screen.')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->boolean('os_locked')->default(false)
                ->comment('Did the runner lack the operating system? Decided when the run began and NEVER SHOWN — every action against the system fails and the failures are indistinguishable from bad luck, because a refusal would hand over a scan fact for free.')
                ->change();
        });
    }
};
