<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Network Topology did nothing; it now widens what its owner sees on the grid by one view range level per level
 * trained (`view_range`, see `cyberspace.view_shapes`).
 *
 * The existing rows are kept and the five new ones appended, so anything an author has already put on it stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        $skill = DB::table('skills')->where('slug', 'networktopology')->first();

        if ($skill === null) {
            return;
        }

        $modifiers = (array) json_decode((string) ($skill->modifiers ?? '[]'), true);

        foreach (range(1, 5) as $level) {
            $modifiers[] = ['op' => 'add', 'level' => $level, 'amount' => 1, 'target' => ['id' => 'view_range']];
        }

        DB::table('skills')->where('id', $skill->id)->update(['modifiers' => json_encode($modifiers)]);
    }

    public function down(): void
    {
        $skill = DB::table('skills')->where('slug', 'networktopology')->first();

        if ($skill === null) {
            return;
        }

        $kept = array_values(array_filter(
            (array) json_decode((string) ($skill->modifiers ?? '[]'), true),
            static fn (array $m): bool => ($m['target']['id'] ?? null) !== 'view_range',
        ));

        DB::table('skills')->where('id', $skill->id)->update(['modifiers' => $kept === [] ? null : json_encode($kept)]);
    }
};
