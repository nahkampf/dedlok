<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A firearm has no damage of its own: the ROUND decides it.
 *
 * Until now a gun carried a damage figure "with its standard round" and ammunition carried a signed
 * percent on top. That made the gun own a number it cannot fire without a round, so the damage and
 * the profile move to the ammunition: its `damage` becomes the whole figure of a hit (an absolute
 * base, never a percent) and its profile says how that splits. A weapon with a calibre keeps
 * neither. A blade, a club or a grenade has no ammunition and keeps both on the weapon.
 *
 * Existing rows convert in place: a round's new damage is the damage of the weapons that take its
 * calibre (their average, rounded) with its percent applied, and a round with no profile of its
 * own takes the profile of the first such weapon (or a plain penetrating hit). Then the firearms
 * are emptied. A round no weapon takes has nothing to scale and becomes the percent's worth of 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE weapons DROP CHECK chk_weapons_damage');
        }

        Schema::table('weapons', function ($table): void {
            $table->unsignedSmallInteger('damage')->nullable()->comment('The ONE fixed damage a hit does, before hit quality and resistance. A base the resolver reads; never a range. NULL for a firearm (one with a calibre): its round carries the damage.')->change();
            $table->json('damage_profile')->nullable()->comment('A JSON map of damage type to percent share, summing to 100: a katana {slashing: 100}, a stun baton {blunt: 70, electrical: 30}. Each part of a hit resolves on its own against the target\'s resistance to that type. NULL for a firearm: its round carries the profile.')->change();
        });

        Schema::table('ammunition', function ($table): void {
            $table->smallInteger('damage')->default(1)->comment('The ONE fixed damage a hit does with this round, before hit quality and resistance: an absolute base the resolver reads, never a range or a percent. The weapon that fires it carries none.')->change();
        });

        // Convert what exists: percent-on-the-weapon becomes a figure on the round.
        foreach (DB::table('ammunition')->get() as $round) {
            $weapons = DB::table('weapons')->where('calibre', $round->calibre)->orderBy('id')->get();
            $base = $weapons->isEmpty() ? 10 : (int) round($weapons->avg('damage'));
            $profile = $round->damage_profile ?? $weapons->first()?->damage_profile ?? json_encode(['penetrating' => 100]);

            DB::table('ammunition')->where('id', $round->id)->update([
                'damage' => max(1, (int) round($base * (1 + $round->damage / 100))),
                'damage_profile' => $profile,
            ]);
        }

        DB::table('weapons')->whereNotNull('calibre')->update(['damage' => null, 'damage_profile' => null]);

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE weapons ADD CONSTRAINT chk_weapons_damage CHECK (damage IS NULL OR damage >= 1)');
            DB::statement('ALTER TABLE weapons ADD CONSTRAINT chk_weapons_damage_or_calibre CHECK ((damage IS NULL) = (calibre IS NOT NULL))');
            DB::statement('ALTER TABLE ammunition ADD CONSTRAINT chk_ammunition_damage CHECK (damage >= 1)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE weapons DROP CHECK chk_weapons_damage_or_calibre');
            DB::statement('ALTER TABLE weapons DROP CHECK chk_weapons_damage');
            DB::statement('ALTER TABLE ammunition DROP CHECK chk_ammunition_damage');
        }

        // The old shape needs a figure on every weapon: a firearm takes the average of the rounds it fires.
        foreach (DB::table('weapons')->whereNotNull('calibre')->get() as $weapon) {
            $rounds = DB::table('ammunition')->where('calibre', $weapon->calibre)->get();

            DB::table('weapons')->where('id', $weapon->id)->update([
                'damage' => max(1, (int) round($rounds->avg('damage') ?: 10)),
                'damage_profile' => $rounds->first()?->damage_profile ?? json_encode(['penetrating' => 100]),
            ]);
        }

        DB::table('ammunition')->update(['damage' => 0]);

        Schema::table('weapons', function ($table): void {
            $table->unsignedSmallInteger('damage')->nullable(false)->comment('The ONE fixed damage a hit does with its standard round, before hit quality and resistance. A base the resolver reads; never a range.')->change();
            $table->json('damage_profile')->nullable(false)->comment('A JSON map of damage type to percent share, summing to 100: a katana {slashing: 100}, a stun baton {blunt: 70, electrical: 30}. Each part of a hit resolves on its own against the target\'s resistance to that type.')->change();
        });

        Schema::table('ammunition', function ($table): void {
            $table->smallInteger('damage')->default(0)->comment('A signed percent on the hit while this round is loaded: +25 for a hollow point, -15 for armour piercing. 0 is the weapon\'s own.')->change();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE weapons ADD CONSTRAINT chk_weapons_damage CHECK (damage >= 1)');
        }
    }
};
