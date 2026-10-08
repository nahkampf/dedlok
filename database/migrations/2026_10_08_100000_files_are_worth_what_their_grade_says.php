<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Files get the new price bands, and decryption gets somewhere to land.
 *
 * ## Re-rolled, deliberately
 *
 * The old bands were 10-60 times the new ones (a grade 1 file sealed at 120-400). Every file already on a
 * system, and every dump a player is already carrying, is rolled again from `cyberspace.contents.data`, so
 * nobody holds a file worth ten times what the same file is worth tomorrow. A dump taken before the grade
 * was recorded is given one from the band of its encryption.
 *
 * ## The Memory Dump
 *
 * What a decrypted file becomes. Created here, like the Encrypted Data Dump, and never stocked or bought:
 * the row is found, in the `extracted_file` JSON the dump already carries. Nothing makes one yet.
 *
 * The values are rolled in PHP rather than SQL so the bands stay the one config the stocker reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (! DB::table('items')->where('slug', 'memory-dump')->exists()) {
            DB::table('items')->insert([
                'slug' => 'memory-dump',
                'name' => 'Memory Dump',
                'flavor' => 'The ciphertext is gone and what is left is raw memory, a system\'s thoughts with nobody at the wheel. Somebody pays well for these.',
                'container' => 'data',
                'tier' => 1,
                'rarity' => 'common',
                'base_cost' => 0,
                'on_market' => false,
                'seed_quantity' => 0,
                'stackable' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $bands = (array) Config::get('cyberspace.contents.data', []);
        $roll = fn (string $key, int $grade): int => random_int(...array_map('intval', $bands[$key][$grade] ?? [1, 1]));

        DB::table('system_contents')
            ->join('systems', 'systems.id', '=', 'system_contents.system_id')
            ->where('system_contents.kind', 'data')
            ->select('system_contents.id', 'systems.grade')
            ->orderBy('system_contents.id')
            ->each(function ($row) use ($roll): void {
                DB::table('system_contents')->where('id', $row->id)->update([
                    'value_sealed' => $roll('value', (int) $row->grade),
                    'value_broken' => $roll('value_broken', (int) $row->grade),
                ]);
            });

        $slugs = DB::table('skills')->where('category', 'Encryption')->pluck('slug', 'id');
        $dumps = DB::table('items')->whereIn('slug', ['encrypted-data-dump', 'memory-dump'])->pluck('id');

        DB::table('inventory_items')->whereIn('item_id', $dumps)->whereNotNull('extracted_file')->orderBy('id')->each(function ($row) use ($roll, $slugs, $bands): void {
            $file = json_decode($row->extracted_file, true);
            [$low, $high] = $bands['encryption'][$slugs[$file['encryption_skill_id'] ?? 0] ?? ''] ?? [1, 9];
            $grade = $file['grade'] ?? random_int($low, $high);

            $file['grade'] = $grade;
            $file['value_sealed'] = $roll('value', $grade);
            $file['value_broken'] = $roll('value_broken', $grade);

            DB::table('inventory_items')->where('id', $row->id)->update(['extracted_file' => json_encode($file)]);
        });
    }

    public function down(): void
    {
        // The old values are not recoverable and the Memory Dump may be held; nothing to undo safely.
    }
};
