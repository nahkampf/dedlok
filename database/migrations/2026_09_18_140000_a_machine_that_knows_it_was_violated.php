<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lockdown: what happens when a system knows it was violated.
 *
 * docs/CYBERSPACE.md's *Lockdown* table lists four triggers and they are all one
 * rule — the machine found out. It fires AFTER a run, whatever else happened,
 * and when the window closes the machine relocates: new position, new address,
 * books balanced. Until now the third raid relocated a machine on the spot,
 * because the window had nowhere to live.
 *
 * TWO COLUMNS AND THEY ARE NOT THE SAME FACT.
 *
 * `systems.locked_down_until` is the truth: NULL is the ordinary state, and a
 * time in the future means nobody may run it and the housekeeping sweep will
 * move it when that time passes. The window is 2-6 hours, rolled, which is
 * long enough that waiting one out is not a plan and short enough that a
 * machine is not out of the world for a session.
 *
 * `system_contacts.locked_down_until` is what somebody SAW. A contact is a
 * sighting and is allowed to be wrong — a machine that locked down after you
 * walked past still looks fine on your map, and a machine you saw rebooting
 * may have relocated since. Recording it on the contact is what keeps the map
 * drawn from memory rather than from a live feed, which is the rule the whole
 * discovery design rests on.
 *
 * EXPIRY IS SWEPT AND NOT LAZY, which is the one place cyberspace departs from
 * the settle-when-somebody-looks pattern a scan, a training deposit and the
 * waiting screen all follow. Two systems relocating into the same free
 * position is a race, and the sweep is what serialises it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->timestamp('locked_down_until')->nullable()->after('raids')
                ->comment('When this machine stops being locked down, or NULL for the ordinary state. Set after a run that it noticed, or on its third raid. While it is in the future nothing may run the machine; when it passes, cyberspace:lockdowns relocates it. Rolled 2-6 hours.');
        });

        Schema::table('system_contacts', function (Blueprint $table): void {
            $table->timestamp('locked_down_until')->nullable()->after('owner')
                ->comment('What the machine looked like WHEN SEEN: locked down until this moment, or NULL for nothing wrong. A sighting is allowed to be wrong — this is not refreshed until the position is swept again.');
        });
    }

    public function down(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->dropColumn('locked_down_until');
        });

        Schema::table('system_contacts', function (Blueprint $table): void {
            $table->dropColumn('locked_down_until');
        });
    }
};
