<?php

declare(strict_types=1);

namespace App\Missions;

use App\Cyberspace\ScanFile;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Mission;
use App\Models\MissionTarget;
use App\Models\System;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Standing\LedgerKind;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use App\Stats\Resolver;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * The only thing that moves a mission through its states.
 *
 * `MissionBoard` rolls them and this does everything afterwards: taking one,
 * turning it down, giving it up, handing it in, and the two endings nobody
 * chose. Every one of those is a transaction, because each moves a job and
 * something else — money, standing, a stack of files — together.
 *
 * ## Failing and voiding are different, and that is the whole design
 *
 * FAILING is the clock running out on somebody who took the work, or walking
 * away from it. It costs standing, and **abandoning costs exactly what
 * expiring costs** — if giving up were free nobody would ever do it, they
 * would sit on the job and let it run out, and the game would have charged
 * them four hours for the same outcome.
 *
 * VOIDING is the job's subject ceasing to exist: burned by somebody else,
 * relocated out from under them, shut for longer than the window. None of it
 * is the runner's fault and none of it costs anything.
 *
 * ## The reward is resolved, the penalty is not
 *
 * `standing_reward` on the row is a BASE, handed to the sheet as
 * `standing_gain`, so the first skill that makes somebody better at being
 * liked is a modifier and nothing here learns it exists. The PENALTY is flat:
 * being bad at consequences is not a thing to be good at.
 */
