<?php

declare(strict_types=1);

use App\Cyberspace\BookmarkKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a player gets back to somewhere. TWO KINDS, and they behave differently
 * for the same reason terrain and contact do.
 *
 *  - A WAYPOINT is a position. It survives everything, because a position
 *    cannot move or change, and it is how you return to the frontier.
 *  - A SYSTEM BOOKMARK is an address. It dies with the system that wore it,
 *    and arriving nowhere is an ordinary outcome rather than a fault.
 *
 * ONE TABLE WITH A CHECK, not two tables, because they are the same act from
 * the player's side — a saved way back — and every screen that lists one wants
 * to list the other beside it. The shape is `archetype_perks.chk_perk_shape`'s:
 * a `kind` naming which half of the row is filled in, and a constraint that the
 * other half is empty. A row cannot be half a waypoint.
 *
 * THE ASYMMETRY IS THE WHOLE PACING OF EXPLORATION, and it is worth stating
 * plainly because it looks like an inconsistency until it is:
 *
 *  - **A waypoint may only be set on ground the character has SWEPT.** If a
 *    position could be bookmarked sight unseen, coordinates would be tradeable
 *    and the map would be solved by whoever posted a list of them. The terrain
 *    bitmap is the gate.
 *  - **A system bookmark may be set on ANY address**, seen or not. Trading
 *    addresses is fine and expected — the design says so outright — and it is
 *    harmless precisely because an address carries no position: it tells you
 *    where to arrive and nothing about where that is or what is on the way.
 *
 * So what is tradeable is a door, and what is not tradeable is a map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table): void {
            $table->comment('Saved ways back. A waypoint is a position and survives everything; a system bookmark is an address and dies with the system wearing it.');

            $table->id();

            // ->comment() BEFORE ->constrained(): the latter returns a
            // ForeignKeyDefinition, so a comment chained after it is set on
            // the key and never reaches the column. Silently.
            $table->foreignId('character_id')
                ->comment('Whose bookmark this is. Bookmarks are private and are never read by another player.')
                ->constrained()->cascadeOnDelete();

            $table->string('kind', 16)
                ->comment('Which half of this row is filled in: waypoint (a position) or system (an address). A CHECK keeps the other half empty.');

            $table->unsignedSmallInteger('x')->nullable()
                ->comment('Waypoint only. The position saved, which never moves and never goes stale. NULL on a system bookmark.');
            $table->unsignedSmallInteger('y')->nullable()
                ->comment('Waypoint only. NULL on a system bookmark.');

            $table->char('address', 8)->nullable()
                ->comment('System bookmark only. The address saved, which may stop resolving at any time - that is the world moving on, not a fault. NULL on a waypoint.');

            $table->string('label', 48)->nullable()
                ->comment('What the player called it. NULL falls back to the remembered system name, or to the coordinates for a waypoint.');

            $table->timestamps();

            // One waypoint per position and one bookmark per address, per
            // character. Both are expressed as unique keys over nullable
            // columns, which MySQL and SQLite alike treat as distinct when
            // NULL — so a table of system bookmarks does not collide on
            // (character, NULL, NULL). The same device `stack_key` uses.
            $table->unique(['character_id', 'x', 'y'], 'uq_bookmark_position');
            $table->unique(['character_id', 'address'], 'uq_bookmark_address');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // A row is one kind or the other, wholly. Half a waypoint would be a
        // row every screen has to defend against.
        DB::statement(
            'ALTER TABLE `bookmarks` ADD CONSTRAINT `chk_bookmark_shape` CHECK ('.
            "(`kind` = '".BookmarkKind::Waypoint->value."' AND `x` IS NOT NULL AND `y` IS NOT NULL AND `address` IS NULL) OR ".
            "(`kind` = '".BookmarkKind::System->value."' AND `address` IS NOT NULL AND `x` IS NULL AND `y` IS NULL))"
        );

        DB::statement(
            'ALTER TABLE `bookmarks` ADD CONSTRAINT `chk_bookmark_address` CHECK ('.
            "`address` IS NULL OR `address` REGEXP '^[0-9A-F]{8}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
