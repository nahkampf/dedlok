<?php

declare(strict_types=1);

namespace App\Standing;

use App\Characters\Flags;
use App\Models\Character;
use App\Models\Entity;
use App\Models\System;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * What an entity hands over once standing with it is high enough (`standing.grants`).
 *
 * Run by `StandingLedger::record()` after a gain. A flag is one way, so a grant is earned once: `Flags::set()` says
 * whether this is the first time, and only then is the message sent.
 */
final class StandingGrants
{
    public function check(Character $character, Entity $entity): void
    {
        foreach ((array) Config::get('standing.grants', []) as $grant) {
            if ((int) $grant['entity'] !== $entity->getKey()) {
                continue;
            }

            $standing = (float) (new StandingLedger)->standingFor($character, $entity);

            if ($standing < (float) $grant['at'] || ! Flags::set($character, (string) $grant['flag'])) {
                continue;
            }

            (new Notifier)->send($character, Category::Cyberspace, (string) $grant['message'], [
                'address' => $this->gatewayAddress((string) $grant['grid']),
            ]);
        }
    }

    /** The main-grid gateway that leads into this subnet. */
    private function gatewayAddress(string $slug): string
    {
        $entry = System::query()
            ->whereIn('grid_id', DB::table('grids')->where('slug', $slug)->select('id'))
            ->where('type', 'gateway')
            ->first();

        return (string) System::query()->whereKey($entry?->links_to_system_id)->value('address');
    }
}
