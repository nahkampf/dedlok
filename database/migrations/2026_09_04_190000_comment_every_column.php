<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What every column means, said in the database itself.
 *
 * A schema explains its shape and never its intent. `items.container` is a
 * varchar(20) whatever you read it with, and nothing in the type says it names
 * one of two inventories and is a property of the definition rather than a
 * player choice. That knowledge lives in migrations, in CLAUDE.md and in the
 * models — none of which a GUI, a `SHOW CREATE TABLE`, or somebody with a
 * mysql prompt at three in the morning is looking at.
 *
 * So it is written down where the column is. One line each, saying what the
 * field MEANS rather than restating its type, and flagging the handful that
 * are easy to misread — the ones that are bases rather than values, the ones
 * whose NULL is an ordinary state, and the ones nothing reads yet.
 *
 * MYSQL ONLY. SQLite has no column comments at all, so this is a no-op there
 * and the test suite neither gains nor loses anything by it — the same shape as
 * every CHECK constraint in this schema.
 *
 * WHY THE DEFINITIONS ARE READ BACK RATHER THAN WRITTEN OUT. MySQL cannot
 * change a comment on its own: `ALTER TABLE ... MODIFY COLUMN` is the only
 * door, and it replaces the whole definition. Hand-writing 257 of those is 257
 * chances to quietly widen a column, drop a default or lose a NOT NULL, and
 * every one of those failures is silent. So each definition is read out of
 * `information_schema` and re-emitted exactly as it already is, with only the
 * comment added. The migration cannot change a type because it never states
 * one.
 *
 * KEEPING IT CURRENT IS MANUAL. A column added after this migration has no
 * comment until somebody writes one, and nothing enforces that. Add the comment
 * in the migration that adds the column — `$table->string('x')->comment('…')`
 * works on MySQL and is ignored on SQLite — rather than by editing this file,
 * which is history.
 */
