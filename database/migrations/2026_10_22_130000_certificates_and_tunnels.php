<?php

declare(strict_types=1);

use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subnet Certificates and Secure Tunnelling (docs/CYBERSPACE.md, *Subnet fortresses*): the item and the grid it is stamped with,
 * the tunnel program kind, and the cooldown of a character's last tunnel to a fortress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('grid_id')->nullable()->comment('For a Subnet Certificate: the subnet (grids.id) it is valid for, stamped when it is taken from a node of that subnet. NULL on everything else. A plain id, no foreign key.');
        });

        Schema::create('fortress_tunnels', function (Blueprint $table): void {
            $table->comment('When a character last tunnelled to a Data Fortress. The cooldown is measured from used_at.');
            $table->id()->comment('Primary key.');
            $table->unsignedBigInteger('character_id')->comment('Who tunnelled (a plain characters.id).');
            $table->unsignedBigInteger('system_id')->comment('The fortress they tunnelled to (a plain systems.id).');
            $table->timestamp('used_at')->comment('The last tunnel to this fortress by this character.');
            $table->unique(['character_id', 'system_id'], 'uq_fortress_tunnel');
        });

        DB::getDriverName() === 'mysql' && $this->kinds([...ProgramKind::values(), 'locator', 'pathfinder']);

        $now = now();

        if (! DB::table('items')->where('slug', 'subnet_certificate')->exists()) {
            DB::table('items')->insert([
                'slug' => 'subnet_certificate', 'name' => 'Subnet Certificate', 'container' => 'data', 'tier' => 1, 'rarity' => 'rare',
                'flavor' => 'A signed grant of residence on one subnet and no other. Without it, nothing of yours may take root there.',
                'stackable' => false, 'size' => 0.1, 'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('items')->where('slug', 'subnet_certificate')->delete();
        Schema::dropIfExists('fortress_tunnels');
        Schema::table('inventory_items', fn (Blueprint $t) => $t->dropColumn('grid_id'));

        DB::getDriverName() === 'mysql' && $this->kinds(array_values(array_diff([...ProgramKind::values(), 'locator', 'pathfinder'], ['tunnel'])));
    }

    /** @param  list<string>  $kinds */
    private function kinds(array $kinds): void
    {
        DB::statement('ALTER TABLE `programs` DROP CHECK `chk_programs_kind`');
        DB::statement('ALTER TABLE `programs` ADD CONSTRAINT `chk_programs_kind` CHECK (`kind` IN ('.implode(',', array_map(static fn (string $k): string => "'{$k}'", $kinds)).'))');
    }
};
