<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The grid: somewhere to go, and the machines standing in it.
 *
 * Phase 3 of CYBERSPACE.md's build order — a walkable, empty world. There is no
 * intrusion here and nothing to steal; what this table carries is the half a
 * player can WALK PAST. Grade, operating system, IDS, ICE, tracer, owner and
 * contents are phase 4, and they arrive as columns on this table rather than as
 * a second one, because they are facts about the same machine.
 *
 * A POSITION AND A SYSTEM ARE TWO DIFFERENT THINGS, and that is the single most
 * important thing this schema has to say. A position is a coordinate: it never
 * moves and never changes, and there is therefore no table of positions — the
 * grid is arithmetic, not rows. A system is a machine with an identity, and its
 * address and its coordinates are attributes it CURRENTLY HAS. Both change when
 * it relocates, and the row survives that with its id intact. Anything written
 * as though a cell "is" a system breaks the first time one moves, and systems
 * move constantly.
 *
 * Hence the two unique keys, which say exactly that and nothing more:
 *
 *  - `address` is unique because two machines cannot answer to one address.
 *    It is 8 hex characters, random, and carries no information — a position
 *    cannot be read out of one. Addresses do NOT survive: a burned system takes
 *    its address out of existence and a relocated one comes back with a new
 *    one, which is what keeps a traded address a fact about right now rather
 *    than a permanent name.
 *  - `(x, y)` is unique because a position holds at most one system. It is NOT
 *    a primary key and must never become one: it is the attribute that moves.
 *
 * Roughly 30% of positions hold nothing at all, and no row says so. Empty is
 * the absence of a system, never a row describing emptiness — 4 096 rows of
 * nothing today would be 4.2 million rows of nothing at full size, which is the
 * version that does not survive the grid getting bigger.
 */
return new class extends Migration
{
    /**
     * The portal is the one system that is none of the things a system is: it
     * cannot be scanned, intruded, burned or owned. It is a type rather than a
     * boolean, because the other three are types too and "is it the portal"
     * would be a second question about the same column.
     */
    private const TYPES = ['portal', 'silo', 'compute', 'relay'];

    public function up(): void
    {
        Schema::create('systems', function (Blueprint $table): void {
            $table->id();

            // Fixed width and unique: 8 hex characters, upper case. char()
            // rather than string() because every one of them is exactly 8, and
            // a 2048-grid holds three million of them.
            $table->char('address', 8)->unique();

            // unsignedSmallInteger holds 65 535, which covers 2048 with room to
            // spare and costs two bytes instead of four.
            $table->unsignedSmallInteger('x');
            $table->unsignedSmallInteger('y');

            $table->string('type', 16);

            // The name is ON THE ROW, like an item's, because it is the string
            // a player reads and it belongs to the thing that has a row. Most
            // are generated from config/cyberspace.php's word repository at
            // instantiation; event and mission systems will carry authored
            // ones; player nodes will carry their owner's.
            $table->string('name', 64);

            $table->timestamps();

            // A position holds at most one system. The pair is also how the
            // viewing window is read — WHERE x BETWEEN … AND y BETWEEN … — so
            // the index it needs is the one the rule already required.
            $table->unique(['x', 'y'], 'uq_systems_position');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE `systems` ADD CONSTRAINT `chk_systems_type` CHECK ('.
            "`type` IN ('".implode("','", self::TYPES)."'))"
        );

        // The address vocabulary, said once in SQL: eight characters, each of
        // them a hex digit in upper case. A lower-case address would resolve
        // through one lookup and fail through another, and the first time that
        // happens is the day somebody types one in by hand.
        DB::statement(
            'ALTER TABLE `systems` ADD CONSTRAINT `chk_systems_address` CHECK ('.
            "`address` REGEXP '^[0-9A-F]{8}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('systems');
    }
};
