<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ten character attributes, in the order they were specified.
     *
     * Duplicated from Character::ATTRIBUTES on purpose: a migration is a
     * historical record and must not change when the model does.
     * CharacterTest asserts the two still agree.
     */
    private const ATTRIBUTES = [
        'intellect', 'memory', 'perception', 'reflex', 'focus',
        'social', 'meat', 'motor', 'senses', 'muscle',
    ];

    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();

            // One character per user. Unique rather than a plain index, so the
            // "every user has a character" relationship is one-to-one at the
            // storage layer and not just in the model.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Same 24-character limit as the account handle (App\Rules\ValidHandle).
            // Unique because suggest_hacker_handles.in_use only makes sense if a
            // handle can be claimed once.
            $table->string('handle', 24)->unique();

            // 0-100. unsignedTinyInteger already excludes negatives and caps at
            // 255; the CHECK constraints below bring that down to 100.
            foreach (self::ATTRIBUTES as $attribute) {
                $table->unsignedTinyInteger($attribute)->default(0);
            }

            // Integer for now — expected to become a foreign key or enum later.
            // Nullable so "not yet chosen" is representable.
            $table->unsignedTinyInteger('archetype')->nullable();

            $table->timestamps();
        });

        // Laravel's Blueprint has no CHECK helper, so this is raw SQL, and
        // SQLite cannot add a constraint via ALTER TABLE. MySQL enforces it
        // (8.0.16+); on SQLite the ceiling is enforced by the model and by
        // validation, which the tests cover.
        if (DB::getDriverName() === 'mysql') {
            foreach (self::ATTRIBUTES as $attribute) {
                DB::statement(
                    "ALTER TABLE `characters` ADD CONSTRAINT `chk_characters_{$attribute}` CHECK (`{$attribute}` <= 100)"
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
