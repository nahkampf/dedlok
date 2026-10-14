<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table): void {
            $table->comment('A conversation: the one General channel, a named room, or a direct line between two characters.');
            $table->increments('id')->comment('Primary key; chat_messages.chat_id points here.');
            $table->string('kind', 10)->comment('general (everybody, exactly one row), room (named, members) or direct (two characters, from /msg).');
            $table->string('name', 64)->nullable()->comment('The room\'s name. NULL for General (named by the game) and for a direct line (named by the other person).');
            $table->boolean('is_public')->default(false)->comment('A public room can be joined by anybody who finds it; a private one only by invitation. Unread for general and direct.');
            $table->unsignedBigInteger('created_by')->nullable()->comment('Character that made the room; plain id, no foreign key, so it outlives them.');
            $table->string('direct_key', 41)->nullable()->unique()->comment('"lowId:highId" of a direct line\'s two characters, so /msg finds the same line from either side. NULL otherwise.');
            $table->timestamp('created_at')->useCurrent()->comment('When the chat was made.');
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->comment('One line said in a chat. Immutable: written once, never edited, no updated_at.');
            $table->id()->comment('Primary key; also the order within a chat and the unit of "read up to".');
            $table->unsignedInteger('chat_id')->comment('The chat it was said in. Cascades: a chat with no row has no history.');
            $table->timestamp('created_at')->useCurrent()->comment('When it was said; shown on hover.');
            $table->unsignedBigInteger('character_id')->comment('Who said it. Cascades with the character.');
            $table->string('message', 500)->comment('Plain text as stored (HTML already stripped); markdown is interpreted when drawn, never here.');
            $table->index(['chat_id', 'id']);
            $table->foreign('chat_id')->references('id')->on('chats')->cascadeOnDelete();
            $table->foreign('character_id')->references('id')->on('characters')->cascadeOnDelete();
        });

        Schema::create('chat_members', function (Blueprint $table): void {
            $table->comment('Who belongs to a room or direct line, and how far they have read. General has no rows: everybody is in it.');
            $table->unsignedInteger('chat_id')->comment('The chat.');
            $table->unsignedBigInteger('character_id')->comment('The member.');
            $table->unsignedBigInteger('last_read_id')->default(0)->comment('The newest chat_messages.id this member has seen; the unread count is what is above it.');
            $table->timestamp('created_at')->useCurrent()->comment('When they joined or were invited.');
            $table->primary(['chat_id', 'character_id']);
            $table->foreign('chat_id')->references('id')->on('chats')->cascadeOnDelete();
            $table->foreign('character_id')->references('id')->on('characters')->cascadeOnDelete();
        });

        DB::table('chats')->insert(['kind' => 'general', 'is_public' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_members');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chats');
    }
};
