<?php

declare(strict_types=1);

use App\Standing\LedgerKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an entity thinks of somebody, and what it owes them.
 *
 * TWO TABLES AND ONE INVARIANT: the books are always exactly the sum of the
 * ledger, per kind. That survives clamping and the nightly drift because
 * every delta records WHAT LANDED rather than what was intended — a raid
 * against an entity already at the floor writes a delta of zero, which
 * honestly says the raid changed nothing.
 *
 * Standing is DECIMAL and not a float. A mission is worth hundredths of a
 * point, the drift takes half a percent a night, and binary floats would
 * accumulate error until the invariant drifted for no reason anybody could
 * find. `characters.heat` made the same call for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_books', function (Blueprint $table): void {
            $table->comment('What one entity thinks of one character, and what it owes them. A missing row is neutral and broke: absent and zero are the same answer, as they are for the drug books.');

            $table->id()->comment('Primary key.');

            $table->foreignId('character_id')
                ->comment('Whose books these are. Standing belongs to the CHARACTER, like heat, and not to the account.')
                ->constrained()->cascadeOnDelete();

            $table->foreignId('entity_id')
                ->comment('Who is keeping them. Only an interactable entity ever has a row: flavour scenery keeps no books.')
                ->constrained()->cascadeOnDelete();

            $table->decimal('standing', 6, 3)->default(0)
                ->comment('Reputation, -100.000 to 100.000, clamped on the way in. Always equals the sum of the standing entries in entity_ledger.');

            $table->unsignedInteger('loyalty')->default(0)
                ->comment('Favours owed, earned only by missions and spent only at the entity store. Never decays: a grudge fades, a favour bank does not.');

            $table->timestamps();

            $table->unique(['character_id', 'entity_id'], 'uq_entity_books');
        });

        Schema::create('entity_ledger', function (Blueprint $table): void {
            $table->comment('Every change to a character\'s standing or loyalty with an entity, immutable. One table for both kinds because an entity screen wants one history, in order, of what passed between them.');

            $table->id()->comment('Primary key.');

            $table->foreignId('character_id')
                ->comment('Whose change this was.')
                ->constrained()->cascadeOnDelete();

            $table->foreignId('entity_id')
                ->comment('Who it was with. A real foreign key: an entity outlives every system it owns, so there is nothing here to outlive.')
                ->constrained()->cascadeOnDelete();

            $table->string('kind', 16)
                ->comment('Which number moved: '.implode(', ', LedgerKind::values()).'. Read as App\Standing\LedgerKind.');

            $table->decimal('delta', 6, 3)
                ->comment('What LANDED, not what was intended: already clamped, so summing the column reproduces the books exactly. Zero is an honest entry — it says the act changed nothing.');

            $table->string('reason', 32)
                ->comment('Why it moved, read as App\Standing\StandingReason. Never a rendered sentence: an entity does not relocate or stop existing, so the line can be composed when it is read.');

            $table->timestamp('happened_at')
                ->comment('When. No timestamps() pair: a ledger row is never updated, so created_at would be this column with another name.');

            $table->index(['character_id', 'entity_id', 'kind'], 'idx_entity_ledger_books');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_ledger');
        Schema::dropIfExists('entity_books');
    }
};
