<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->boolean('is_event')->default(false)->after('message')->comment('A line the game wrote about the chat ("X has joined the chat"), drawn in italics without a speaker. Never set from what a player types.');
        });

        Schema::create('chat_invites', function (Blueprint $table): void {
            $table->comment('A pending invitation into a room, answered from the inbox message: Join makes a member, No thanks deletes the row.');
            $table->unsignedInteger('chat_id')->comment('The room.');
            $table->unsignedBigInteger('character_id')->comment('Who was invited.');
            $table->unsignedBigInteger('invited_by')->comment('The member who invited them; plain id, no foreign key.');
            $table->timestamp('created_at')->useCurrent()->comment('When the invitation was sent.');
            $table->primary(['chat_id', 'character_id']);
            $table->foreign('chat_id')->references('id')->on('chats')->cascadeOnDelete();
            $table->foreign('character_id')->references('id')->on('characters')->cascadeOnDelete();
        });

        Schema::create('chat_ignores', function (Blueprint $table): void {
            $table->comment('Whose lines a character does not see in chat (/ignore). One-sided: the ignored are not told.');
            $table->unsignedBigInteger('character_id')->comment('The one who ignores.');
            $table->unsignedBigInteger('ignored_id')->comment('The character whose chat lines, direct lines and invitations they do not get.');
            $table->timestamp('created_at')->useCurrent()->comment('When it was set.');
            $table->primary(['character_id', 'ignored_id']);
            $table->foreign('character_id')->references('id')->on('characters')->cascadeOnDelete();
            $table->foreign('ignored_id')->references('id')->on('characters')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ignores');
        Schema::dropIfExists('chat_invites');
        Schema::table('chat_messages', fn (Blueprint $table) => $table->dropColumn('is_event'));
    }
};
