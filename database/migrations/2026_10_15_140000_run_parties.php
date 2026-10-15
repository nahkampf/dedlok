<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_parties', function (Blueprint $table): void {
            $table->comment('A party forming at the door of a system for a co-op run. One per owner; the owner\'s coop_slots caps the members.');
            $table->id()->comment('Primary key.');
            $table->foreignId('owner_character_id')
                ->unique()
                ->comment('The runner who called it, whose skill sizes it and who keeps the files. One party at a time.')
                ->constrained('characters')->cascadeOnDelete();
            $table->char('address', 8)->comment('The system the party is gathering at. A plain address, like runs.address: nothing here may outlive-block the system.');
            $table->unsignedSmallInteger('x')->comment('Grid position the party stands at. Members must be on it to join.');
            $table->unsignedSmallInteger('y')->comment('Grid position the party stands at (y).');
            $table->string('share_credits', 10)->default('even')->comment('How creds are shared: even, half (owner takes 50%) or full (owner takes all). A ShareMode value.');
            $table->string('share_extraction', 10)->default('even')->comment('How extracted files are shared: even, half or full. A ShareMode value.');
            $table->timestamps();
        });

        Schema::create('run_party_members', function (Blueprint $table): void {
            $table->comment('An invitation to a run party, and whether it has been accepted. The owner is not a member row.');
            $table->id()->comment('Primary key.');
            $table->foreignId('run_party_id')
                ->comment('The party.')
                ->constrained('run_parties')->cascadeOnDelete();
            $table->foreignId('character_id')
                ->unique()
                ->comment('The invited runner. Unique: one party at a time, invited or joined.')
                ->constrained('characters')->cascadeOnDelete();
            $table->timestamp('joined_at')->nullable()->comment('When they accepted. NULL means still only invited; an invitation does not take a slot beyond counting against the cap.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_party_members');
        Schema::dropIfExists('run_parties');
    }
};
