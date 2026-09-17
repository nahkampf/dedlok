<?php

declare(strict_types=1);

use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first two evasion programs, and the stat they were waiting for.
 *
 * `ProgramKind::Evasion` has been an empty case since programs shipped —
 * declared because a kind is a gate as much as a modifier, and then never
 * filled, because there was nothing for one to modify. `stealth` is that thing.
 *
 * By migration for the reason the scanners and the icebreakers were: nothing
 * sells software yet, and content a mechanic cannot work without has to exist
 * before the mechanic ships. The item editor is where these get TUNED.
 *
 * ## The two are deliberately different shapes
 *
 * **PiggyBack is flat and unconditional.** Two points, for anybody, the moment
 * it runs. Cheap, always exactly what it says, and worth proportionally most to
 * somebody who has trained nothing — which is what a Tier One tool should be.
 *
 * **PayloadInjector is worth what its operator is.** Four per cent per level of
 * Obfuscation, which is FIVE MODIFIERS each conditional on one more level
 * rather than one modifier that multiplies — because a modifier's amount is a
 * number and never an expression. Five copies summing under a condition is the
 * only shape the resolver has for "per level of something else", and it is the
 * same shape a skill's own per-level grants use.
 *
 * Untrained it does nothing at all, which is the point: the Tier Two tool is
 * not a better version of the Tier One one, it is a different bargain. And
 * because percentages apply to base plus flat and are summed before being
 * applied once, running both is the flat two points FIRST and then the
 * percentage over everything — so the two compose rather than competing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $programs = [
            [
                'slug' => 'evasion-piggyback',
                'name' => 'PiggyBack 2.01',
                'flavor' => 'Attempts to wrap intrusion commands into standard system maintenance calls, in order to avoid detection by Intrusion Detection Systems.',
                'tier' => 1,
                'rarity' => 'common',
                'base_cost' => 900,
                'qflops_cost' => 3,
                'memory_cost' => 2,
                'storage_size' => 1,
                'modifiers' => [[
                    'op' => 'add',
                    'amount' => 2,
                    'target' => ['id' => 'stealth'],
                ]],
            ],
            [
                'slug' => 'evasion-payloadinjector',
                'name' => 'PayloadInjector',
                'flavor' => 'Splits intrusion payloads into chunks and tacks them on to the tail end of userland commands, thereby making it harder for IDS to detect an intrusion attempt.',
                'tier' => 2,
                'rarity' => 'rare',
                'base_cost' => 14000,
                'qflops_cost' => 9,
                'memory_cost' => 6,
                'storage_size' => 2,
                'modifiers' => array_map(
                    static fn (int $level): array => [
                        'op' => 'percentage_of_base',
                        'amount' => 4,
                        'target' => ['id' => 'stealth'],
                        'condition' => ['when' => 'skill_at_least', 'args' => ['skill' => 'obfuscation', 'level' => $level]],
                    ],
                    range(1, 5),
                ),
            ],
        ];

        foreach ($programs as $program) {
            $itemId = DB::table('items')->insertGetId([
                'slug' => $program['slug'],
                'name' => $program['name'],
                'flavor' => $program['flavor'],
                'container' => 'data',
                'tier' => $program['tier'],
                'rarity' => $program['rarity'],
                'base_cost' => $program['base_cost'],
                // Listed but not stocked, like the scanners and the
                // icebreakers: nothing sells anything yet, and a shelf size
                // invented before the shop would shift every pinned die in the
                // market's own tests.
                'on_market' => true,
                'seed_quantity' => 0,
                'stackable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('programs')->insert([
                'item_id' => $itemId,
                'kind' => ProgramKind::Evasion->value,
                'qflops_cost' => $program['qflops_cost'],
                'memory_cost' => $program['memory_cost'],
                'storage_size' => $program['storage_size'],
                // NOT A BACKGROUND PROCESS. Evasion is for being somewhere you
                // should not be; there is nothing to hide from on the open grid,
                // and a deck spending compute on it out there is spending it on
                // nothing.
                'background_process' => false,
                'modifiers' => json_encode($program['modifiers']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('items')
            ->whereIn('slug', ['evasion-piggyback', 'evasion-payloadinjector'])
            ->pluck('id');

        DB::table('programs')->whereIn('item_id', $ids)->delete();
        DB::table('items')->whereIn('id', $ids)->delete();
    }
};
