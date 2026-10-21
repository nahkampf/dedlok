<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Path of Repentance does what its flavour claims, a little: while it runs it works the need off, a hundredth of a point
 * of addiction a minute from every family (`addiction_recovery`, the stat built for a Script like this), beside the
 * fatigue it already costs. The item is authored in the live editor, so this only adds the modifier when it is missing
 * and leaves everything else on the row exactly as it was.
 */
return new class extends Migration
{
    private const CURE = ['op' => 'add', 'amount' => 0.01, 'target' => ['id' => 'addiction_recovery']];

    public function up(): void
    {
        $id = DB::table('items')->where('slug', 'path_of_repentance')->value('id');
        $row = $id === null ? null : DB::table('programs')->where('item_id', $id)->first();

        if ($row === null) {
            return;
        }

        $mods = json_decode((string) $row->modifiers, true) ?: [];

        if (collect($mods)->contains(fn (array $m): bool => ($m['target']['id'] ?? null) === 'addiction_recovery')) {
            return;
        }

        DB::table('programs')->where('id', $row->id)->update(['modifiers' => json_encode([...$mods, self::CURE]), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $id = DB::table('items')->where('slug', 'path_of_repentance')->value('id');
        $row = $id === null ? null : DB::table('programs')->where('item_id', $id)->first();

        if ($row === null) {
            return;
        }

        $mods = array_values(array_filter(json_decode((string) $row->modifiers, true) ?: [], fn (array $m): bool => ($m['target']['id'] ?? null) !== 'addiction_recovery'));

        DB::table('programs')->where('id', $row->id)->update(['modifiers' => $mods === [] ? null : json_encode($mods)]);
    }
};
