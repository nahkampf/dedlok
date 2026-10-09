<?php

declare(strict_types=1);

use App\Cyberspace\GridSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The grid grows from 64 x 64 to 128 x 128 (`cyberspace.size`), and the portal, which is derived as the middle, moves from
 * (32,32) to (64,64).
 *
 * Exchanges never move on their own, so they are moved HERE, once, to rings round the new portal (the placer's own rule:
 * the rings divide the distance from the portal to the corner into five, grade 1 innermost). Everything that remembers where
 * a moved system stood follows it: each player's sighting of it, their saved waypoints on its old position, and any scan
 * file. A sighting or waypoint that would land on a cell the player already has one for gives way to the newer. System
 * bookmarks and missions refer to an address or a row and need nothing. Then the world is topped up to its occupancy over
 * the new area, at the same ratios, which is a pass of the ordinary seeder.
 *
 * Does nothing on an empty grid (a fresh install or a test database), where the seeder alone makes the world.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('systems')->exists()) {
            return;
        }

        $size = (int) config('cyberspace.size', 128);
        $middle = intdiv($size, 2);

        DB::transaction(function () use ($size, $middle): void {
            $taken = [];

            foreach (DB::table('systems')->get(['x', 'y']) as $row) {
                $taken["{$row->x},{$row->y}"] = true;
            }

            $move = function (object $system, int $x, int $y) use (&$taken): void {
                unset($taken["{$system->x},{$system->y}"]);
                $taken["{$x},{$y}"] = true;

                DB::table('systems')->where('id', $system->id)->update(['x' => $x, 'y' => $y]);
                $this->follow($system, $x, $y);
            };

            // Whatever stands on the new middle gives way (an exchange is moved on its own, below).
            $portal = DB::table('systems')->where('type', 'portal')->first();
            $exchanges = DB::table('systems')->where('type', 'exchange')->get();
            $exchangeIds = $exchanges->pluck('id')->all();

            // Exchanges first: the rings are measured from the portal's NEW place, so they are chosen before the portal sits there.
            $step = max(1, (int) floor($size / 2 / 5));

            foreach ($exchanges as $desk) {
                $grade = (int) DB::table('exchanges')->where('system_id', $desk->id)->value('grade') ?: 1;
                $inner = ($grade - 1) * $step;
                $outer = $grade === 5 ? $size : $grade * $step;
                $placed = null;

                foreach ([$outer, $size] as $limit) {
                    for ($try = 0; $try < 2000 && $placed === null; $try++) {
                        $x = random_int(0, $size - 1);
                        $y = random_int(0, $size - 1);
                        $distance = max(abs($x - $middle), abs($y - $middle));

                        if ($distance >= $inner && $distance <= $limit && ! isset($taken["{$x},{$y}"])) {
                            $placed = [$x, $y];
                        }
                    }

                    if ($placed !== null) {
                        break;
                    }
                }

                if ($placed !== null) {
                    $move($desk, $placed[0], $placed[1]);
                }
            }

            if ($portal !== null && ($portal->x !== $middle || $portal->y !== $middle)) {
                $occupant = DB::table('systems')->where('x', $middle)->where('y', $middle)->where('id', '!=', $portal->id)->first();

                if ($occupant !== null && ! in_array($occupant->id, $exchangeIds, true)) {
                    do {
                        $x = random_int(0, $size - 1);
                        $y = random_int(0, $size - 1);
                    } while (isset($taken["{$x},{$y}"]));

                    $move($occupant, $x, $y);
                }

                $move($portal, $middle, $middle);
            }
        });

        // The world, topped up to its occupancy over the new area. The portal is in place, so this only fills.
        GridSeeder::fromConfig()->run();
    }

    /** What remembers where a system stood now says where it stands. */
    private function follow(object $system, int $x, int $y): void
    {
        foreach (['system_contacts', 'bookmarks'] as $table) {
            // Whatever the player already holds at the destination is older news than this: clear it so the key stays unique.
            $query = fn () => DB::table($table)->where('x', $system->x)->where('y', $system->y)
                ->when($table === 'system_contacts', fn ($q) => $q->where('address', $system->address))
                ->when($table === 'bookmarks', fn ($q) => $q->where('kind', 'waypoint'));

            $holders = $query()->pluck('character_id');

            if ($holders->isNotEmpty()) {
                DB::table($table)->whereIn('character_id', $holders)->where('x', $x)->where('y', $y)->delete();
                $query()->update(['x' => $x, 'y' => $y]);
            }
        }

        DB::table('system_scans')->where('address', $system->address)->update(['x' => $x, 'y' => $y]);
    }

    public function down(): void
    {
        // Not reversible: the old world was only part of this one.
    }
};
