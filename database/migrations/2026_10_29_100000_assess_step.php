<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Assessor: a Utility with an `assess_step` shows rough odds on the run screens, rounded to that step.
 * Four Assessors are inserted, finest last: Runstats (20), MonteCarlo (15), Predictor (10), SIGHTLINE (5). An existing item
 * with a slug is left exactly as it is.
 */
return new class extends Migration
{
    /** @var list<array<string, mixed>> */
    private const ITEMS = [
        [
            'slug' => 'runstats', 'name' => 'Runstats', 'tier' => 1, 'rarity' => 'common', 'base_cost' => 350, 'on_market' => true, 'seed_quantity' => 15,
            'flavor' => 'Runstats is an old and abandoned script cobbled together by a now defunct hacking crew, but many people still use it. It analyzes your scan file, your running programs and user profile to give you an assessment of your chance of performing a successful intrusion run against a given system. It is imprecise but can at best be useful to give a hint of where you stand, and at worst lure you into a false sense of safety.',
            'qflops' => 0.5, 'memory' => 0.5, 'storage' => 1.0, 'step' => 20,
        ],
        [
            'slug' => 'montecarlo', 'name' => 'MonteCarlo', 'tier' => 1, 'rarity' => 'uncommon', 'base_cost' => 1500, 'on_market' => true, 'seed_quantity' => 5,
            'flavor' => 'A commercial utility program for security professionals, node administrators and the like, to give a rough estimate of how safe their systems are against intrusion. Hackers use it to assess their odds for an intrusion run against a given system. It is imprecise, but more precise than the commonly available RunStats script.',
            'qflops' => 1.0, 'memory' => 1.0, 'storage' => 2.0, 'step' => 15,
        ],
        [
            'slug' => 'predictor', 'name' => 'Predictor', 'tier' => 2, 'rarity' => 'rare', 'base_cost' => 5000, 'on_market' => false, 'seed_quantity' => 0,
            'flavor' => 'A highly capable risk-assessment tool developed collective between hacker crews Lockjaw and Nodebleed. It analyzes a myriad of factors when setting up an intrusion run and applies heuristics, sample data and modelling to give a very precise prediction on your odds for various actions during an intrusion run. Highly complex to set up and use, and a closely kept secret in the hacker scene, that is not released to the public.',
            'qflops' => 1.0, 'memory' => 1.0, 'storage' => 2.0, 'step' => 10,
        ],
        [
            'slug' => 'sightline', 'name' => 'SIGHTLINE', 'tier' => 2, 'rarity' => 'ultra_rare', 'base_cost' => 15000, 'on_market' => false, 'seed_quantity' => 0,
            'flavor' => 'A highly classified joint research tool from the depths of the Tailored Access Operations vaults, SIGHTLINE is the finest, most precise intrusion run predictor there is. It can give you very detailed and precise calculations on your success chance for various operations during an intrusion run. SIGHTLINE is deemed a national security asset and any handling of this utility without proper security credentials carries serious penalties, not excluding liquidation.',
            'qflops' => 1.5, 'memory' => 2.0, 'storage' => 3.0, 'step' => 5,
        ],
    ];

    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('assess_step')->nullable()->comment('A Utility that shows rough odds on the run screens (App\\Cyberspace\\Assessor): the percentage step it rounds to, 5 being the finest. NULL means it is not an Assessor.');
        });

        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests()) {
            return;
        }

        $now = now();

        foreach (self::ITEMS as $row) {
            if (DB::table('items')->where('slug', $row['slug'])->exists()) {
                continue;
            }

            $item = DB::table('items')->insertGetId([
                'slug' => $row['slug'], 'name' => $row['name'], 'flavor' => $row['flavor'], 'container' => 'data', 'tier' => $row['tier'],
                'rarity' => $row['rarity'], 'base_cost' => $row['base_cost'], 'on_market' => $row['on_market'], 'seed_quantity' => $row['seed_quantity'],
                'stackable' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('programs')->insert([
                'item_id' => $item, 'kind' => 'utility', 'qflops_cost' => $row['qflops'], 'memory_cost' => $row['memory'], 'storage_size' => $row['storage'],
                'background_process' => true, 'multithreaded' => false, 'assess_step' => $row['step'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('items')->whereIn('slug', array_column(self::ITEMS, 'slug'))->delete();

        Schema::table('programs', function (Blueprint $table): void {
            $table->dropColumn('assess_step');
        });
    }
};
