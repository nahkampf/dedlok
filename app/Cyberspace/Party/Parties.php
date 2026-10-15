<?php

declare(strict_types=1);

namespace App\Cyberspace\Party;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunResult;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\RunPartyMember;
use App\Models\System;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `run_parties` and `run_party_members`: a party FORMING at a system's door.
 *
 * The cap is the owner's `coop_slots` and counts every invitation, accepted or not, so an owner cannot hold more
 * seats open than they could fill. Nobody joins a run in progress (a run is a plan); this is the "at the door" half and
 * the run itself is not started here.
 */
final class Parties
{
    public function open(Character $owner, ShareMode $credits = ShareMode::Even, ShareMode $extraction = ShareMode::Even): PartyRefusal|RunParty
    {
        if ($owner->coopSlots() < 1) {
            return PartyRefusal::NoSlots;
        }

        if ($owner->current_run_id !== null) {
            return PartyRefusal::InARun;
        }

        $at = $owner->gridPosition();

        if ($at === null) {
            return PartyRefusal::NotJackedIn;
        }

        $system = System::query()->at($at)->first();

        if ($system === null || ! $system->type->isInteractable()) {
            return PartyRefusal::NothingThere;
        }

        if ($this->partyOf($owner) !== null) {
            return PartyRefusal::AlreadyInParty;
        }

        return RunParty::query()->create([
            'owner_character_id' => $owner->getKey(),
            'address' => $system->address,
            'x' => $at->x,
            'y' => $at->y,
            'share_credits' => $credits,
            'share_extraction' => $extraction,
        ]);
    }

    public function options(Character $owner, ShareMode $credits, ShareMode $extraction): ?PartyRefusal
    {
        $party = RunParty::query()->where('owner_character_id', $owner->getKey())->first();

        if ($party === null) {
            return PartyRefusal::NoParty;
        }

        $party->update(['share_credits' => $credits, 'share_extraction' => $extraction]);

        return null;
    }

    public function invite(Character $owner, string $handle): ?PartyRefusal
    {
        return DB::transaction(function () use ($owner, $handle): ?PartyRefusal {
            $party = RunParty::query()->where('owner_character_id', $owner->getKey())->lockForUpdate()->first();

            if ($party === null) {
                return PartyRefusal::NoParty;
            }

            $target = Character::query()->where('handle', $handle)->first();

            if ($target === null) {
                return PartyRefusal::NoSuchRunner;
            }

            if ($target->getKey() === $owner->getKey()) {
                return PartyRefusal::Yourself;
            }

            if ($party->members()->count() >= $owner->coopSlots()) {
                return PartyRefusal::Full;
            }

            if ($target->current_run_id !== null) {
                return PartyRefusal::InARun;
            }

            if ($this->partyOf($target) !== null) {
                return PartyRefusal::AlreadyInParty;
            }

            $party->members()->create(['character_id' => $target->getKey()]);

            (new Notifier)->send($target, Category::Cyberspace, 'party_invite', ['owner' => $owner->handle]);

            return null;
        });
    }

    /** Accepting needs standing at the same door: the party forms where it is going. */
    public function accept(Character $character, ?array $loadout = null): ?PartyRefusal
    {
        return DB::transaction(function () use ($character, $loadout): ?PartyRefusal {
            $member = RunPartyMember::query()->where('character_id', $character->getKey())->lockForUpdate()->first();

            if ($member === null) {
                return PartyRefusal::NotInvited;
            }

            if ($character->current_run_id !== null) {
                return PartyRefusal::InARun;
            }

            $at = $character->gridPosition();
            $party = $member->party;

            if ($at === null || $at->x !== $party->x || $at->y !== $party->y) {
                return PartyRefusal::NotAtTheDoor;
            }

            // Run-only software the member actually holds, as row ids. What may START is ProgramRunner's, at launch.
            $mine = $loadout === null ? ($member->loadout ?? []) : InventoryItem::query()
                ->where('character_id', $character->getKey())->whereIn('id', $loadout)->pluck('id')->all();

            $member->update(['joined_at' => $member->joined_at ?? now(), 'loadout' => $mine]);

            return null;
        });
    }

    /** Declining an invitation, leaving the party, or (for the owner) disbanding it: all one door. */
    public function leave(Character $character): void
    {
        RunParty::query()->where('owner_character_id', $character->getKey())->delete();
        RunPartyMember::query()->where('character_id', $character->getKey())->delete();
    }

    /** The party this character owns or is invited to / in, if any. */
    public function partyOf(Character $character): ?RunParty
    {
        return RunParty::query()->where('owner_character_id', $character->getKey())->first()
            ?? RunPartyMember::query()->where('character_id', $character->getKey())->first()?->party;
    }

    /**
     * THE OWNER BEGINS IT, and every joined member goes in at once with the loadout they chose at the door.
     *
     * Each runner gets their OWN run (own approach roll, own trace, own loadout) tied together by `runs.run_party_id`.
     * The owner's run starts first and decides it: a refusal there starts nobody. A member who cannot start (deck not
     * ready, moved off the door, a program that will not start) is dropped from the party, told, and the rest go.
     */
    public function launch(Character $owner, RunPurpose $purpose, ?BackdoorKind $kind, array $ownerLoadout, RunStarter $starter): RunResult
    {
        $party = RunParty::query()->where('owner_character_id', $owner->getKey())->first();

        if ($party === null || $party->started_at !== null) {
            return $starter->start($owner, $purpose, $kind, $ownerLoadout);
        }

        $result = $starter->start($owner, $purpose, $kind, $ownerLoadout, $party->getKey());

        if (! $result->succeeded()) {
            return $result;
        }

        $party->update(['started_at' => now()]);

        foreach ($party->members()->whereNotNull('joined_at')->with('character')->get() as $member) {
            $runner = $member->character;
            $at = $runner?->gridPosition();
            $started = false;

            if ($runner !== null && $at !== null && $at->x === $party->x && $at->y === $party->y) {
                $rows = InventoryItem::query()->whereIn('id', $member->loadout ?? [])
                    ->where('character_id', $runner->getKey())->with('item.program')->get()->all();
                $started = $starter->start($runner, $purpose, $kind, $rows, $party->getKey())->succeeded();
            }

            if (! $started) {
                $member->delete();
                if ($runner !== null) {
                    (new Notifier)->send($runner, Category::Cyberspace, 'party_dropped', ['owner' => $owner->handle]);
                }
            } else {
                (new Notifier)->send($runner, Category::Cyberspace, 'party_started', ['owner' => $owner->handle]);
            }
        }

        return $result;
    }

    /** A run of the party ended: when none is left live, the party is over and everybody is free to form another. */
    public static function runEnded(Run $run): void
    {
        $live = Run::query()->where('run_party_id', $run->run_party_id)->whereNull('ended_at')->exists();

        if (! $live) {
            RunParty::query()->whereKey($run->run_party_id)->delete();
        }
    }
}
