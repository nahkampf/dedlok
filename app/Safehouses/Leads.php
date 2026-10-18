<?php

declare(strict_types=1);

namespace App\Safehouses;

use App\Characters\Fatigue;
use App\Characters\Mood;
use App\Checks\CheckRoller;
use App\City\ActivityKind;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\Safehouse;
use App\Models\SafehouseLead;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Support\Points;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Finding another player's home, working out who lives there, and getting into it. FATIGUE.md, *Raiding and burning*.
 * The only writer of `safehouse_leads` and of a stakeout's or a scouting's activity row.
 *
 * A pass of exploring may SENSE one (`discover()`): a random held home in the district the character has no lead on. The
 * lead starts at discovery 0 and the finder is told only that something is off. Stakeouts (passes of a minute, until
 * stopped or done) and asking around raise discovery; past the home's stealth the owner is known. Scouting passes raise
 * the security figure the same way. A break-in is a check whose difficulty falls to its base once security is scouted past
 * the home's. Inside, they may rob it (a few stored rows) or burn it (the holder loses it and what was stored, at once).
 * A lead dies with the holding: it is deleted whenever its target no longer holds that home.
 */
final class Leads
{
    /** @param  ?Closure(int, int): int  $roll  an integer die */
    public function __construct(
        private readonly CheckRoller $roller,
        private readonly Fatigue $fatigue = new Fatigue,
        private readonly Mood $mood = new Mood,
        private readonly ?Closure $roll = null,
    ) {}

    private function die(int $min, int $max): int
    {
        return $this->roll !== null ? ($this->roll)($min, $max) : random_int($min, $max);
    }

    /** Called by a finished explore pass, on the locked character. Returns the message key when one was sensed. */
    public function discover(Character $locked): ?string
    {
        $candidates = Character::query()
            ->whereKeyNot($locked->getKey())
            ->whereIn('safehouse_id', Safehouse::query()->where('district_id', $locked->district_id)->where('slug', '!=', (string) Config::get('safehouse.fallback'))->select('id'))
            ->whereNotIn('id', SafehouseLead::query()->where('character_id', $locked->getKey())->select('target_id'))
            ->inRandomOrder()->first();

        if ($candidates === null) {
            return null;
        }

        if (! $this->roller->roll((float) $locked->stat('exploration')->value, (float) Config::get('safehouse.leads.find.difficulty'), 'safehouse_find')->passed) {
            return null;
        }

        SafehouseLead::query()->create([
            'character_id' => $locked->getKey(),
            'target_id' => $candidates->getKey(),
            'safehouse_id' => $candidates->safehouse_id,
            'number' => 1 + (int) SafehouseLead::query()->where('character_id', $locked->getKey())
                ->whereIn('safehouse_id', Safehouse::query()->where('district_id', $locked->district_id)->select('id'))->max('number'),
        ]);

        return 'safehouse_sensed';
    }

    /** Drop leads whose holder no longer holds that home; step outside any the finder has left behind. */
    public function prune(Character $character): void
    {
        SafehouseLead::query()->where('character_id', $character->getKey())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('characters as t')->whereColumn('t.id', 'safehouse_leads.target_id')->whereColumn('t.safehouse_id', 'safehouse_leads.safehouse_id'))
            ->delete();

