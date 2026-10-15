<?php

declare(strict_types=1);

namespace App\Cyberspace\Party;

use App\Models\Character;
use App\Models\Run;
use App\Models\RunParty;
use Illuminate\Support\Collection;

/**
 * Who a party's take is divided among, and how.
 *
 * The runners are the owner and everybody who has a run row of the party, including one who has already egressed: they
 * took the risk and are owed their share. The owner is always first, so a remainder or a tie goes to them.
 * Dividing is by largest remainder, so the parts always add back up to the whole.
 */
final class PartyShare
{
    /** @param  Collection<int, Character>  $runners  owner first */
    private function __construct(private readonly RunParty $party, public readonly Collection $runners) {}

    /** The party the character's live run belongs to, or null for a solo run (or a party of one). */
    public static function for(Character $character): ?self
    {
        $run = $character->current_run_id === null ? null : Run::query()->find($character->current_run_id);

        if ($run === null || $run->run_party_id === null) {
            return null;
        }

        $party = RunParty::query()->find($run->run_party_id);

        if ($party === null) {
            return null;
        }

        $ids = Run::query()->where('run_party_id', $party->getKey())->pluck('character_id')->unique()
            ->reject(fn ($id): bool => (int) $id === (int) $party->owner_character_id)->values();

        $ids = $ids->prepend($party->owner_character_id)->values();
        $found = Character::query()->whereKey($ids)->get()->keyBy('id');
        $runners = $ids->map(fn ($id) => $found->get($id))->filter()->values();

        return $runners->count() < 2 ? null : new self($party, $runners);
    }

    public function credits(): ShareMode
    {
        return $this->party->share_credits;
    }

    public function extraction(): ShareMode
    {
        return $this->party->share_extraction;
    }

    /**
     * Whole units dealt out by largest remainder.
     *
     * @return array<int, int> character id => units, owner first, summing to $units
     */
    public function divide(int $units, ShareMode $mode): array
    {
        $n = $this->runners->count();
        $out = [];
        $weights = [];

        foreach ($this->runners->values() as $i => $runner) {
            $share = $i === 0 ? $mode->ownerShare($n) : $mode->memberShare($n);
            $exact = $units * $share;
            $out[$runner->getKey()] = (int) floor($exact + 1e-9);
            $weights[$runner->getKey()] = $exact - $out[$runner->getKey()];
        }

        $left = $units - array_sum($out);
        $order = array_keys($weights);

        // Largest remainder first; a tie keeps the owner-first order (stable sort).
        usort($order, fn ($a, $b): int => $weights[$b] <=> $weights[$a]);

        foreach ($order as $id) {
            if ($left <= 0) {
                break;
            }
            $out[$id]++;
            $left--;
        }

        return $out;
    }
}