return new class extends Migration
{
    /**
     * Columns that mean the same thing everywhere, so the map below only has
     * to carry what is particular to a table.
     */
    private const GENERIC = [
        'id' => 'Primary key.',
        'created_at' => 'When this row was created.',
        'updated_at' => 'When this row was last changed.',
    ];

    /**
     * Every column, and what it is for.
     *
     * @return array<string, array<string, string>>
     */
    private function comments(): array
    {
        return [
            // ---- the game: characters and what they know ------------------

            'characters' => [
                'user_id' => 'The account this character belongs to. Unique: one character per user, and two middlewares enforce it.',
                'handle' => 'The name other players see. Unique across the game.',
                'intellect' => 'Attribute, 0-100. A BASE: what the character has before skills, implants and drugs are resolved on top.',
                'memory' => 'Attribute, 0-100. A base, like every attribute here.',
                'perception' => 'Attribute, 0-100. A base, like every attribute here.',
                'reflex' => 'Attribute, 0-100. A base, like every attribute here.',
                'focus' => 'Attribute, 0-100. A base, like every attribute here.',
                'social' => 'Attribute, 0-100. A base, like every attribute here.',
                'meat' => 'Attribute, 0-100. Bodily toughness. A base, like every attribute here.',
                'motor' => 'Attribute, 0-100. A base, like every attribute here.',
                'senses' => 'Attribute, 0-100. A base, like every attribute here.',
                'muscle' => 'Attribute, 0-100. A base, like every attribute here.',
                'archetype' => 'The role chosen at creation. Permanent, and a foreign key, which is why a withdrawn archetype is never deleted.',
                'current_hitpoints' => 'Hit points right now. A STORED resource whose maximum is derived: it clamps down when the ceiling drops, never scales.',
                'current_synaptic_integrity' => 'Synaptic Integrity right now. Stored like hit points; reaching 0 is what ICE is trying to do to you.',
                'creds' => 'Money. Stored and mutable like a resource, but with no derived maximum to clamp against, which is why it is neither a resource nor a stat.',
                'equipped_deck_id' => 'The inventory row of the cyberdeck being carried. NULL is the ordinary state. This is NOT jacking in; see grid_x.',
                'grid_x' => 'Position in cyberspace. NULL together with grid_y means not jacked in, which is the ordinary state; a CHECK stops half a position existing.',
                'grid_y' => 'Position in cyberspace. Set and cleared only by the navigator, never from a request.',
            ],

            'character_skills' => [
                'character_id' => 'Whose skill this is.',
                'skill_id' => 'Which skill.',
                'skill_points' => 'Points accumulated by training. Must agree with attained_level.',
                'attained_level' => 'Level reached. 0 is a real state: injected and untrained.',
            ],

            'character_terrain' => [
                'character_id' => 'Whose map this is.',
                'chunk_x' => 'Which 64x64 chunk of the grid, horizontally. A chunk row exists because the character has been in it.',
                'chunk_y' => 'Which 64x64 chunk of the grid, vertically.',
                'bits' => 'A 512-byte bitmap, one bit per position in the chunk, set when the position has been looked at. Terrain never goes stale, because a position cannot move.',
            ],

            'training_queue' => [
                'character_id' => 'Whose queue this entry is in.',
                'skill_id' => 'The skill being trained. The character must already have injected its book.',
                'target_level' => 'The level this entry trains towards.',
                'position' => 'Order in the queue. Contiguous from 1; a gap is a fault the validator reports.',
                'last_deposited_at' => 'When training points were last paid into this entry. The heartbeat reads it to work out what is owed.',
                'sp_per_minute' => 'The rate this entry earns at, resolved when it was queued.',
            ],

            // ---- items, and what a character is carrying ------------------

            'items' => [
                'slug' => 'Stable machine name. Permanent: rows and code reference it.',
                'name' => 'What the player sees it called.',
                'flavor' => 'Flavour text on the detail card. Says nothing mechanical.',
                'container' => 'Which of the two inventories it lives in: physical or data. A property of the DEFINITION, never a player choice, so a row cannot disagree with it.',
                'tier' => 'What the item IS: Tier One or Tier Two. Colours the card and carries no mechanics. Not rarity.',
                'rarity' => 'How often the market stocks one: common, uncommon, rare, ultra_rare. Not tier, and free to disagree with it.',
                'stackable' => 'Whether two of these share one inventory row. False for containers and deck hardware, which must be pointable at individually.',
                'use_op' => 'What using it does, or NULL for an item that cannot be used.',
                'use_skill_id' => 'For a skillbook, the skill it teaches. Unique, because exactly one book exists per skill and that is what makes a book nameless.',
                'weight' => 'Stored and currently unenforced. Neither inventory has a weight limit.',
                'size' => 'What this costs against a container capacity. Enforced.',
                'capacity' => 'How much this can hold. Greater than 0 makes the item a container.',
                'accepts' => 'Which inventory kind a container takes. The memory shard is a physical object that accepts data.',
                'hardware' => 'Which piece of deck hardware this is, or NULL for the rest of the catalogue. The ONLY place the kind is written down.',
                'board_layout' => 'JSON map from socket type to [x, y] percentages, positioning nodes on the deck board. NULL means unarranged, not missing: the automatic ring draws it.',
                'base_cost' => 'Reference price. Nothing spends it yet.',
                'on_market' => 'Whether the market may stock it at all. Unlisting is how content is retired, since deletes are real.',
                'seed_quantity' => 'Target shelf size per restock pass. Defaults to 0, so nothing reaches the market by being forgotten.',
            ],

            'inventory_items' => [
                'character_id' => 'Who is carrying it.',
                'item_id' => 'Which item definition this is a copy of.',
                'parent_id' => 'The container row this sits inside. NULL means loose, which is what every listing of an inventory means. Depth is capped at one.',
                'running_on_deck_id' => 'For a program, the deck it is running on. NULL means not running.',
                'started_at' => 'When this program started running.',
                'quantity' => 'How many are in this stack. Always 1 for anything unstackable.',
                'stack_key' => 'Derived key that makes two piles of the same thing in the same place collide, and two unstackable objects stay separate. NULL for unstackable. Set by the model on every save, never by hand.',
            ],

            // ---- cyberdecks: the sockets and what goes in them ------------

            'deck_slots' => [
                'item_id' => 'The cyberdeck presenting this socket.',
                'kind' => 'What kind of socket it is. One ROW per socket, not a count per kind, because a fitted module has to point at the place it went into.',
                'variant' => 'Which connector it takes. A universal socket accepts either variant; no module is ever universal.',
                'position' => 'Ordering among the deck sockets.',
            ],

            'deck_modules' => [
                'item_id' => 'The item this hardware data belongs to.',
                'variant' => 'The connector this module presents, matched against a socket variant.',
                'power_draw' => 'Watts drawn. A BASE, resolved through the stat resolver rather than read off the row.',
                'thermal_load' => 'Thermal units generated per turn. Every module makes some.',
                'qflops' => 'Compute offered, from a CPU or an NPU. At most one measure per module is set; a CHECK enforces it.',
                'quantabits' => 'Memory offered, from a RAM stick.',
                'bandwidth' => 'Link speed offered, from a NIC.',
                'watts' => 'Power supplied, from a PSU. A supply gives power and draws none.',
                'cooling' => 'Thermal units removed, from a radiator. A radiator cools and heats none, but it still draws.',
                'storage' => 'Storage offered, where files and unloaded programs sit. Not memory.',
                'thermal_warning' => 'The DECK thermal load at which this module is at risk. NULL means the part does not care.',
                'thermal_limit' => 'The DECK thermal load at which this module fails. NULL together with the warning. A deck tolerance is its weakest part, not the sum of its parts.',
            ],

            'deck_fittings' => [
                'deck_id' => 'The inventory row of the deck: WHICH deck.',
                'deck_slot_id' => 'The definition row of the socket: WHICH socket. Only the pair is a place, since a slot alone is shared by every copy of that deck in the game.',
                'module_id' => 'The inventory row of the module: WHAT went in. Unique, so one stick cannot be fitted into two decks.',
            ],

            'programs' => [
                'item_id' => 'The item this program data belongs to.',
                'kind' => 'What the program is for. Nothing reads it as a gate yet; that waits on the run simulator.',
                'qflops_cost' => 'Compute spent while it RUNS, against the deck total.',
                'memory_cost' => 'Memory spent while it runs.',
                'storage_size' => 'Storage occupied simply by owning it, paid whether it runs or not.',
                'background_process' => 'Whether it may run outside a run. Deliberately not constrained against the kind.',
                'modifiers' => 'JSON stat modifiers applied while it is running, and only while it is running.',
            ],

            // ---- cyberspace ------------------------------------------------

            'systems' => [
                'address' => 'Eight hex characters, unique, random, and MEANINGLESS: a position cannot be read out of one. Addresses do not survive - a relocated system comes back wearing a new one.',
                'x' => 'Where it is standing right now. An attribute it currently HAS, not what it is: it changes when the system relocates.',
                'y' => 'Where it is standing right now. Unique with x, because a position holds at most one system.',
                'type' => 'What kind of machine it is: portal, silo, compute or relay. The portal is a type rather than a flag, and is the one system nothing may be done to.',
                'name' => 'What the player sees it called. Generated from a word repository at instantiation and written here, so a name cannot change under a bookmark.',
            ],

            'system_contacts' => [
                'character_id' => 'Whose memory this is.',
                'x' => 'Where the sighting was. Keyed by POSITION, because a contact is the last thing you saw at a place.',
                'y' => 'Where the sighting was.',
                'address' => 'The address the machine wore when it was seen. There is deliberately NO foreign key to systems: a contact must outlive the machine it describes.',
                'name' => 'What it called itself when it was seen. A snapshot, never refreshed except by looking again.',
                'type' => 'What kind of machine it looked like when it was seen.',
                'seen_at' => 'When it was looked at. The only clock on this row, which is why there is no updated_at beside it.',
            ],

            // ---- skills ----------------------------------------------------

            'skills' => [
                'slug' => 'Stable machine name. Permanent: rows and code reference it.',
                'title' => 'What the player sees it called.',
                'description' => 'What the skill is, on the skill tree.',
                'category' => 'How the skill tree groups it.',
                'rank' => 'How advanced the skill is. Drives what its book costs and how the market stocks it.',
                'primary_attribute' => 'The attribute that most governs checks against this skill.',
                'secondary_attribute' => 'The second attribute governing it. Must differ from the primary.',
                'base_cost' => 'Reference price, kept in step with the price of the skill book.',
                'on_market' => 'Whether the skill book may be stocked, kept in step with the book.',
            ],

            'skill_prerequisites' => [
                'skill_id' => 'The skill that has the requirement.',
                'required_skill_id' => 'The skill it requires. Cycles are refused: nothing is wrong with either row alone, but together they are two skills nobody can learn.',
                'required_level' => 'How far the required skill must be trained.',
            ],

            // ---- archetypes -------------------------------------------------

            'archetypes' => [
                'slug' => 'Stable machine name. Permanent, and prohibited from being changed on update.',
                'name' => 'What the player sees the role called.',
                'role' => 'The short line under the name on the selection card.',
                'description' => 'The longer blurb on the selection card.',
                'starting_creds' => 'Money handed out at creation. Cash is one number, which is why it is a column while skills and items are tables.',
                'playable' => 'Whether a new character may choose this. False is WITHDRAWN, not deleted: existing characters still have to resolve a name and a perk set.',
            ],

            'archetype_perks' => [
                'archetype_id' => 'The archetype this perk belongs to.',
                'key' => 'Stable identifier for the perk within its archetype.',
                'modifiers' => 'JSON stat modifiers the perk applies. NULL for a perk that is still only described rather than resolved.',
                'display_modifier' => 'A signed number to SHOW on the card when the perk has no real modifiers yet. NULL whenever modifiers carries the truth; a CHECK stops both being set.',
                'display_unit' => 'The unit for display_modifier, such as percent. Null and set together with it.',
                'label' => 'What the perk is called on the card. NULL means it borrows an attribute name, so renaming an attribute stays one edit.',
                'position' => 'Ordering of the perks on the card.',
            ],

            'archetype_skills' => [
                'archetype_id' => 'The archetype granting the skill.',
                'skill_id' => 'The skill granted at creation, with no book spent because none is owned.',
                'level' => 'The level it is granted at. 0 is the ordinary grant, meaning injected and untrained; above 0 every prerequisite must be granted too.',
            ],

            'archetype_items' => [
                'archetype_id' => 'The archetype granting the item.',
                'item_id' => 'The item granted at creation.',
                'quantity' => 'How many. An unstackable item becomes one inventory row per copy. Grants always land loose, never inside a container.',
            ],

            // ---- the market -------------------------------------------------

            'market_stock' => [
                'item_id' => 'What is on the shelf.',
                'quantity' => 'How many are on it. A MISSING ROW is a shelf of zero rather than an error; rows are created by the first delivery that lands.',
                'last_seeded_at' => 'When a restock pass last delivered this item.',
            ],

            'market_purchases' => [
                'character_id' => 'Who bought it. Nullable: a deleted character leaves their purchases behind, because the demand they represent was real.',
                'item_id' => 'What was bought.',
                'quantity' => 'How many were bought.',
                'unit_price' => 'What each one cost at the time.',
                'purchased_at' => 'The moment of the purchase. This table is DEMAND, not just a receipt: restocking sums a window of it. Written once and never updated, which is why there are no timestamps.',
            ],

            // ---- accounts and framework tables --------------------------------

            'users' => [
                'email' => 'Sign-in address. Unique.',
                'email_verified_at' => 'When the address was confirmed. NULL means unverified.',
                'password' => 'Hashed password. Never a plain one.',
                'is_admin' => 'The whole authorisation model. Outside the fillable list deliberately: there is no HTTP path to it, and an artisan command is the only writer.',
                'two_factor_secret' => 'Encrypted TOTP secret. The columns exist; the feature is switched off until its screens are written.',
                'two_factor_recovery_codes' => 'Encrypted single-use recovery codes.',
                'two_factor_confirmed_at' => 'When two-factor was confirmed. NULL means not in use.',
                'remember_token' => 'Token behind the stay-signed-in cookie.',
            ],

            'settings' => [
                'key' => 'The setting name, and the primary key.',
                'value' => 'The stored value, as text.',
                'category' => 'How the admin screen groups the setting.',
            ],

            'suggest_hacker_handles' => [
                'handle' => 'A handle offered to players who cannot think of one.',
                'in_use' => 'Whether it has been taken, so the pool stops offering it.',
            ],

            'passkeys' => [
                'user_id' => 'Whose passkey this is.',
                'name' => 'What the user called this credential.',
                'credential_id' => 'The WebAuthn credential id.',
                'credential' => 'The serialised public-key credential.',
                'last_used_at' => 'When it was last used to sign in.',
            ],

            'sessions' => [
                'id' => 'Session id, and the primary key.',
                'user_id' => 'Who the session belongs to. NULL for a signed-out visitor.',
                'ip_address' => 'Where the request came from.',
                'user_agent' => 'What the browser said it was.',
                'payload' => 'The serialised session data.',
                'last_activity' => 'Unix timestamp of the last request, used to expire the session.',
            ],

            'password_reset_tokens' => [
                'email' => 'The address a reset was asked for, and the primary key.',
                'token' => 'Hashed reset token.',
                'created_at' => 'When it was issued. Reset links expire against this.',
            ],

            'cache' => [
                'key' => 'Cache key, and the primary key.',
                'value' => 'The serialised cached value.',
                'expiration' => 'Unix timestamp when the entry goes stale.',
            ],

            'cache_locks' => [
                'key' => 'Name of the lock, and the primary key.',
                'owner' => 'Token identifying who holds it.',
                'expiration' => 'Unix timestamp when the lock lapses on its own.',
            ],

            'jobs' => [
                'queue' => 'Which queue the job is waiting on.',
                'payload' => 'The serialised job.',
                'attempts' => 'How many times it has been tried.',
                'reserved_at' => 'When a worker picked it up. NULL means nobody has.',
                'available_at' => 'When it becomes eligible to run.',
                'created_at' => 'When it was pushed.',
            ],

            'job_batches' => [
                'id' => 'Batch id, and the primary key.',
                'name' => 'What the batch was called.',
                'total_jobs' => 'How many jobs the batch started with.',
                'pending_jobs' => 'How many have still not finished.',
                'failed_jobs' => 'How many have failed.',
                'failed_job_ids' => 'The ids of the failed jobs.',
                'options' => 'Serialised batch options.',
                'cancelled_at' => 'When the batch was cancelled, if it was.',
                'created_at' => 'When the batch was created.',
                'finished_at' => 'When the last job finished.',
            ],

            'failed_jobs' => [
                'uuid' => 'Unique id for this failure.',
                'connection' => 'The queue connection it was running on.',
                'queue' => 'The queue it came from.',
                'payload' => 'The serialised job that failed.',
                'exception' => 'The exception and stack trace.',
                'failed_at' => 'When it failed.',
            ],

            'migrations' => [
                'migration' => 'The migration filename that has been run.',
                'batch' => 'Which batch it ran in, so a rollback can take a whole batch.',
            ],
        ];
    }

    /**
     * What each table is for.
     *
     * A table comment is a far simpler operation than a column one — MySQL
     * takes `ALTER TABLE ... COMMENT` on its own, with no definition to
     * restate — so there is nothing to be careful about here.
     *
     * @return array<string, string>
     */
    private function tableComments(): array
    {
        return [
            'characters' => "A player's character. One per user, and two middlewares are what make that true in practice rather than by convention.",
            'character_skills' => 'What a character knows. A row existing means the skill has been INJECTED from its book; level 0 is a real state, meaning known and untrained.',
            'character_terrain' => 'Where each character has looked, as a bitmap per 64x64 chunk of the grid. One of the two halves of discovery, and the half that never goes stale.',
            'system_contacts' => 'The last thing each character saw at each position. The other half of discovery: a sighting rather than a pointer, so it outlives the machine it describes.',
            'systems' => 'The machines standing on the cyberspace grid. Address and coordinates are attributes a system currently HAS, not what it is - both change when it relocates.',
            'training_queue' => 'What each character is training, and in what order. Contiguous positions from 1.',

            'items' => 'Every item definition in the game. Content lives in the database rather than config, and the admin editor is what authors it - a seeder that wrote items would be a second source that wins on re-seed.',
            'inventory_items' => 'What each character is carrying. One row per (character, item, place) for stackable things, and one row per object for everything that cannot stack.',
            'programs' => 'The software half of an item: what it costs to own, and what it costs to run.',

            'deck_slots' => 'The sockets a cyberdeck presents. One ROW per socket rather than a count per kind, because a fitted module has to point at the place it went into.',
            'deck_modules' => 'The numbers a piece of deck hardware carries. At most one measure per module: qFLOPS, quantabits, QB/s and watts do not convert into one another.',
            'deck_fittings' => 'Which module is in which socket of which deck. Three references, because three separate things have to be named and only the pair of deck and slot is a place.',

            'skills' => 'Every skill in the game, and the two attributes that govern checks against it.',
            'skill_prerequisites' => 'Which skills require which others, and how far. Cycles are refused: neither row is wrong alone, but together they are two skills nobody can learn.',

            'archetypes' => 'The roles a character can be. Rows arrive by migration and are WITHDRAWN rather than deleted, because characters.archetype is a foreign key into this table.',
            'archetype_perks' => 'What an archetype CHANGES about a character. Displayed on the selection card and edited nowhere: a live perk is a list of modifiers with an editor of its own.',
            'archetype_skills' => 'What an archetype hands a new character in skills. Granted rather than sold - no book is spent, because none is owned yet.',
            'archetype_items' => 'What an archetype hands a new character in items. Granted once, at creation; editing a loadout changes what the NEXT character gets and never reaches back.',

            'market_stock' => 'What is on the market shelf right now. A missing row is a shelf of zero rather than an error.',
            'market_purchases' => 'Every purchase, written once and never touched again. This is DEMAND rather than a pile of receipts: a restock pass sums a window of it to decide what to carry.',

            'users' => 'Accounts. The character is a separate row; this is only the sign-in.',
            'settings' => 'Runtime settings the admin screens edit.',
            'suggest_hacker_handles' => 'The pool of handles offered to players who cannot think of one.',
            'passkeys' => 'WebAuthn credentials. Migrated ahead of the feature, which stays off until its screens are written.',

            'sessions' => 'Laravel: active sessions.',
            'cache' => 'Laravel: the cache store.',
            'cache_locks' => 'Laravel: atomic locks held against cache keys.',
            'jobs' => 'Laravel: queued jobs waiting for a worker.',
            'job_batches' => 'Laravel: progress of batched jobs.',
            'failed_jobs' => 'Laravel: jobs that exhausted their attempts, kept with the exception that killed them.',
            'password_reset_tokens' => 'Laravel: outstanding password-reset tokens.',
            'migrations' => 'Laravel: which migrations have run, and in which batch.',
        ];
    }

    public function up(): void
    {
        $this->apply(fn (string $table, string $column): ?string => $this->commentFor($table, $column));
    }

    /**
     * Take them back off.
     *
     * A comment is not state anything reads, so this could reasonably do
     * nothing at all — but a migration that cannot be rolled back is one nobody
     * dares run, and blanking a comment is exactly as safe as setting one.
     */
    public function down(): void
    {
        $this->apply(static fn (string $table, string $column): ?string => '', blankTables: true);
    }

    /**
     * Walk every column in the schema and hand each one whatever comment the
     * callback gives it.
     *
     * Driven by what the database actually HAS rather than by the map, so a
     * column that has been dropped since cannot fail the migration and one
     * that has been added since is simply passed over.
     *
     * @param  callable(string, string): ?string  $comment
     * @param  bool  $blankTables  Rolling back: empty the table comments too.
     */
    private function apply(callable $comment, bool $blankTables = false): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // The table collation comes along so a column that merely INHERITS the
        // table default can be left alone rather than having it spelled out —
        // see restate().
        $columns = DB::select(
            'SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT, '.
            'c.EXTRA, c.CHARACTER_SET_NAME, c.COLLATION_NAME, t.TABLE_COLLATION '.
            'FROM information_schema.COLUMNS c '.
            'JOIN information_schema.TABLES t '.
            '  ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME '.
            'WHERE c.TABLE_SCHEMA = DATABASE() '.
            'ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION'
        );

        // Tables first: a bare ALTER, no definition in play. Driven by what
        // the schema actually has, like the columns below, so a table dropped
        // since cannot fail the migration.
        $present = array_flip(array_map(
            static fn (object $column): string => $column->TABLE_NAME,
            $columns,
        ));

        foreach ($this->tableComments() as $table => $text) {
            if (! isset($present[$table])) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` COMMENT = %s',
                $table,
                $this->quote($blankTables ? '' : $text),
            ));
        }

        foreach ($columns as $column) {
            $text = $comment($column->TABLE_NAME, $column->COLUMN_NAME);

            if ($text === null) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY COLUMN `%s` %s COMMENT %s',
                $column->TABLE_NAME,
                $column->COLUMN_NAME,
                $this->restate($column),
                $this->quote($text),
            ));
        }
    }

    private function commentFor(string $table, string $column): ?string
    {
        return $this->comments()[$table][$column] ?? self::GENERIC[$column] ?? null;
    }

    /**
     * The column definition it already has, written back out unchanged.
     *
     * This is the whole safety argument for the migration: MODIFY COLUMN
     * replaces a definition wholesale, so the definition it is given is read
     * out of the schema a moment earlier rather than typed by hand. Nothing
     * here can widen a column or drop a default, because nothing here decides
     * what either of those should be.
     */
    private function restate(object $column): string
    {
        $sql = $column->COLUMN_TYPE;

        // ONLY when the column differs from its table default. Spelling out a
        // charset the column was merely inheriting would keep the same
        // collation today and quietly stop the column following the table if
        // that default were ever changed — a real difference, and one that
        // shows up as a schema diff nobody asked for.
        if ($column->COLLATION_NAME !== null && $column->COLLATION_NAME !== $column->TABLE_COLLATION) {
            $sql .= " CHARACTER SET {$column->CHARACTER_SET_NAME} COLLATE {$column->COLLATION_NAME}";
        }

        $sql .= $column->IS_NULLABLE === 'NO' ? ' NOT NULL' : ' NULL';

        if ($column->COLUMN_DEFAULT !== null) {
            // CURRENT_TIMESTAMP and numbers go in bare; anything else is a
            // string literal and is quoted. Quoting a number would still work
            // and would make SHOW CREATE TABLE read differently afterwards,
            // which is the one difference this migration must not introduce.
            $default = $column->COLUMN_DEFAULT;

            $sql .= ' DEFAULT '.($default === 'CURRENT_TIMESTAMP' || is_numeric($default)
                ? $default
                : $this->quote($default));
        }

        // DEFAULT_GENERATED describes the default above rather than the
        // column, and re-emitting it is a syntax error. auto_increment and
        // "on update CURRENT_TIMESTAMP" are real and have to survive.
        $extra = trim(str_ireplace('DEFAULT_GENERATED', '', $column->EXTRA));

        return $extra === '' ? $sql : "{$sql} {$extra}";
    }

    /** A SQL string literal. DDL cannot take a bound parameter. */
    private function quote(string $value): string
    {
        return DB::getPdo()->quote($value);
    }
};
