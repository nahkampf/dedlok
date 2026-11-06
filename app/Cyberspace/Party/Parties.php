<?php

declare(strict_types=1);

namespace App\Cyberspace\Party;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunRefusal;
use App\Cyberspace\RunResult;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\Chat;
use App\Models\InventoryItem;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\RunPartyMember;
use App\Models\System;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Programs\ProgramKind;
use Illuminate\Support\Collection;
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
    /** An invitation nobody answered lapses after this long. */
    public const INVITE_MINUTES = 15;

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

        if ($system->type->isGateway()) {
            return PartyRefusal::TooNoisy;
        }

        if ($this->partyOf($owner) !== null) {
            return PartyRefusal::AlreadyInParty;
        }

        return RunParty::query()->create([
            'owner_character_id' => $owner->getKey(),
            'address' => $system->address,
            'grid_id' => $at->grid,
            'x' => $at->x,
            'y' => $at->y,
            'share_credits' => $credits,
            'share_extraction' => $extraction,
        ]);
    }

    public function options(Character $owner, ShareMode $credits, ShareMode $extraction): ?PartyRefusal
    {
        // The same lock `invite` takes, so an invitation cannot slip in beside a change of terms.
        return DB::transaction(function () use ($owner, $credits, $extraction): ?PartyRefusal {
            $party = RunParty::query()->where('owner_character_id', $owner->getKey())->lockForUpdate()->first();

            if ($party === null) {
                return PartyRefusal::NoParty;
            }

            // The terms are what an invitation promised: once anyone holds one, they cannot be swapped for worse.
            if ($party->members()->exists()) {
                return PartyRefusal::TermsLocked;
            }

            $party->update(['share_credits' => $credits, 'share_extraction' => $extraction]);

            return null;
        });
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

            if ($party->started_at !== null) {
                return PartyRefusal::Started;
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

            if ($party->started_at !== null) {
                return PartyRefusal::Started;
            }

            if ($at === null || $at->x !== $party->x || $at->y !== $party->y) {
                return PartyRefusal::NotAtTheDoor;
            }

            // Run-only software the member actually holds, as row ids. What may START is ProgramRunner's, at launch.
            $mine = $loadout === null ? ($member->loadout ?? []) : $this->oneIcebreaker(InventoryItem::query()
                ->where('character_id', $character->getKey())->whereIn('id', $loadout)->with('item.program')->get());

            $member->update(['joined_at' => $member->joined_at ?? now(), 'loadout' => $mine]);

            return null;
        });
    }

    /**
     * Only one icebreaker may go in, as on the owner's own form: the first of any more is kept.
     *
     * @param  Collection<int, InventoryItem>  $rows
     * @return list<int>
     */
    private function oneIcebreaker($rows): array
    {
        $breaker = false;

        return $rows->filter(function (InventoryItem $row) use (&$breaker): bool {
            if ($row->item?->program?->kind !== ProgramKind::Icebreaker) {
                return true;
            }

            return $breaker ? false : ($breaker = true);
        })->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * Accept the invitation to THIS party (the id from a `run:<id>` link). There are no open invitations on the grid:
     * a link only works for somebody the owner has actually invited, by handle or by pasting it in a private line.
     */
    public function acceptInto(Character $character, RunParty $party): ?PartyRefusal
    {
        $invited = RunPartyMember::query()->where('character_id', $character->getKey())->where('run_party_id', $party->getKey())->exists();

        return $invited ? $this->accept($character) : PartyRefusal::NotInvited;
    }

    /**
     * A line the owner posted in a direct line or a private room carries `run:<id>`: everybody else in that chat is
     * invited, as far as the slots go. A public room, or General, invites nobody.
     */
    public function inviteFromChat(Character $owner, string $text, Chat $chat): void
    {
        if ($chat->kind === Chat::GENERAL || ($chat->kind === Chat::ROOM && $chat->is_public)) {
            return;
        }

        if (preg_match_all('/\brun:(\d{1,10})\b/', $text, $m) < 1) {
            return;
        }

        $party = RunParty::query()->where('owner_character_id', $owner->getKey())->whereIn('id', array_unique($m[1]))->first();

        if ($party === null) {
            return;
        }

        $handles = Character::query()
            ->whereIn('id', DB::table('chat_members')->where('chat_id', $chat->getKey())->pluck('character_id'))
            ->whereKeyNot($owner->getKey())->pluck('handle');

        foreach ($handles as $handle) {
            $this->invite($owner, (string) $handle);
        }
    }

    /** Declining an invitation, leaving the party, or (for the owner) disbanding it: all one door. */
    public function leave(Character $character): void
    {
        // A party with runs still inside stays until the last is out: the others' rounds are settled through it.
        RunParty::query()->where('owner_character_id', $character->getKey())
            ->whereNotIn('id', Run::query()->live()->whereNotNull('run_party_id')->select('run_party_id'))->delete();
        // A member the launch left out stays until the party is over, so they cannot start a run of their own beside it.
        RunPartyMember::query()->where('character_id', $character->getKey())->whereNull('dropped_at')->delete();
    }

    /** The owner left cyberspace: a party still forming at the door goes with them, invitations and all. */
    public function abandon(Character $owner): void
    {
        RunParty::query()->where('owner_character_id', $owner->getKey())->whereNull('started_at')->delete();
    }

    /** The party this character owns or is invited to / in, if any. */
    public function partyOf(Character $character): ?RunParty
    {
        RunPartyMember::query()->where('character_id', $character->getKey())->whereNull('joined_at')
            ->where('created_at', '<', now()->subMinutes(self::INVITE_MINUTES))->delete();

        return RunParty::query()->where('owner_character_id', $character->getKey())->first()
            ?? RunPartyMember::query()->where('character_id', $character->getKey())->first()?->party;
    }

    /**
     * Why this runner could not go in with the party right now, or null when they could: not at the door, or any of
     * the refusals a start would give. THE launch asks it and so does the screen, so what a member is shown is what
     * the owner's Start will do.
     */
    public function obstacle(Character $runner, RunParty $party, RunStarter $starter): ?string
    {
        $at = $runner->gridPosition();

        if ($at === null || $at->x !== $party->x || $at->y !== $party->y) {
            return PartyRefusal::NotAtTheDoor->message();
        }

        $refusal = $starter->occupied($runner);

        if ($refusal === null && System::query()->at($at)->first()?->isLockedDown()) {
            $refusal = RunRefusal::LockedDown;
        }

        if ($refusal === null && ! $starter->deckReady($runner)) {
            $refusal = RunRefusal::DeckNotReady;
        }

        return $refusal?->message();
    }

    /**
     * THE OWNER BEGINS IT, and every joined member goes in at once with the loadout they chose at the door.
     *
     * Each runner gets their OWN run (own approach roll, own trace, own loadout) tied together by `runs.run_party_id`.
     * The owner's run starts first and decides it: a refusal there starts nobody. A member who cannot start (deck not
     * ready, moved off the door, a program that will not start) is dropped from the party, told, and the rest go.
     */
    public function launch(Character $owner, RunPurpose $purpose, ?BackdoorKind $kind, array $ownerLoadout, RunStarter $starter, array $ownerSuspend = []): RunResult
    {
        $party = RunParty::query()->where('owner_character_id', $owner->getKey())->first();

        if ($party === null || $party->started_at !== null) {
            return $starter->start($owner, $purpose, $kind, $ownerLoadout, null, $ownerSuspend);
        }

        // The party forms at the door: an owner who walked off does not take it with them.
        $here = $owner->gridPosition();

        if ($here === null || $here->x !== $party->x || $here->y !== $party->y) {
            return RunResult::refused(RunRefusal::NothingThere);
        }

        $result = $starter->start($owner, $purpose, $kind, $ownerLoadout, $party->getKey(), $ownerSuspend);

        if (! $result->succeeded()) {
            return $result;
        }

        $party->update(['started_at' => now()]);

        $in = [$owner];
        $left = [];

        foreach ($party->members()->whereNotNull('joined_at')->with('character')->get() as $member) {
            $runner = $member->character;
            $why = $runner === null ? null : $this->obstacle($runner, $party, $starter);

            if ($runner !== null && $why === null) {
                $rows = InventoryItem::query()->whereIn('id', $member->loadout ?? [])
                    ->where('character_id', $runner->getKey())->with('item.program')->get()->all();
                $started = $starter->start($runner, $purpose, $kind, $rows, $party->getKey());
                $why = $started->succeeded() ? null : $started->message();
            }

            if ($runner === null || $why !== null) {
                $member->update(['dropped_at' => now()]);

                if ($runner !== null) {
                    $left[$runner->handle] = $why;
                    (new Notifier)->send($runner, Category::Cyberspace, 'party_dropped', ['owner' => $owner->handle]);
                }
            } else {
                $in[] = $runner;
                (new Notifier)->send($runner, Category::Cyberspace, 'party_started', ['owner' => $owner->handle]);
            }
        }

        // Detection is the party's: everybody goes in seeing the same state, not each their own approach roll until round one.
        $partyTurns = app(PartyTurns::class);
        $partyTurns->syncShared($partyTurns::liveRuns($party->getKey()));

        // Everybody who went in is told who did not, and why.
        foreach ($left as $handle => $reason) {
            foreach ($in as $participant) {
                (new Notifier)->send($participant, Category::Cyberspace, 'party_member_dropped', ['handle' => $handle, 'reason' => $reason]);
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