        SafehouseLead::query()->where('character_id', $character->getKey())->where('inside', true)
            ->whereIn('safehouse_id', Safehouse::query()->where('district_id', '!=', $character->district_id)->select('id'))
            ->update(['inside' => false]);
    }

    /** @return Collection<int, SafehouseLead> */
    public function all(Character $character): Collection
    {
        $this->prune($character);

        return SafehouseLead::query()->where('character_id', $character->getKey())->with('safehouse.district', 'target')->orderBy('id')->get();
    }

    /** @return Collection<int, SafehouseLead> */
    public function inDistrict(Character $character, int $districtId): Collection
    {
        return $this->all($character)->filter(fn (SafehouseLead $l): bool => $l->safehouse->district_id === $districtId)->values();
    }

    /** What stops them acting on a lead now, or null. */
    private function refusal(Character $locked, SafehouseLead $lead, bool $idle = true): ?Refusal
    {
        if ($locked->isJackedIn()) {
            return Refusal::JackedIn;
        }

        if ($locked->current_fight_id !== null) {
            return Refusal::Fighting;
        }

        if ($idle && CityActivity::query()->whereKey($locked->getKey())->exists()) {
            return Refusal::Busy;
        }

        return $lead->safehouse->district_id === $locked->district_id ? null : Refusal::NotHere;
    }

    /** Lock the character and re-read the lead inside the lock. @return array{0: Character, 1: SafehouseLead}|Refusal */
    private function open(Character $character, SafehouseLead $lead, bool $idle = true): array|Refusal
    {
        $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
        $this->prune($locked);
        $fresh = SafehouseLead::query()->where('character_id', $locked->getKey())->whereKey($lead->getKey())->lockForUpdate()->with('safehouse.district', 'target')->first();

        if ($fresh === null) {
            return Refusal::NotHere;
        }

        return $this->refusal($locked, $fresh, $idle) ?? [$locked, $fresh];
    }

    private function moved(Character $locked, bool $good, string $event = 'lead_success'): void
    {
        $this->mood->event($locked, $good ? $event : 'lead_failure');
    }

    /** Ask around: instant, random. */
    public function askAround(Character $character, SafehouseLead $lead): Raid|Refusal
    {
        return DB::transaction(function () use ($character, $lead): Raid|Refusal {
            $opened = $this->open($character, $lead);

            if ($opened instanceof Refusal) {
                return $opened;
            }

            [$locked, $lead] = $opened;

            if ($lead->isKnown()) {
                return Refusal::Identified;
            }

            $this->fatigue->charge($locked, 'ask_around');
            $set = (array) Config::get('safehouse.leads.ask');
            $roll = $this->die(1, $set['nothing'] + $set['clues'] + $set['misdirected'] + $set['nutjob']);
            $good = false;

            if ($roll <= $set['nothing']) {
                $message = 'ask_nothing';
            } elseif ($roll <= $set['nothing'] + $set['clues']) {
                $message = 'ask_clues';
                $good = true;
                $this->spend($locked, $set['clue_creds']);
                $this->raise($lead, 'discovery', (float) $set['clue_gain']);
            } elseif ($roll <= $set['nothing'] + $set['clues'] + $set['misdirected']) {
                $message = 'ask_misdirected';
                $this->raise($lead, 'discovery', -(float) $set['misdirected_loss']);
            } else {
                $message = 'ask_nutjob';
                $this->spend($locked, $set['nutjob_creds']);
            }

            $this->moved($locked, $good);
            $character->refresh();

            return new Raid($good, Text::get("safehouse.lead.{$message}").($good && $lead->isKnown() ? ' '.Text::get('safehouse.lead.now_known') : ''));
        });
    }

    /** Pay what the asking cost, never into debt. @param list<int> $range */
    private function spend(Character $locked, array $range): void
    {
        $amount = min((int) $locked->creds, $this->die($range[0], $range[1]));

        if ($amount > 0) {
            Wallet::move($locked, -$amount, Reason::Inquiry, Text::get('wallet.notes.inquiry'));
        }
    }

    private function raise(SafehouseLead $lead, string $column, float $by): void
    {
        $lead->forceFill([$column => Points::of(max(0, $lead->{$column} + $by))])->save();
    }

    /** Begin a stakeout (watching, to learn who lives there) or a scouting (to learn how it is guarded). */
    public function begin(Character $character, SafehouseLead $lead, ActivityKind $kind): true|Refusal
    {
        return DB::transaction(function () use ($character, $lead, $kind): true|Refusal {
            $opened = $this->open($character, $lead);

            if ($opened instanceof Refusal) {
                return $opened;
            }

            [$locked, $lead] = $opened;

            if ($kind === ActivityKind::Stakeout && $lead->isKnown()) {
                return Refusal::Identified;
            }

            if ($kind === ActivityKind::ScopeOut && ! $lead->isKnown()) {
                return Refusal::NotIdentified;
            }

            if ($kind === ActivityKind::ScopeOut && $lead->isCleared()) {
                return Refusal::NothingToDo;
            }

            CityActivity::query()->create([
                'character_id' => $locked->getKey(), 'kind' => $kind, 'lead_id' => $lead->getKey(), 'step' => 0, 'paid' => 0, 'cancelling' => false,
                'pass_ends_at' => CarbonImmutable::now()->addSeconds((int) Config::get('safehouse.leads.watch_seconds')),
            ]);

            return true;
        });
    }

    /** Stop a stakeout or scouting, keeping what it earned. */
    public function stop(Character $character): true|Refusal
    {
        $activity = CityActivity::query()->whereKey($character->getKey())->whereIn('kind', [ActivityKind::Stakeout->value, ActivityKind::ScopeOut->value])->first();

        if ($activity === null) {
            return Refusal::NotWatching;
        }

        $this->settle($character);
        CityActivity::query()->whereKey($character->getKey())->whereIn('kind', [ActivityKind::Stakeout->value, ActivityKind::ScopeOut->value])->delete();

        return true;
    }

    /**
     * Credit the minutes of a stakeout or scouting that have passed, one pass each, and end it when it has done its work.
     * Safe to call for anybody. @return list<array{0: string, 1: array<string, string>}> message key and replacements
     */
    public function settle(Character $character, ?CarbonInterface $now = null, bool $notify = false): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $messages = [];
        $this->prune($character);

        DB::transaction(function () use ($character, $now, $notify, &$messages): void {
            $activity = CityActivity::query()->whereKey($character->getKey())
                ->whereIn('kind', [ActivityKind::Stakeout->value, ActivityKind::ScopeOut->value])->lockForUpdate()->first();

            if ($activity === null || $activity->pass_ends_at > $now) {
                return;
            }

            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $lead = SafehouseLead::query()->whereKey($activity->lead_id)->lockForUpdate()->with('safehouse.district', 'target')->first();
            $watching = $activity->kind === ActivityKind::Stakeout;
            $ended = $lead === null;

            for ($n = 0; ! $ended && $activity->pass_ends_at <= $now && $n < (int) Config::get('safehouse.leads.max_catchup'); $n++) {
                $last = $this->pass($locked, $lead, $watching);
                $activity->pass_ends_at = CarbonImmutable::instance($activity->pass_ends_at)->addSeconds((int) Config::get('safehouse.leads.watch_seconds'));

                if ($watching ? $lead->isKnown() : $lead->isCleared()) {
                    $messages[] = [$watching ? 'safehouse_identified' : 'safehouse_scoped', ['place' => $lead->name()]];
                    $ended = true;
                } elseif ($locked->collapse_pending) {
                    $ended = true;
                }
            }

            // What the last scouting pass turned up, for the page: said on a look, never sent to the inbox.
            if (! $watching && ! $notify && isset($last) && ! $lead->isCleared()) {
                $messages[] = [$last ? 'scope_success' : 'scope_fail', []];
            }

            $ended ? $activity->delete() : $activity->save();
            $character->refresh();
        });

        return $messages;
    }

    /** One minute of watching or scouting. */
    private function pass(Character $locked, SafehouseLead $lead, bool $watching): bool
    {
        $this->fatigue->charge($locked, $watching ? 'stakeout' : 'scope_out');
        $this->mood->event($locked, 'watch_pass');

        if ($watching) {
            $set = (array) Config::get('safehouse.leads.stakeout');
            $roll = $this->die(1, 100);
            $gain = $roll <= $set['nothing'] ? 0.0 : ($roll <= $set['nothing'] + $set['small'] ? (float) $set['small_gain'] : (float) $set['large_gain']);
            $this->raise($lead, 'discovery', $gain);
            $this->moved($locked, $gain > 0);

            return $gain > 0;
        }

        $passed = $this->roller->roll($this->value($locked), $lead->safehouse->security * (float) Config::get('safehouse.leads.difficulty_scale'))->passed;

        if ($passed) {
            $this->raise($lead, 'security', (float) Config::get('safehouse.leads.scope_gain'));
        }

        $this->moved($locked, $passed);

        return $passed;
    }

    /** What they bring to getting past a lock: stealth, and a share of Perception and Senses. */
    private function value(Character $locked): float
    {
        return (float) $locked->stat('stealth')->value
            + (float) Config::get('safehouse.leads.attribute_weight') * ((float) $locked->stat('perception')->value + (float) $locked->stat('senses')->value);
    }

    /** Try the door. Failing warns the holder and costs the same. */
    public function breakIn(Character $character, SafehouseLead $lead): Raid|Refusal
    {
        return DB::transaction(function () use ($character, $lead): Raid|Refusal {
            $opened = $this->open($character, $lead);

            if ($opened instanceof Refusal) {
                return $opened;
            }

            [$locked, $lead] = $opened;

            if (! $lead->isKnown()) {
                return Refusal::NotIdentified;
            }

            if ($this->cooling($lead)) {
                return Refusal::Cooldown;
            }

            $this->fatigue->charge($locked, 'break_in');

            $security = (float) $lead->safehouse->security;
            $gap = $lead->isCleared() ? 0.0 : max(0.0, $security - $lead->security) * (float) Config::get('safehouse.leads.gap_weight');
            $difficulty = ($security + $gap) * (float) Config::get('safehouse.leads.difficulty_scale');

            if (! $this->roller->roll($this->value($locked), $difficulty)->passed) {
                (new Notifier)->send($lead->target, Category::City, 'safehouse_raid_failed', ['place' => app(Renter::class)->name($lead->safehouse)]);
                $this->mood->event($locked, 'break_in_failed');

                // Sometimes the police get there: what was scouted is lost.
                $police = $this->die(1, 100) <= (int) Config::get('safehouse.leads.police_chance');

                if ($police) {
                    $lead->forceFill(['security' => 0])->save();
                }

                $character->refresh();

                return new Raid(false, Text::get($police ? 'safehouse.lead.police' : 'safehouse.lead.caught'));
            }

            $lead->forceFill(['inside' => true])->save();
            $this->mood->event($locked, 'break_in');
            $character->refresh();

            return new Raid(true, Text::get('safehouse.lead.in'));
        });
    }

    /** Has this home been robbed lately? Nobody can break into or rob it again until the cooldown ends. */
    private function cooling(SafehouseLead $lead): bool
    {
        $until = $lead->target->raid_cooldown_until;

        return $until !== null && $until->isFuture();
    }

    /** Take a few stored rows. */
    public function rob(Character $character, SafehouseLead $lead): Raid|Refusal
    {
        return DB::transaction(function () use ($character, $lead): Raid|Refusal {
            $opened = $this->open($character, $lead, idle: false);

            if ($opened instanceof Refusal) {
                return $opened;
            }

            [$locked, $lead] = $opened;

            if (! $lead->inside) {
                return Refusal::NotInside;
            }

            if ($this->cooling($lead)) {
                return Refusal::Cooldown;
            }

            $this->fatigue->charge($locked, 'rob');
            $victim = Character::query()->whereKey($lead->target_id)->lockForUpdate()->firstOrFail();
            $storage = app(SafehouseStorage::class);
            $took = 0;

            foreach ($storage->rows($victim, $lead->safehouse)->whereNull('storage_expires_at')->with('item')->inRandomOrder()->lockForUpdate()->get() as $row) {
                if ($took >= (int) Config::get('safehouse.leads.steal_rows')) {
                    break;
                }

                if ($storage->seize($locked, $row)) {
                    $took++;
                }
            }

            if ($took > 0) {
                (new Notifier)->send($victim, Category::City, 'safehouse_raided', ['place' => app(Renter::class)->name($lead->safehouse), 'count' => $took]);
                $this->mood->event($victim, 'raided');
                $this->mood->event($locked, 'rob');
                $victim->forceFill(['raid_cooldown_until' => now()->addHours((int) Config::get('safehouse.leads.raid_cooldown_hours'))])->save();
            }

            $character->refresh();

            return new Raid($took > 0, $took > 0 ? Text::get('safehouse.lead.took', ['count' => $took]) : Text::get('safehouse.lead.empty'));
        });
    }

    /** Burn it down: the holder loses the home and everything in it, at once. */
    public function burn(Character $character, SafehouseLead $lead): Raid|Refusal
    {
        return DB::transaction(function () use ($character, $lead): Raid|Refusal {
            $opened = $this->open($character, $lead, idle: false);

            if ($opened instanceof Refusal) {
                return $opened;
            }

            [$locked, $lead] = $opened;

            if (! $lead->inside) {
                return Refusal::NotInside;
            }

            $this->fatigue->charge($locked, 'burn');
            $victim = Character::query()->whereKey($lead->target_id)->lockForUpdate()->firstOrFail();
            $place = app(Renter::class)->name($lead->safehouse);

            app(Renter::class)->burnDown($victim, $lead->safehouse);
            (new Notifier)->send($victim, Category::City, 'safehouse_burned', ['place' => $place]);
            $this->mood->event($victim, 'burned');
            $this->mood->event($locked, 'burn');
            $this->prune($locked);
            $character->refresh();

            return new Raid(true, Text::get('safehouse.lead.burned'));
        });
    }

    /** Step back outside. */
    public function leave(Character $character, SafehouseLead $lead): true|Refusal
    {
        $lead = SafehouseLead::query()->where('character_id', $character->getKey())->whereKey($lead->getKey())->first();

        if ($lead === null) {
            return Refusal::NotHere;
        }

        $lead->forceFill(['inside' => false])->save();

        return true;
    }

    /** Forget it: the lead, with what was worked out of it. */
    public function forget(Character $character, SafehouseLead $lead): void
    {
        SafehouseLead::query()->where('character_id', $character->getKey())->whereKey($lead->getKey())->delete();
    }
}