final readonly class MissionLedger
{
    /**
     * The resolver is injected because the standing base is PER MISSION.
     *
     * `standing_gain` is deliberately not in `stats.bases` — there is no such
     * thing as a character's standing gain in the abstract, only what this job
     * at this desk was worth when it was offered. So the base is handed in at
     * the call site, exactly as `scan_duration` and `icebreaker_damage` are.
     */
    public function __construct(private Resolver $resolver) {}

    /** Take the job. The clock starts here and nowhere else. */
    public function accept(Character $character, Mission $mission): MissionRefusal|Mission
    {
        if ($mission->character_id !== $character->getKey()) {
            return MissionRefusal::NotYours;
        }

        return DB::transaction(function () use ($character, $mission): MissionRefusal|Mission {
            $live = Mission::query()->whereKey($mission->getKey())->lockForUpdate()->first();

            if ($live === null || $live->state !== MissionState::Offered) {
                return MissionRefusal::Gone;
            }

            $desk = $live->exchange()->first();
            $standing = (new StandingLedger)->standingFor($character, $live->entity()->first());

            // The same gate the shelf reads: an agent who will not sell to
            // somebody will not employ them either.
            if ($desk !== null && ! $desk->willDealWith($standing)) {
                return MissionRefusal::NotWelcome;
            }

            $cap = (int) config('missions.active_per_entity', 1);

            $working = Mission::query()
                ->where('character_id', $character->getKey())
                ->where('entity_id', $live->entity_id)
                ->accepted()
                ->count();

            if ($working >= $cap) {
                return MissionRefusal::AlreadyWorking;
            }

            $hours = random_int(
                (int) config('missions.window_hours.min', 4),
                (int) config('missions.window_hours.max', 24),
            );

            $live->forceFill([
                'state' => MissionState::Accepted,
                'accepted_at' => now(),
                'expires_at' => now()->addHours($hours),
            ])->save();

            $accepted = $live->fresh();
            $this->briefing($character, $accepted);

            return $accepted;
        });
    }

    /**
     * The job, written down: what it is, where, what it pays and when it is due, kept in the inbox.
     *
     * An EXCEPTION to the inbox's rule that it holds what happened while nobody was looking: the player pressed the
     * button. But the desk's board is gone once it is closed, the window is hours, and the addresses are what a runner
     * works from, so a record to come back to is worth the exception. Written as it stood at acceptance and never
     * recomposed, like every message: a target that has since gone is still the one the job named.
     */
    private function briefing(Character $character, Mission $mission): void
    {
        $mission->loadMissing('targets.system', 'entity');

        $targets = $mission->targets->map(static fn ($target): array => [
            'address' => (string) $target->address,
            'grade' => $target->system?->grade,
        ])->all();

        $what = $mission->action->designatesTargets()
            ? Text::get('notifications.mission_what_systems', ['count' => count($targets), 'addresses' => implode(', ', array_column($targets, 'address'))])
            : Text::get('notifications.mission_what_supply', ['count' => $mission->quantity_required]);

        (new Notifier)->send($character, Category::Mission, 'mission_accepted', [
            'action' => $mission->action->label(),
            'entity' => (string) ($mission->entity?->name ?? ''),
            'what' => $what,
            'creds' => Number::format((int) $mission->creds_reward),
            'standing' => number_format((float) $mission->standing_reward, 3),
            'loyalty' => (int) $mission->loyalty_reward,
            'penalty' => number_format((float) $mission->penalty, 3),
            'due' => $mission->expires_at?->format('Y-m-d H:i').' UTC',
        ], $targets === [] ? null : ['targets' => $targets]);
    }

    /**
     * Turn it down.
     *
     * The row is DELETED rather than marked, because an offer nobody took is
     * not history. What it costs is the offer itself until the board refreshes
     * — which is what makes the hour a real decision.
     */
    public function decline(Character $character, Mission $mission): MissionRefusal|bool
    {
        if ($mission->character_id !== $character->getKey()) {
            return MissionRefusal::NotYours;
        }

        if ($mission->state !== MissionState::Offered) {
            return MissionRefusal::Gone;
        }

        $mission->delete();

        return true;
    }

    /** Give up work already taken. Costs what letting the clock run out costs. */
    public function abandon(Character $character, Mission $mission): MissionRefusal|Mission
    {
        if ($mission->character_id !== $character->getKey()) {
            return MissionRefusal::NotYours;
        }

        if ($mission->state !== MissionState::Accepted) {
            return MissionRefusal::Gone;
        }

        return $this->fail($mission, 'mission_abandoned');
    }

    /**
     * Hand it in.
     *
     * TWO KINDS OF EVIDENCE MEET HERE. A designating job is judged on its
     * target rows, which were filled by the acts themselves; supply is judged
     * on what the runner is carrying, and the files are spent in this
     * transaction. Either way the rewards land once.
     */
    public function complete(Character $character, Mission $mission): MissionRefusal|Mission
    {
        if ($mission->character_id !== $character->getKey()) {
            return MissionRefusal::NotYours;
        }

        return DB::transaction(function () use ($character, $mission): MissionRefusal|Mission {
            $live = Mission::query()->whereKey($mission->getKey())->lockForUpdate()->first();

            if ($live === null || $live->state !== MissionState::Accepted) {
                return MissionRefusal::Gone;
            }

            if ($live->hasExpired()) {
                return MissionRefusal::Expired;
            }

            // A target that stopped existing voids the job even at the desk:
            // the runner walked here for nothing, and that is not a failure.
            if (! $this->retarget($live)) {
                $this->void($live);

                return MissionRefusal::Gone;
            }

            if ($live->action === MissionAction::Supply) {
                $spent = $this->spendFiles($character, $live);

                if ($spent === false) {
                    return MissionRefusal::NotDone;
                }
            } elseif ($live->action === MissionAction::Scan) {
                // A survey is handed in: one whole, unexpired file for every system it names.
                if (! $this->handInSurvey($character, $live)) {
                    return MissionRefusal::NotDone;
                }
            } elseif (! $live->targetsSatisfied()) {
                return MissionRefusal::NotDone;
            }

            $this->pay($character, $live);

            $live->forceFill([
                'state' => MissionState::Completed,
                'completed_at' => now(),
            ])->save();

            return $live->fresh();
        });
    }

    /**
     * Mark one target done, from wherever the act happened.
     *
     * CALLED BY THE ACT, NOT BY THE PLAYER — `Burner` and `Backdoors` reach
     * this inside their own transactions. It is deliberately quiet: a runner
     * who burns a machine they happen to owe somebody is not interrupted to be
     * told, and the desk is where they find out it counted.
     *
     * A target already satisfied is not satisfied twice, and a system nobody
     * has a job against is an ordinary no-op — most burns are nobody's
     * mission.
     */
    public function recordAgainst(Character $character, System $system, MissionAction $action): int
    {
        $targets = $this->openTargets($character, $system, $action)->get();

        foreach ($targets as $target) {
            $target->forceFill(['satisfied_at' => now()])->save();
        }

        return $targets->count();
    }

    /** Whether an accepted job of this kind still has this system to do: what `recordAgainst()` would record. Writes nothing. */
    public function hasOpenAgainst(Character $character, System $system, MissionAction $action): bool
    {
        return $this->openTargets($character, $system, $action)->exists();
    }

    /** @return Builder<MissionTarget> */
    private function openTargets(Character $character, System $system, MissionAction $action)
    {
        return MissionTarget::query()
            ->whereNull('satisfied_at')
            ->where('system_id', $system->getKey())
            ->where('address', $system->address)
            ->whereHas('mission', fn ($query) => $query
                ->where('character_id', $character->getKey())
                ->where('action', $action->value)
                ->accepted()
            );
    }

    /**
     * The clock ran out on everything it has run out on.
     *
     * A SWEEP AND NOT LAZY, for `cyberspace:lockdowns`' reason: the penalty
     * has to land whether or not the player ever comes back, and somebody who
     * never opens the screen again should still answer for the job they took.
     *
     * A mission whose target has since gone VOIDS rather than fails, even
     * here — the clock running out on a job that became impossible is still
     * not the runner's fault.
     *
     * @return array{failed: int, voided: int}
     */
    public function sweepExpired(): array
    {
        $failed = 0;
        $voided = 0;

        $due = Mission::query()
            ->accepted()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with('targets')
            ->cursor();

        foreach ($due as $mission) {
            if (! $this->retarget($mission)) {
                $this->void($mission);
                $voided++;

                continue;
            }

            $this->fail($mission, 'mission_failed');
            $failed++;
        }

        return ['failed' => $failed, 'voided' => $voided];
    }

    /**
     * Make sure every outstanding target still stands, handing out a new one where it does not.
     *
     * A target that relocated or was burned is replaced by a system of the same grade and type, nearest the old
     * position (any in the desk's band when the old row is gone), never one the job already names, a fortress or an
     * abandoned system. The deadline is kept. Returns false only when nothing could stand in, which is the old
     * void. Detected by the snapshot, as before: the row is gone, or its address is no longer the one written down.
     */
    public function retarget(Mission $mission): bool
    {
        $mission->loadMissing('targets');
        $character = $mission->character()->first();

        foreach ($mission->targets->whereNull('satisfied_at') as $target) {
            if ($target->stillStands()) {
                continue;
            }

            $old = $target->system()->first();
            $desk = $mission->exchange()->first();

            $query = System::query()
                ->whereIn('type', MissionBoard::targetableTypes())
                ->where('type', '!=', 'fortress')
                ->whereNull('abandoned_at')
                ->whereNotIn('id', $mission->targets->pluck('system_id')->filter()->all())
                ->where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', '!=', $mission->entity_id));

            if ($old !== null) {
                $query->where('grade', $old->grade)->where('type', $old->type)
                    ->orderByRaw('ABS(CAST(x AS SIGNED) - ?) + ABS(CAST(y AS SIGNED) - ?)', [$old->x, $old->y]);
            } elseif ($desk !== null) {
                [$low, $high] = $desk->targetGrades();
                $query->whereBetween('grade', [$low, $high])->inRandomOrder();
            } else {
                return false;
            }

            $new = $query->orderBy('id')->first();

            if ($new === null) {
                return false;
            }

            $was = (string) $target->address;
            $target->forceFill(['system_id' => $new->getKey(), 'address' => $new->address])->save();

            if ($character !== null) {
                (new Notifier)->send($character, Category::Mission, 'mission_retargeted', [
                    'old' => $was,
                    'new' => (string) $new->address,
                ], ['targets' => [['address' => (string) $new->address, 'grade' => $new->grade]]]);
            }
        }

        return true;
    }

    /**
     * The scan files a runner could hand in: loose in the stash, whole and unexpired. The one definition, shared by a supply
     * job, a survey and the desk's card, so what the card says is in hand is what the hand-in would take.
     *
     * @return Collection<int, InventoryItem>
     */
    public function heldFiles(Character $character): Collection
    {
        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('scan_file')
            ->loose()
            ->orderBy('id')
            ->get()
            ->filter(function (InventoryItem $row): bool {
                $file = $row->scanFile();

                return $file !== null && ! $file->hasExpired() && $this->isWholePicture($file);
            })
            ->values();
    }

    /**
     * The SEALED data dumps in hand: loose Encrypted Data Dumps, which a supply job takes as files too. A decrypted one
     * (a Memory Dump) is worth more to a buyer than to a desk that only wants bulk, so it does not count.
     *
     * @return Collection<int, InventoryItem>
     */
    public function heldDumps(Character $character): Collection
    {
        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('extracted_file')
            ->whereHas('item', static fn ($q) => $q->where('slug', 'encrypted-data-dump'))
            ->loose()
            ->orderBy('id')
            ->get();
    }

    /** How many files a supply job could be handed right now: whole scan files and sealed dumps. */
    public function suppliable(Character $character): int
    {
        return $this->heldFiles($character)->count() + $this->heldDumps($character)->count();
    }

    /**
     * The addresses of the files in hand, for a survey's card to tick off.
     *
     * @return list<string>
     */
    public function heldAddresses(Character $character): array
    {
        return $this->heldFiles($character)->map(static fn (InventoryItem $row): string => (string) $row->scanFile()?->address)->unique()->values()->all();
    }

    /**
     * A SURVEY, handed in: for every system the job names that is not already satisfied, one whole, unexpired file of THAT
     * system (made before the job or during it). All or nothing, like a supply job, and the files are spent so one file cannot
     * be sold twice. Writes `satisfied_at` on the targets: the files are the evidence and the targets are the receipt.
     */
    private function handInSurvey(Character $character, Mission $mission): bool
    {
        $held = $this->heldFiles($character);
        $spending = [];

        foreach ($mission->targets()->whereNull('satisfied_at')->get() as $target) {
            $row = $held->first(static fn (InventoryItem $candidate): bool => $candidate->scanFile()?->address === $target->address
                && ! in_array($candidate->getKey(), array_map(static fn (array $pair): mixed => $pair[1]->getKey(), $spending), true));

            if ($row === null) {
                return false;
            }

            $spending[] = [$target, $row];
        }

        foreach ($spending as [$target, $row]) {
            $target->forceFill(['satisfied_at' => now()])->save();
            $row->delete();
        }

        return true;
    }

    /**
     * Files, counted and spent.
     *
     * ONLY COMPLETE FILES COUNT and only unexpired ones — a one-pass file that
     * learned the grade and nothing else is not intel. Asking for more than
     * the runner has is refused rather than partly taken, the rule the shelf
     * and the offer book both keep.
     */
    private function spendFiles(Character $character, Mission $mission): bool
    {
        // Scan files first, then sealed dumps: a scan perishes and a dump keeps.
        $held = $this->heldFiles($character)->concat($this->heldDumps($character));

        if ($held->count() < $mission->quantity_required) {
            return false;
        }

        $spending = $held->take($mission->quantity_required);

        foreach ($spending as $row) {
            $file = $row->scanFile();

            // THE TARGET ROWS ARE THE RECEIPT RATHER THAN THE ASSIGNMENT.
            // Written at hand-in, one per file accepted, so the mission still
            // records exactly which systems were sold.
            $system = $file === null ? null : System::query()->where('address', $file->address)->first();

            if ($system !== null) {
                $mission->targets()->create([
                    'system_id' => $system->getKey(),
                    'address' => $file->address,
                    'satisfied_at' => now(),
                ]);
            }

            $row->delete();
        }

        return true;
    }

    /**
     * Is this file the whole picture?
     *
     * Asked of the FILE rather than of a scan row, because by the time one is
     * tradeable the read it came from is spent. `ScanFile::toScan()` hands the
     * facts back in the shape `SystemScan::isComplete()` already reads.
     */
    private function isWholePicture(ScanFile $file): bool
    {
        return $file->toScan()->isComplete();
    }

    /** Creds onto the character, standing and loyalty into the entity's books. */
    private function pay(Character $character, Mission $mission): void
    {
        $entity = $mission->entity()->first();

        Wallet::move($character, (int) $mission->creds_reward, Reason::Mission, $entity?->name);

        $ledger = new StandingLedger;

        // The BASE is on the row; what lands is resolved, so a perk or an
        // implant that makes somebody better at being liked moves it without
        // this method knowing such a thing exists.
        $standing = $this->resolver
            ->resolve('standing_gain', (float) $mission->standing_reward, $character->modifiers())
            ->value;

        $ledger->record($character, $entity, StandingReason::MissionCompleted, $standing);
        $ledger->record($character, $entity, StandingReason::MissionCompleted, (float) $mission->loyalty_reward, LedgerKind::Loyalty);
    }

    /** An ending somebody is answerable for. */
    private function fail(Mission $mission, string $message): Mission
    {
        return DB::transaction(function () use ($mission, $message): Mission {
            $character = $mission->character()->first();
            $entity = $mission->entity()->first();

            if ($character !== null) {
                // Flat, and deliberately not resolved: being bad at
                // consequences is not a thing to be good at.
                (new StandingLedger)->record(
                    $character,
                    $entity,
                    StandingReason::MissionCompleted,
                    -abs((float) $mission->penalty),
                );

                $this->tell($character, $mission, $message);
            }

            $mission->forceFill([
                'state' => MissionState::Failed,
                'completed_at' => now(),
            ])->save();

            return $mission->fresh();
        });
    }

    /** An ending nobody is answerable for, and it costs nothing at all. */
    private function void(Mission $mission): Mission
    {
        $character = $mission->character()->first();

        if ($character !== null) {
            $this->tell($character, $mission, 'mission_voided');
        }

        $mission->forceFill([
            'state' => MissionState::Voided,
            'completed_at' => now(),
        ])->save();

        return $mission->fresh();
    }

    /**
     * Say what happened, when it happened to somebody who was not there.
     *
     * A job expiring or being voided while its owner is asleep is exactly what
     * the inbox is for. Abandoning is told too, because the sentence is the
     * same one and a flash message does not survive the page.
     */
    private function tell(Character $character, Mission $mission, string $key): void
    {
        (new Notifier)->send($character, Category::Mission, $key, [
            'action' => $mission->action->label(),
            'entity' => (string) ($mission->entity()->first()?->name ?? ''),
        ]);
    }
}
