<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lockjaw's subnet gateway, the main-grid end of the Darknet, takes the reserved address DEADBEEF. Everything that holds
 * the address by value follows it: scans, contacts, bookmarks, mission targets, runs, run parties, and the darknet_access
 * messages already sent. The gateway is found through the darknet grid, not by its old address. Does nothing when there
 * is no darknet, and refuses when DEADBEEF is held by another system. No schema changes.
 */
return new class extends Migration
{
    private const NEW = 'DEADBEEF';

    private const OLD = '6F59D096';

    public function up(): void
    {
        $grid = DB::table('grids')->where('slug', 'darknet')->value('id');
        $inside = $grid === null ? null : DB::table('systems')->where('grid_id', $grid)->where('type', 'gateway')->value('links_to_system_id');
        $from = $inside === null ? null : DB::table('systems')->where('id', $inside)->value('address');

        if ($from !== null) {
            $this->move((string) $from, self::NEW);
        }
    }

    public function down(): void
    {
        if (DB::table('systems')->where('address', self::NEW)->where('type', 'gateway')->exists()) {
            $this->move(self::NEW, self::OLD);
        }
    }

    private function move(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        if (DB::table('systems')->where('address', $to)->exists()) {
            throw new RuntimeException("Address {$to} is already held by another system.");
        }

        DB::transaction(function () use ($from, $to): void {
            DB::table('systems')->where('address', $from)->update(['address' => $to]);

            foreach (['system_scans', 'system_contacts', 'bookmarks', 'mission_targets', 'runs', 'run_parties'] as $table) {
                DB::table($table)->where('address', $from)->update(['address' => $to]);
            }

            DB::table('notifications')->where('body', 'like', "%{$from}%")
                ->update(['body' => DB::raw('REPLACE(body, '.DB::getPdo()->quote($from).', '.DB::getPdo()->quote($to).')')]);
        });
    }
};
