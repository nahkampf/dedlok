<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table): void {
            $table->comment('Story messages an encounter sent with a delay. `messages:deliver` turns each into an inbox message once deliver_at has passed, then deletes it.');
            $table->id()->comment('Row id.');
            $table->foreignId('user_id')->comment('The account the message is for.')->constrained()->cascadeOnDelete();
            $table->string('sender', 100)->comment('Who it is from, shown on the message.');
            $table->string('title', 120)->comment('The closed row of the inbox.');
            $table->string('body', 500)->comment('The sentence inside.');
            $table->timestamp('deliver_at')->index()->comment('When it reaches the inbox.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
