<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fortress_roles', function (Blueprint $table): void {
            $table->comment('A role the owner defined for one Data Fortress: a name and the properties it carries. Overrides the config defaults of the same name.');
            $table->id()->comment('Primary key.');
            $table->unsignedBigInteger('system_id')->index()->comment('The fortress (a plain systems.id).');
            $table->string('name', 30)->comment('Lower-case slug a member holds and an area names.');
            $table->json('properties')->comment('What it lets a holder do: host, maintain, alarm, audit.');
            $table->timestamps();
            $table->unique(['system_id', 'name']);
        });

        Schema::create('fortress_areas', function (Blueprint $table): void {
            $table->comment('A storage area in a Data Fortress. Files with no area are in the owner\'s storage, which only the owner can use.');
            $table->id()->comment('Primary key.');
            $table->unsignedBigInteger('system_id')->index()->comment('The fortress (a plain systems.id).');
            $table->string('name', 40)->comment('What it is called.');
            $table->json('permissions')->comment('Audience to what it may do here: {"members": ["see","deposit","withdraw"], "role:<name>": [...], "member:<characterId>": [...]}. The owner may always do all three.');
            $table->timestamps();
        });

        Schema::create('fortress_log', function (Blueprint $table): void {
            $table->comment('What was done at a Data Fortress, by whom: uploads, takings, installs, setting changes. Immutable.');
            $table->id()->comment('Primary key.');
            $table->unsignedBigInteger('system_id')->index()->comment('The fortress (a plain systems.id).');
            $table->unsignedBigInteger('character_id')->nullable()->comment('Who did it. A plain id: the log outlives a deleted character.');
            $table->string('action', 40)->comment('A short key: deposit, withdraw, install, uninstall, start, stop, maintain, invite, revoke, settings, area, role, sale.');
            $table->string('detail', 255)->nullable()->comment('What it was done to, in words, composed when it happened.');
            $table->timestamp('created_at')->useCurrent()->comment('When.');
        });

        Schema::table('fortress_members', function (Blueprint $table): void {
            $table->json('roles')->nullable()->comment('Every role this member holds. NULL means just the one named in role.');
        });

        Schema::table('system_contents', function (Blueprint $table): void {
            $table->unsignedBigInteger('area_id')->nullable()->comment('The fortress storage area a file is in; NULL is the owner\'s storage. Only meaningful on a fortress.');
            $table->unsignedBigInteger('deposited_by')->nullable()->comment('Who put a file into a fortress (a plain characters.id); NULL for anything an NPC node holds.');
        });
    }

    public function down(): void
    {
        Schema::table('system_contents', fn (Blueprint $t) => $t->dropColumn(['area_id', 'deposited_by']));
        Schema::table('fortress_members', fn (Blueprint $t) => $t->dropColumn('roles'));
        Schema::dropIfExists('fortress_log');
        Schema::dropIfExists('fortress_areas');
        Schema::dropIfExists('fortress_roles');
    }
};
