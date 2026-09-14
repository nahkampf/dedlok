<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A machine is named for what it is and who runs it.
 *
 * *Fast Forge*, *Cold Vault 041* — the old names read well and said nothing.
 * They came out of a word bag, so two machines owned by rival corporations
 * were as alike as two owned by the same one, and the first thing a player
 * wants to know about a node was the one thing its name would not tell them.
 * They now read *Data silo - Anansi Data Weaving 041*: a type, an owner, and a
 * serial to tell it from its siblings.
 *
 * THE NAME IS NO LONGER A STRING ON THE ROW, and that is the substance of this
 * migration rather than a side effect. Both halves of it already were on the
 * row — `type` has always been there and `owner_id` arrived with the corps —
 * so storing the sentence they spell would be storing the same facts twice,
 * free to disagree with them the first time a corp is renamed. What is stored
 * instead is the one part that is nowhere else: the designation.
 *
 * `name` therefore becomes NULLABLE and changes meaning. It is now an AUTHORED
 * name, which is the exception rather than the rule: the portal has one, and
 * event, mission and player-owned systems will arrive carrying theirs. NULL
 * means *named for its owner*, the way `items.board_layout` being NULL means
 * *arranged by the ring* — the derived answer is the ordinary one and the
 * column is the override.
 *
 * EVERY GENERATED SYSTEM GETS A DESIGNATION, where the word bag rolled one on
 * 60% of names. It has to: 121 corps by three types is 363 combinations
 * against nearly three thousand machines, so without a serial a name would
 * identify about eight systems at once. With one it identifies about one.
 *
 * The 2 867 systems already standing are renamed IN PLACE rather than
 * re-seeded. Re-seeding was the plan while an owner still had to be invented
 * for every row; now that they have owners, a re-seed would burn every address
 * in the world to change a naming rule — and an address is the thing a
 * bookmark and a contact point at.
 *
 * Contacts are left exactly as they are. A contact is a memory and is allowed
 * to be out of date, which is the whole reason it carries a name of its own
 * rather than a pointer; the next sweep over that position rewrites it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedSmallInteger('designation')->nullable()->after('name')
                ->comment('Serial in the name — the 041 of "Data silo - Anansi 041". NULL on a system with an authored name. The only part of a generated name that is not derived from another column.');

            $table->string('name', 64)->nullable()->change();
        });

        $this->comment('NULL', 'An AUTHORED name, overriding the one composed from the type and the owner. NULL is the ordinary state and means composed - see App\Cyberspace\SystemNamer.');

        // Everything the world generated. The portal is the one system with a
        // name of its own, and it keeps it.
        DB::table('systems')
            ->where('type', '!=', 'portal')
            ->update([
                'name' => null,
                'designation' => DB::raw($this->randomDesignation()),
            ]);
    }

    public function down(): void
    {
        // The word bag is gone, so a name cannot be put back as it was. What
        // goes into the column is what the screen would have shown: the same
        // sentence, spelled out once and stored.
        $corps = DB::table('corps')->pluck('name', 'id');

        DB::table('systems')->whereNull('name')->orderBy('id')->chunkById(500, function ($systems) use ($corps): void {
            foreach ($systems as $system) {
                $owner = $system->owner_id === null ? null : $corps[$system->owner_id] ?? null;

                DB::table('systems')->where('id', $system->id)->update([
                    'name' => mb_substr(trim(sprintf(
                        '%s%s %s',
                        ucfirst(str_replace('_', ' ', $system->type)),
                        $owner === null ? '' : ' - '.$owner,
                        $system->designation === null ? '' : str_pad((string) $system->designation, 3, '0', STR_PAD_LEFT),
                    )), 0, 64),
                ]);
            }
        });

        Schema::table('systems', function (Blueprint $table): void {
            $table->dropColumn('designation');
            $table->string('name', 64)->nullable(false)->change();
        });

        $this->comment('NOT NULL', 'What the machine calls itself. Generated at instantiation from the word repository in config/cyberspace.php; event and mission systems will carry authored names.');
    }

    /**
     * A designation per row, rolled by the database so this is one statement
     * rather than three thousand.
     */
    private function randomDesignation(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? '(ABS(RANDOM()) % 999) + 1'
            : 'FLOOR(1 + RAND() * 999)';
    }

    /**
     * `->change()` rewrites the whole column definition, so the comment has to
     * be restated afterwards or it is quietly dropped. No-op on SQLite, like
     * every other comment in this schema.
     */
    private function comment(string $null, string $comment): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE systems MODIFY COLUMN name VARCHAR(64) %s COMMENT %s',
            $null,
            DB::getPdo()->quote($comment),
        ));
    }
};
