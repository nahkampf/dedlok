<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Non-Disclosure Agreement from Joint Signals Command reaches the inbox twenty minutes after "A strange dataset" ends, however the
 * player got to the ending (every route goes through the one ending node). The encounter is `once`, so the message is too. Found by
 * the node's key, and left alone if the node is missing or already has a message.
 */
return new class extends Migration
{
    private const NODE = 'njord_jsc_files_logoff';

    public function up(): void
    {
        $node = DB::table('encounter_nodes')->where('node_key', self::NODE)->value('id');

        if ($node === null || DB::table('encounter_outcomes')->where('node_id', $node)->where('kind', 'message')->exists()) {
            return;
        }

        DB::table('encounter_outcomes')->insert([
            'node_id' => $node,
            'branch' => 'always',
            'position' => (int) DB::table('encounter_outcomes')->where('node_id', $node)->max('position') + 1,
            'kind' => 'message',
            'amount' => 20 * 60,
            'ref' => 'Joint Signals Command Legal Department',
            'text' => 'Non-disclosure agreement',
            'entry_text' => 'It has come to our attention that you may have accessed confindential files. You are hereby informed that by accessings these files you have entered into a Non-Disclosure agreement with JSC, preventing you from divulging any information gleaned from these files. Failure to comply may result in legal or extra-judical consequences. By reading this message you have approved the terms and conditions of the NDA.',
        ]);
    }

    public function down(): void
    {
        $node = DB::table('encounter_nodes')->where('node_key', self::NODE)->value('id');

        DB::table('encounter_outcomes')->where('node_id', $node)->where('kind', 'message')->where('text', 'Non-disclosure agreement')->delete();
    }
};
