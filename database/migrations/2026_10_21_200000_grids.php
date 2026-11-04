<?php

declare(strict_types=1);

use App\Cyberspace\SystemType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grids as rows (docs/CYBERSPACE.md, *Subnets*, step 1). The main grid is row 1, and every coordinate table gains a `grid_id`
 * that DEFAULTS to it, so nothing that exists changes: one grid, and every position is on it. A position is now
 * (grid, x, y), and the unique keys say so, which is what lets a second grid hold a system at the same x and y.
 *
 * `grid_id` is NOT NULL DEFAULT 1 on purpose: "null means main" would be a second answer to one question. The tables that
 * REMEMBER (contacts, scans, runs, bookmarks, terrain) carry no foreign key, like the rest of their columns.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['systems', 'characters', 'bookmarks', 'character_terrain', 'runs', 'run_parties', 'system_contacts', 'system_scans'];

    /** Every table that stores an address; a subnet's (`mil:3FCD`) is up to ten characters, not eight. */
    private const ADDRESS_TABLES = ['systems', 'bookmarks', 'mission_targets', 'run_parties', 'runs', 'system_contacts', 'system_scans'];

    /** The address CHECKs, by table: a main address is 8 hex, a subnet's a lower-case prefix, a colon and 4 hex. */
    private const ADDRESS_CHECKS = ['systems' => 'chk_systems_address', 'bookmarks' => 'chk_bookmark_address', 'system_contacts' => 'chk_contacts_address'];

    public function up(): void
    {
        Schema::create('grids', function (Blueprint $table): void {
            $table->comment('The grids of cyberspace: the main one (id 1) and the subnets behind its gateways.');
            $table->id()->comment('Primary key. 1 is the main grid, and every grid_id column defaults to it.');
            $table->string('slug', 40)->unique()->comment('Stable machine name. Permanent.');
            $table->string('name', 80)->comment('What the admin and the map call it.');
            $table->string('prefix', 5)->nullable()->unique()->comment('A subnet\'s address prefix, 2 to 5 lower-case letters (mil:3FCD). NULL for the main grid, whose addresses carry none.');
            $table->unsignedSmallInteger('size')->comment('Edge of the grid in positions. The main grid\'s is cyberspace.size and this row does not override it.');
            $table->unsignedSmallInteger('entry_x')->nullable()->comment('Where the grid is entered. NULL for the main grid, whose portal is the middle.');
            $table->unsignedSmallInteger('entry_y')->nullable()->comment('Where the grid is entered. NULL for the main grid.');
            $table->json('spec')->nullable()->comment('What generates a subnet: {entities: [ids], types: [slugs], grade_min, grade_max, occupancy}. NULL for the main grid, which the global settings generate.');
            $table->timestamp('created_at')->nullable()->comment('When this row was created.');
            $table->timestamp('updated_at')->nullable()->comment('When this row was last changed.');
        });

        DB::table('grids')->insert([
            'id' => 1, 'slug' => 'main', 'name' => 'The grid', 'size' => (int) config('cyberspace.size', 128),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->unsignedBigInteger('grid_id')->default(1)->comment(
                    $name === 'characters'
                        ? 'The grid the body is on, or was last on. Whether they are jacked in is still grid_x being set.'
                        : 'Which grid this position is on (grids.id). Defaults to the main grid.'
                );
            });
        }

        if (DB::getDriverName() !== 'sqlite') {
            foreach (['systems', 'characters'] as $name) {
                Schema::table($name, fn (Blueprint $table) => $table->foreign('grid_id')->references('id')->on('grids')->restrictOnDelete());
            }
        }

        Schema::table('systems', function (Blueprint $table): void {
            $table->dropUnique('uq_systems_position');
            $table->unique(['grid_id', 'x', 'y'], 'uq_systems_position');
        });
        Schema::table('bookmarks', function (Blueprint $table): void {
            $table->dropUnique('uq_bookmark_position');
            $table->unique(['character_id', 'grid_id', 'x', 'y'], 'uq_bookmark_position');
        });
        Schema::table('system_contacts', function (Blueprint $table): void {
            // The new key first: the character foreign key needs an index to stand on while the old one goes.
            $table->unique(['character_id', 'grid_id', 'x', 'y'], 'uq_contact_grid_position');
            $table->dropUnique('uq_contact_position');
        });
        Schema::table('character_terrain', function (Blueprint $table): void {
            $table->unique(['character_id', 'grid_id', 'chunk_x', 'chunk_y'], 'uq_terrain_grid_chunk');
            $table->dropUnique('uq_terrain_chunk');
        });

        Schema::table('systems', function (Blueprint $table): void {
            $table->json('entry_rules')->nullable()->comment('On a main-grid gateway: the rules for passing, any one of which lets a character through ([{kind: flag, flag: slug}, ...]). NULL or empty is an open door. Unread on every other system.');
            $table->unsignedBigInteger('links_to_system_id')->nullable()->index()->comment('A gateway\'s other end: the gateway on the other grid it passes through to. NULL for every other system. Paired, so each end names the other.');
        });
        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('infiltrated')->default(0)->comment('An Infiltrate run against a gateway: how many very hard stealth checks in a row it has survived. Reset by a failed one; the run is won at cyberspace.infiltrate.turns. Zero on every other run.');
        });
        Schema::table('characters', function (Blueprint $table): void {
            $table->unsignedSmallInteger('resume_x')->nullable()->comment('Where a jack-in resumes on a subnet (grid_id says which): set by jacking out inside one, cleared by a flatline. NULL means the main portal.');
            $table->unsignedSmallInteger('resume_y')->nullable()->comment('See resume_x.');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('systems', fn (Blueprint $table) => $table->foreign('links_to_system_id')->references('id')->on('systems')->nullOnDelete());
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->widenAddresses('VARCHAR(10)', "'^([0-9A-F]{8}|[a-z]{2,5}:[0-9A-F]{4})$'");

        $in = "'".implode("','", SystemType::values())."'";

        foreach (['systems' => 'chk_systems_type', 'system_contacts' => 'chk_contacts_type'] as $table => $constraint) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK (`type` IN ({$in}))");
        }
    }

    /** Re-types every address column keeping its nullability and comment, and re-states the CHECKs on the new width. */
    private function widenAddresses(string $type, string $pattern): void
    {
        foreach (self::ADDRESS_CHECKS as $table => $constraint) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }

        foreach (self::ADDRESS_TABLES as $table) {
            $column = DB::selectOne(
                'SELECT IS_NULLABLE AS nullable, COLUMN_COMMENT AS comment FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, 'address'],
            );

            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `address` %s %s COMMENT %s',
                $table, $type, $column->nullable === 'YES' ? 'NULL' : 'NOT NULL', DB::getPdo()->quote($column->comment),
            ));
        }

        foreach (self::ADDRESS_CHECKS as $table => $constraint) {
            $null = $table === 'bookmarks' ? '`address` IS NULL OR ' : '';
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$null}REGEXP_LIKE(`address`, {$pattern}, 'c'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (DB::table('systems')->where('grid_id', '!=', 1)->exists()) {
                throw new RuntimeException('There are systems on a subnet; remove them before folding the grids back into one.');
            }

            $in = "'".implode("','", array_diff(SystemType::values(), ['gateway']))."'";

            foreach (['systems' => 'chk_systems_type', 'system_contacts' => 'chk_contacts_type'] as $table => $constraint) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK (`type` IN ({$in}))");
            }

            $this->widenAddresses('CHAR(8)', "'^[0-9A-F]{8}$'");
        }

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('systems', fn (Blueprint $table) => $table->dropForeign(['links_to_system_id']));
        }

        Schema::table('systems', function (Blueprint $table): void {
            $table->dropIndex(['links_to_system_id']);
            $table->dropColumn(['links_to_system_id', 'entry_rules']);
        });
        Schema::table('characters', fn (Blueprint $table) => $table->dropColumn(['resume_x', 'resume_y']));
        Schema::table('runs', fn (Blueprint $table) => $table->dropColumn('infiltrated'));

        // The foreign keys first: they stand on the position index while it is being swapped.
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['systems', 'characters'] as $name) {
                Schema::table($name, fn (Blueprint $table) => $table->dropForeign(['grid_id']));
            }
        }

        Schema::table('character_terrain', function (Blueprint $table): void {
            $table->unique(['character_id', 'chunk_x', 'chunk_y'], 'uq_terrain_chunk');
            $table->dropUnique('uq_terrain_grid_chunk');
        });
        Schema::table('system_contacts', function (Blueprint $table): void {
            $table->unique(['character_id', 'x', 'y'], 'uq_contact_position');
            $table->dropUnique('uq_contact_grid_position');
        });
        Schema::table('bookmarks', function (Blueprint $table): void {
            $table->dropUnique('uq_bookmark_position');
            $table->unique(['character_id', 'x', 'y'], 'uq_bookmark_position');
        });
        Schema::table('systems', function (Blueprint $table): void {
            $table->dropUnique('uq_systems_position');
            $table->unique(['x', 'y'], 'uq_systems_position');
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('grid_id'));
        }

        Schema::dropIfExists('grids');
    }
};
