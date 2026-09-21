<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heat decays, and half a point a night does not fit in an integer.
 *
 * WHAT EARNS IT IS WHOLE AND WHAT REMOVES IT IS NOT. A completed trace puts one
 * point on and a burn two; the nightly cooling takes 0.5, and a skill that
 * speeds it up will take some other fraction. Rounding the decay to whole
 * points would mean either no decay at all below one a night or a decay rate
 * nobody can tune between one and two.
 *
 * ONE DECIMAL, matching the `tenth` rounding the `heat_decay` stat resolves to
 * and the same precision a pool keeps. Nothing in the game needs to know
 * whether somebody is carrying 3.47 or 3.5.
 *
 * The column stays unsigned and stays defaulted to zero: heat has a floor at
 * nothing and no ceiling at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->decimal('heat', 8, 1)->unsigned()->default(0)
                ->comment('What the world knows about this character, from traces that completed and machines burned. PERSISTENT and theirs — not the run\'s thermal load, which is a different quantity that used to share the word. One point per completed trace, two per burn; it decays by the `heat_decay` stat every night, and it is what App\Characters\Bounties rolls against.')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->unsignedInteger('heat')->default(0)
                ->comment('What the world knows about this character, from traces that completed. PERSISTENT and theirs — not the run\'s thermal load, which is a different quantity that used to share the word. One per completed trace for now; what heat DOES is not designed.')
                ->change();
        });
    }
};
