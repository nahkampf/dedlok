<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A skillbook is an Eidetic Imprint now, and its slug says so.
 *
 * The NAME needed nothing: an imprint has no name of its own and composes
 * from the skill it carries, so renaming the format in `strings` renamed all
 * thirty-nine of them at once. That is the whole argument for composing it.
 *
 * The slug is the part with a row. It is permanent by rule — `prohibited` on
 * update in the item editor — so it moves here rather than drifting: an item
 * whose slug says skillbook and whose name says imprint is one thing with two
 * names, which is the state this codebase keeps refusing to be in.
 *
 * Nothing outside the items table references these slugs; `Imprint::forSkill()`
 * reads the relation, not the name.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Skill::query()->cursor() as $skill) {
            DB::table('items')
                ->where('slug', "skillbook_{$skill->slug}")
                ->update(['slug' => Imprint::slugFor($skill), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (Skill::query()->cursor() as $skill) {
            DB::table('items')
                ->where('slug', Imprint::slugFor($skill))
                ->update(['slug' => "skillbook_{$skill->slug}", 'updated_at' => now()]);
        }
    }
};
