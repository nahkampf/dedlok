<?php

declare(strict_types=1);

namespace App\Cyberspace\Party;

use App\Cyberspace\RunEnding;
use App\Models\Character;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\System;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Support\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * "Run concluded": what a co-op run came to, sent to everybody who was in it when the last of them is out.
 *
 * The message carries how each runner's run ended, who was in, how the take was to be divided, what came off the system,
 * who got what, and the run's whole log (the one of whoever stayed in longest, since a party shares one account), with
 * every runner named in brackets. It is composed once, here, from the party's loot ledger (`run_parties.loot`) and the
 * run rows, and sent as it stands: the party row is deleted straight afterwards.
 */
final class RunReport
{
    /** A party of one is not a co-op run: only a run with at least two runners is reported. */
    public static function send(int $partyId): void
    {
        $party = RunParty::query()->find($partyId);
        $runs = Run::query()->where('run_party_id', $partyId)->orderBy('id')->get();

        if ($party === null || $runs->count() < 2) {
            return;
        }

        $characters = Character::query()->whereKey($runs->pluck('character_id'))->get()->keyBy('id');
        $owner = $party->owner_character_id;
        // Owner first, then the order they went in.
        $order = $runs->pluck('character_id')->sortBy(fn ($id): int => (int) $id === (int) $owner ? 0 : 1)->values();
        $handle = fn (int $id): string => '['.($characters[$id]->handle ?? '?').']';

        $system = System::query()->where('address', $runs->first()->address)->first();
        $name = $system?->displayName() ?? '';
        $grade = (int) ($system?->grade ?? 0);
        $loot = $party->loot ?? [];

        // Done is done: a run that finished its job, or one that came away with something.
        $done = $runs->contains(fn (Run $r): bool => $r->ending === RunEnding::Completed->value) || $loot !== [];

        $details = ['report' => [
            'address' => (string) $runs->first()->address,
            'system' => $name,
            'grade' => $grade,
            'sections' => [
                ['heading' => Text::get('notifications.report.outcome'), 'lines' => [Text::get($done ? 'notifications.report.successful' : 'notifications.report.failed')]],
                ['heading' => Text::get('notifications.report.participants'), 'lines' => $order->map(fn ($id): string => $handle((int) $id).' '.self::ending($runs->firstWhere('character_id', $id)))->all()],
                ['heading' => Text::get('notifications.report.results'), 'lines' => [
                    Text::get('notifications.report.split_files', ['mode' => $party->share_extraction->label()]),
                    Text::get('notifications.report.split_credits', ['mode' => $party->share_credits->label()]),
                    Text::get('notifications.report.total', ['loot' => implode(', ', self::looted($loot))]),
                    ...self::dealt($loot, $order, $handle),
                ]],
            ],
            'log' => self::log($runs),
        ]];

        $replace = ['address' => $details['report']['address'], 'system' => $name.($grade > 0 ? ', '.Text::get('notifications.details.grade', ['grade' => $grade]) : '')];

        foreach ($order as $id) {
            if (($who = $characters->get($id)) !== null) {
                (new Notifier)->send($who, Category::Cyberspace, 'party_run_report', $replace, $details);
            }
        }
    }

    private static function ending(?Run $run): string
    {
        if ($run === null) {
            return '';
        }

        $ending = $run->ending === null ? null : RunEnding::tryFrom((string) $run->ending);

        return $ending?->message() ?? '';
    }

    /**
     * Everything taken, totalled: creds as one line, files and items by name.
     *
     * @param  list<array{character_id: int, kind: string, label: string, quantity: int}>  $loot
     * @return list<string>
     */
    private static function looted(array $loot): array
    {
        $creds = 0;
        $things = [];

        foreach ($loot as $entry) {
            if ($entry['kind'] === 'creds') {
                $creds += (int) $entry['quantity'];
            } else {
                $things[$entry['label']] = ($things[$entry['label']] ?? 0) + (int) $entry['quantity'];
            }
        }

        $lines = $creds > 0 ? [Number::format($creds).' ¤'] : [];

        foreach ($things as $label => $quantity) {
            $lines[] = $quantity > 1 ? "{$label} × {$quantity}" : (string) $label;
        }

        return $lines === [] ? [Text::get('notifications.report.nothing')] : $lines;
    }

    /**
     * One line per runner: what they were dealt, or a dash.
     *
     * @param  list<array{character_id: int, kind: string, label: string, quantity: int}>  $loot
     * @param  Collection<int, int|string>  $order
     * @return list<string>
     */
    private static function dealt(array $loot, Collection $order, callable $handle): array
    {
        return $order->map(function ($id) use ($loot, $handle): string {
            $creds = 0;
            $things = [];

            foreach ($loot as $entry) {
                if ((int) $entry['character_id'] !== (int) $id) {
                    continue;
                }

                if ($entry['kind'] === 'creds') {
                    $creds += (int) $entry['quantity'];
                } else {
                    $things[$entry['label']] = ($things[$entry['label']] ?? 0) + (int) $entry['quantity'];
                }
            }

            $parts = $creds > 0 ? [Number::format($creds).' ¤'] : [];

            foreach ($things as $label => $quantity) {
                $parts[] = $quantity > 1 ? "{$label} × {$quantity}" : (string) $label;
            }

            return $handle((int) $id).': '.($parts === [] ? '—' : implode(', ', $parts));
        })->all();
    }

    /**
     * The whole log, oldest first, of the run that went on longest (a party's runs share one account; the one that saw
     * the end has all of it). A runner is named in brackets: in the third person where the line has a wording for it,
     * else in front of the first-person text.
     *
     * @param  Collection<int, Run>  $runs
     * @return list<string>
     */
    private static function log(Collection $runs): array
    {
        $longest = $runs->sortByDesc(fn (Run $r): array => [(int) $r->turn, $r->ended_at?->getTimestamp() ?? 0])->first();
        $lines = [];

        foreach ((array) ($longest?->log ?? []) as $line) {
            $by = isset($line['by']) ? '['.$line['by'].']' : null;
            $told = null;

            if ($by !== null && isset($line['key'])) {
                $key = 'cyberspace.run.party_log.'.substr((string) $line['key'], strlen('cyberspace.run.log.'));
                $text = Text::get($key, ((array) ($line['replace'] ?? [])) + ['player' => $by, 'tag' => $line['tag'] ?? '']);
                $told = str_starts_with($text, 'cyberspace.run.party_log.') ? null : $text;
            }

            $text = $told ?? trim(($by !== null ? $by.' ' : '').($line['before'] ?? '').' '.($line['tag'] ?? '').' '.($line['text'] ?? ''));
            $lines[] = isset($line['turn']) ? Text::get('cyberspace.run.turn', ['turn' => $line['turn']]).': '.preg_replace('/\s+/', ' ', $text) : preg_replace('/\s+/', ' ', $text);
        }

        return $lines;
    }
}
