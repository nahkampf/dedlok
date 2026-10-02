<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_activities', function (Blueprint $table): void {
            $table->comment('What a character is doing in the City right now: at most one row each. Written only by App\\City\\Traveller and App\\City\\Explorer, settled lazily with a five-minute sweep behind it.');
            $table->foreignId('character_id')->primary()->comment('Whose activity. The primary key is what makes "one thing at a time" a rule nobody has to enforce.')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->comment('App\\City\\ActivityKind: travel or explore.');
            $table->string('mode', 10)->nullable()->comment('App\\City\\TravelMode: walk or pod. NULL for exploring.');
            $table->json('route')->nullable()->comment('Travel only: the district ids from origin to destination, snapshotted at the start and never rewritten.');
            $table->unsignedSmallInteger('step')->default(0)->comment('Travel only: the index into route of the district whose pass is running. Exploring is always 0.');
            $table->unsignedInteger('paid')->default(0)->comment('Creds charged up front (a pod fare), snapshotted so a refund returns what was paid whatever config says now.');
            $table->boolean('cancelling')->default(false)->comment('The player asked to stop. Takes effect when the pass in progress matures, never before.');
            $table->timestamp('pass_ends_at')->comment('When the pass in progress matures. The next pass begins here, not at settle time.');
            $table->timestamp('created_at')->nullable()->comment('Laravel bookkeeping: when the activity began.');
            $table->timestamp('updated_at')->nullable()->comment('Laravel bookkeeping: when the row was last changed.');

            $table->index('pass_ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_activities');
    }
};
