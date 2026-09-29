<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Item;
use App\Models\Skill;
use App\Programs\ProgramEditor;
use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Content authored in the LOCAL admin on 2026-09-28, carried to production.
 *
 * Content is normally authored in the live admin and pulled down. These rows
 * went the other way — made on localhost — so a migration is how they reach
 * the live game, the route every other content migration takes. Everything is
 * keyed by SLUG and upserted, so running it where the rows already exist (the
 * laptop that made them) changes nothing.
 *
 * - Spectral (alpha 0.3): a daemon, −8% ICE damage while it runs. Off the
 *   market; Joint Signals Command keeps it.
 * - Trauma Recovery: −10% `recovery_seconds` a level.
 * - Showmanship: +3% `standing_gain` a level.
 * - Joint Signals Command's shelf: Spectral, Palantír and THO Nightjar.
 * - Routing loses its +5% bandwidth at level 2 and is routing hops only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $spectral = Item::query()->updateOrCreate(['slug' => 'spectral'], [
            'name' => 'Spectral (alpha 0.3)',
            'flavor' => 'A highly experimental (and janky) piece of software that spawns a multitude of cloned '
                .'processes, that attempt to make themselves as noisy as possible. This will usually attract at '
                .'least part of the ICE heuristics, and in effect draw some of the attention away from the '
                .'important processes. A quite RAM-intensive process to run.',
            'container' => 'data',
            'tier' => 2,
            'rarity' => 'rare',
            'stackable' => true,
            'weight' => 0,
            'size' => 0,
            'capacity' => 0,
            'base_cost' => 25000,
            'on_market' => false,
            'seed_quantity' => 0,
        ]);

        ProgramEditor::apply($spectral, ProgramKind::Daemon, [
            'qflops_cost' => 9,
            'memory_cost' => 18,
            'storage_size' => 6,
            'background_process' => false,
            'multithreaded' => false,
            'modifiers' => [
                ['op' => 'percentage_of_base', 'amount' => -8, 'target' => ['id' => 'damage.synaptic_integrity.ice']],
            ],
        ]);

        $this->skill('trauma_recovery', [
            'title' => 'Trauma Recovery',
            'description' => 'Through a combination of meditations, breathing exercises, mental preparations, '
                .'semi-autonomous muscle movements and conditioned nerve responsens it is possible to quickly '
                .'recover from traumatic injury or unconsciousness.',
            'category' => 'Physical',
            'primary_attribute' => 'meat',
            'secondary_attribute' => 'focus',
            'base_cost' => 3000,
        ], 'recovery_seconds', -10);

        $this->skill('showmanship', [
            'title' => 'Showmanship',
            'description' => 'When doing gigs for someone else it often pays to add some flair, to be larger than '
                .'life and to make yourself look as good as you can while doing the work. That\'s how you get '
                .'noticed, and rewarded.',
            'category' => 'Social',
            'primary_attribute' => 'social',
            'secondary_attribute' => 'perception',
            'base_cost' => 2500,
        ], 'standing_gain', 3);

        $jsc = DB::table('entities')->where('name', 'Joint Signals Command')->value('id');

        if ($jsc !== null) {
            foreach ([
                ['spectral', 3, 1000, 20],
                ['icebreaker-8', 2, 800, 20],
                ['tho_nightjar', 4, 1500, 30],
            ] as [$slug, $minGrade, $price, $standing]) {
                $itemId = DB::table('items')->where('slug', $slug)->value('id');

                if ($itemId === null) {
                    continue;
                }

                DB::table('entity_offers')->updateOrInsert(
                    ['entity_id' => $jsc, 'item_id' => $itemId],
                    [
                        'min_grade' => $minGrade,
                        'max_grade' => 5,
                        'loyalty_price' => $price,
                        'min_standing' => $standing,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        Skill::query()->where('slug', 'routing')->first()?->update([
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => 'add', 'level' => $level, 'amount' => 1, 'target' => ['id' => 'routing_hops'],
                ],
                range(1, 5),
            ),
        ]);
    }

    public function down(): void
    {
        DB::table('entity_offers')
            ->where('item_id', DB::table('items')->where('slug', 'spectral')->value('id'))
            ->delete();

        foreach (['trauma_recovery', 'showmanship'] as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();
            $skill?->imprint()->delete();
            $skill?->delete();
        }

        Item::query()->where('slug', 'spectral')->delete();
    }

    /** A rank 8 skill on the market, five equal grants against one stat, and its imprint. */
    private function skill(string $slug, array $columns, string $stat, float $amount): void
    {
        $skill = Skill::query()->updateOrCreate(['slug' => $slug], [
            ...$columns,
            'rank' => 8,
            'on_market' => true,
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => 'percentage_of_base', 'level' => $level, 'amount' => $amount, 'target' => ['id' => $stat],
                ],
                range(1, 5),
            ),
        ]);

        Imprint::ensureFor($skill);
    }
};
