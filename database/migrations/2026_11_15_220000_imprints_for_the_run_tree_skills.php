<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * The imprints for Loopback Routing and Egress Tunneling (docs/RUN_TREE.md). Their skills arrived by migration, which
 * skips the admin's own `Imprint::ensureFor()`, and a skill without its imprint is one nobody can learn. Off the market
 * with their skills; safe to run twice (by slug).
 */
return new class extends Migration
{
    private const SKILLS = ['loopback_routing', 'egress_tunneling'];

    public function up(): void
    {
        Skill::query()->whereIn('slug', self::SKILLS)->each(fn (Skill $skill) => Imprint::ensureFor($skill));
    }

    public function down(): void
    {
        Item::query()->whereIn('slug', array_map(static fn (string $s): string => "imprint_{$s}", self::SKILLS))->delete();
    }
};
