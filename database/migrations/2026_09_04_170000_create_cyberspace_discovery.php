<?php

declare(strict_types=1);

use App\Cyberspace\Chunk;
use App\Cyberspace\SystemType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a character has seen. TWO RECORDS, NOT ONE.
 *
 * Discovery used to be one question and relocation makes it two, because the
 * two halves of "I have been here" behave differently once the world moves:
 *
 *  - TERRAIN — *I have looked at this position.* A position never moves and
 *    never changes, so this can never go stale and can never be wrong. It is
 *    the half that is permanent.
 *  - CONTACT — *I know this machine: its name, its address, what it looked
 *    like.* This dies when the system relocates or burns, and that death is
 *    the mechanic rather than a fault: jumping to a bookmark and finding
 *    nothing there is the world having moved on.
 *
 * Storing them as one record would mean one of the two lying. A cell that
 * remembered "a data silo is here" would keep saying so after the silo left,
 * and a record that was cleared when the silo left would take the fact that
 * you had SWEPT that position with it.
 *
 * They are stored differently as well as separately, and for the same reason
 * the grid is arithmetic rather than rows — see docs/CYBERSPACE.md, *How the grid
 * is stored*. Both shapes have to survive the grid becoming 2048 x 2048.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Terrain: a bitmap per chunk, and no row at all for a chunk nobody
         * has entered.
         *
         * A row per swept position is the version that does not survive the
         * grid getting bigger — 4.2 million rows for one thorough player. A
         * bit per position is 512 bytes per chunk at either size, so a
         * character who has explored five chunks holds 2.5 KB and one who has
         * explored nothing holds nothing.
         *
         * The blob is fixed width and always fully allocated: a chunk row
         * exists because the character has been in that chunk, and the bits
         * inside it say where. There is no partial blob and no growing one.
         */
        Schema::create('character_terrain', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('character_id')->constrained()->cascadeOnDelete();

            // Chunk coordinates, not positions. 32 x 32 chunks at full size,
            // so a byte would do — smallInteger is two, and the difference at
            // a thousand rows per character is not worth a column nobody can
            // widen later without a migration.
            $table->unsignedSmallInteger('chunk_x');
            $table->unsignedSmallInteger('chunk_y');

            $table->binary('bits');

            $table->timestamps();

            // One blob per chunk per character. Two would be two answers about
            // the same ground, and the loser would be whichever was read
            // second.
            $table->unique(['character_id', 'chunk_x', 'chunk_y'], 'uq_terrain_chunk');
        });

        /*
         * Contact: rows, because there are few of them and they carry content.
         *
         * THERE IS DELIBERATELY NO FOREIGN KEY TO `systems`. A contact is a
         * SIGHTING — what stood here, when you looked — and it has to outlive
         * the machine it describes, because a machine that relocated or burned
         * is exactly the case the record exists to be wrong about. A foreign
         * key would cascade the memory away at the moment it becomes
         * interesting.
         *
         * It is keyed by POSITION rather than by address for the same reason
         * the map is a memory rather than a feed: what a player has is the
         * last thing they saw at each place. Looking again overwrites it.
         */
        Schema::create('system_contacts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('character_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('x');
            $table->unsignedSmallInteger('y');

            // The snapshot. Every one of these is what the system looked like
            // at `seen_at`, never what it looks like now — nothing here is
            // refreshed except by looking again.
            $table->char('address', 8);
            $table->string('name', 64);
            $table->string('type', 16);

            // The moment, and the only clock this row has. `timestamps()`
            // would put an `updated_at` beside it saying the same thing, free
            // to disagree the day something touches the row without having
            // looked.
            $table->timestamp('seen_at');

            // One sighting per position per character. The unique key is also
            // how a window is read back — WHERE character_id = ? AND x BETWEEN
            // … AND y BETWEEN … — so the index the rule needs is the index the
            // map wanted anyway.
            $table->unique(['character_id', 'x', 'y'], 'uq_contact_position');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // The blob is exactly one chunk wide, always. A short one would read
        // as a chunk whose far corner is unswept and a long one would carry
        // bits belonging to nowhere; both are silent, and both are the kind of
        // thing that is discovered a month later.
        DB::statement(
            'ALTER TABLE `character_terrain` ADD CONSTRAINT `chk_terrain_width` CHECK ('.
            'OCTET_LENGTH(`bits`) = '.Chunk::BYTES.')'
        );

        // The same vocabulary the systems table states, said again here
        // because this table cannot borrow it through a foreign key it
        // deliberately does not have.
        DB::statement(
            'ALTER TABLE `system_contacts` ADD CONSTRAINT `chk_contacts_type` CHECK ('.
            "`type` IN ('".implode("','", SystemType::values())."'))"
        );

        DB::statement(
            'ALTER TABLE `system_contacts` ADD CONSTRAINT `chk_contacts_address` CHECK ('.
            "`address` REGEXP '^[0-9A-F]{8}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('system_contacts');
        Schema::dropIfExists('character_terrain');
    }
};
