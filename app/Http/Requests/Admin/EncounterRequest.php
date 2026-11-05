<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\City\EncounterGraph;
use App\City\EncounterRunEnd;
use App\City\EncounterStart;
use App\City\OutcomeBranch;
use App\City\OutcomeKind;
use App\City\PrerequisiteKind;
use App\Cyberspace\Grid;
use App\Cyberspace\RunOutcome;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\SystemType;
use App\Models\District;
use App\Models\Encounter;
use App\Models\Entity;
use App\Models\Item;
use App\Models\Npc;
use App\Models\Skill;
use App\Stats\StatRegistry;
use App\Support\Text;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

/**
 * An encounter and its whole graph, in one form.
 *
 * The migration's CHECKs cover the plain columns; everything SQL cannot see lives
 * HERE: a node key that exists, exactly one entry, an outcome of kind `item` having
 * an item (MySQL refuses a CHECK on a cascading foreign key, error 3823), and a
 * graph with no trap in it. Blank rows are skipped, not errors, because the form pads
 * itself with empty ones.
 *
 * `nodeRows()` is the cleaned graph, only valid once validation has passed.
 */
class EncounterRequest extends FormRequest
{
    private const KEY = '/^[a-z0-9_]{1,40}$/';

    private const FLAG = '/^[a-z0-9_]{1,60}$/';

    /** A journal note's or line's slug: the same shape every slug has. */
    private const SLUG = '/^[a-z0-9_-]{1,60}$/';

    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array<string, mixed>> */
    private array $prerequisites = [];

    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var Encounter|null $encounter */
        $encounter = $this->route('encounter');

        return [
            'slug' => $encounter === null
                ? ['required', 'string', 'regex:/^[a-z0-9_-]{1,40}$/', 'unique:encounters,slug']
                : ['prohibited'],
            'title' => ['required', 'string', 'max:150'],
            'intro' => ['nullable', 'string', 'max:5000'],
            'starts_on' => ['required', Rule::enum(EncounterStart::class)],
            'weight' => ['required', 'integer', 'between:1,100'],
            // Its own odds in percent, overriding the door's: only for a door that is rolled.
            'chance' => ['nullable', 'numeric', 'gt:0', 'lte:100', 'prohibited_if:starts_on,place,spot'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id', 'prohibited_if:starts_on,place,grid_step,system,run,after_run'],
            // The grid doors' own filters, each only on the door that reads it.
            'system_type' => ['nullable', Rule::enum(SystemType::class), 'prohibited_unless:starts_on,system,run,after_run'],
            'grade_min' => ['nullable', 'integer', 'between:1,9', 'prohibited_unless:starts_on,system,run,after_run'],
            'grade_max' => ['nullable', 'integer', 'between:1,9', 'gte:grade_min', 'prohibited_unless:starts_on,system,run,after_run'],
            'owner_entity_id' => ['nullable', 'integer', 'exists:entities,id', 'prohibited_unless:starts_on,system,run,after_run'],
            'run_purpose' => ['nullable', Rule::enum(RunPurpose::class), 'prohibited_unless:starts_on,run,after_run'],
            'run_outcome' => ['nullable', Rule::enum(RunOutcome::class), 'prohibited_unless:starts_on,after_run'],
            // The spot door: the address of the system it is offered at (stored as that system's id, so it follows
            // the machine when it moves), and how long a character waits before pressing it again.
            'spot_address' => ['nullable', 'string', 'max:16', 'required_if:starts_on,spot', 'prohibited_unless:starts_on,spot'],
            'cooldown_hours' => ['nullable', 'integer', 'between:1,8760', 'prohibited_unless:starts_on,spot'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id', 'required_if:starts_on,place', 'prohibited_unless:starts_on,place'],
            'required_flag' => ['nullable', 'string', 'regex:'.self::FLAG],
            'once' => ['nullable', 'boolean'],
            'retired' => ['nullable', 'boolean'],
            'nodes' => ['nullable', 'array', 'max:60'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // PHP DROPS every variable past `max_input_vars` and says nothing, so a big encounter would be saved
            // with its tail missing and look like it had saved. A form that reached the limit is refused whole.
            $limit = (int) ini_get('max_input_vars');
            $leaves = 0;
            $posted = $this->request->all();
            array_walk_recursive($posted, static function () use (&$leaves): void {
                $leaves++;
            });

            if ($limit > 0 && $leaves >= $limit) {
                $v->errors()->add('nodes', Text::get('admin.encounters.errors.too_big', ['limit' => $limit]));

                return;
            }

            [$this->nodes, $errors] = $this->build();
            [$this->prerequisites, $prerequisiteErrors] = $this->buildPrerequisites();

            // The address must name a system that exists now; it is stored as the system's id.
            $address = trim((string) $this->input('spot_address'));

            if ($address !== '' && Grid::findAnywhere($address) === null) {
                $v->errors()->add('spot_address', Text::get('admin.encounters.errors.spot_address'));
            }

            foreach ([...$errors, ...$prerequisiteErrors] as $field => $message) {
                $v->errors()->add($field, $message);
            }
        });
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        $data = $this->validated();
        // `prohibited` lets an empty value through, so a slug is dropped here rather than trusted: it is permanent.
        $slug = $this->route('encounter') === null ? ['slug' => $data['slug']] : [];

        return [
            ...$slug,
            ...array_intersect_key($data, array_flip(['title', 'intro', 'starts_on', 'weight', 'district_id', 'location_id', 'required_flag'])),
            ...$this->doorFilters($data),
            'spot_system_id' => $this->spotSystemId(),
            'cooldown_hours' => ($data['cooldown_hours'] ?? '') === '' ? null : (int) $data['cooldown_hours'],
            'chance' => ($data['chance'] ?? '') === '' ? null : round((float) $data['chance'], 2),
            'intro' => ($data['intro'] ?? '') === '' ? null : $data['intro'],
            'required_flag' => ($data['required_flag'] ?? '') === '' ? null : $data['required_flag'],
            'once' => $this->boolean('once'),
            'retired' => $this->boolean('retired'),
        ];
    }

    /**
     * The grid doors' filters, a blank being NULL (no filter).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function doorFilters(array $data): array
    {
        $out = [];

        foreach (['system_type', 'grade_min', 'grade_max', 'owner_entity_id', 'run_purpose', 'run_outcome'] as $field) {
            $value = $data[$field] ?? null;
            $out[$field] = $value === null || $value === '' ? null : $value;
        }

        return $out;
    }

    /** The pinned system's id, or null. Resolved here so a spot names its machine and not its address. */
    private function spotSystemId(): ?int
    {
        if ((string) $this->input('starts_on') !== EncounterStart::Spot->value) {
            return null;
        }

        $system = Grid::findAnywhere(trim((string) $this->input('spot_address')));

        return $system === null ? null : (int) $system->getKey();
    }

    /** @return list<array<string, mixed>> */
    public function nodeRows(): array
    {
        return $this->nodes;
    }

    /** @return list<array<string, mixed>> */
    public function prerequisiteRows(): array
    {
        return $this->prerequisites;
    }

    /**
     * The posted conditions, cleaned. A row with no kind is a blank the form padded with.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function buildPrerequisites(): array
    {
        $rows = [];
        $errors = [];

        foreach ((array) $this->input('prerequisites', []) as $i => $raw) {
            $raw = (array) $raw;
            $word = trim((string) ($raw['kind'] ?? ''));

            if ($word === '') {
                continue;
            }

            $at = "prerequisites.{$i}";
            $kind = PrerequisiteKind::tryFrom($word);

            if ($kind === null) {
                $errors["{$at}.kind"] = Text::get('admin.encounters.prerequisite.errors.kind');

                continue;
            }

            $subject = $kind->needsSubject() ? trim((string) ($raw['subject'] ?? '')) : null;
            $amount = $kind->needsAmount() ? ($raw['amount'] ?? null) : null;
            $itemId = $kind->needsItem() ? (int) ($raw['item_id'] ?? 0) : null;
            $entityId = $kind->needsEntity() ? (int) ($raw['entity_id'] ?? 0) : null;

            if ($kind->needsAmount() && ! is_numeric($amount)) {
                $errors["{$at}.amount"] = Text::get('admin.encounters.prerequisite.errors.amount', ['kind' => $kind->label()]);
            }

            // A count is a whole number of at least one; a standing is a figure from -100 to 100.
            if (is_numeric($amount) && in_array($kind, [PrerequisiteKind::Item, PrerequisiteKind::ScanFiles, PrerequisiteKind::Creds, PrerequisiteKind::Skill], true)
                && ((float) $amount < 1 || (float) $amount != (int) $amount)) {
                $errors["{$at}.amount"] = Text::get('admin.encounters.prerequisite.errors.whole', ['kind' => $kind->label()]);
            }

            if (is_numeric($amount) && $kind === PrerequisiteKind::Standing && abs((float) $amount) > 100) {
                $errors["{$at}.amount"] = Text::get('admin.encounters.prerequisite.errors.standing');
            }

            if ($kind === PrerequisiteKind::Flag && preg_match(self::FLAG, (string) $subject) !== 1) {
                $errors["{$at}.subject"] = Text::get('admin.encounters.prerequisite.errors.flag');
            }

            if ($kind === PrerequisiteKind::Skill && ! Skill::query()->where('slug', $subject)->exists()) {
                $errors["{$at}.subject"] = Text::get('admin.encounters.prerequisite.errors.skill');
            }

            if ($kind === PrerequisiteKind::District && ! District::query()->where('slug', $subject)->exists()) {
                $errors["{$at}.subject"] = Text::get('admin.encounters.prerequisite.errors.district');
            }

            if ($kind === PrerequisiteKind::Item && ! Item::query()->whereKey($itemId)->exists()) {
                $errors["{$at}.item_id"] = Text::get('admin.encounters.prerequisite.errors.item');
            }

            if ($kind === PrerequisiteKind::Standing && ! Entity::query()->whereKey($entityId)->exists()) {
                $errors["{$at}.entity_id"] = Text::get('admin.encounters.prerequisite.errors.entity');
            }

            $rows[] = [
                'kind' => $kind->value,
                'subject' => $subject === '' ? null : $subject,
                'amount' => is_numeric($amount) ? (float) $amount : null,
                'item_id' => $itemId,
                'entity_id' => $entityId,
                'negate' => (bool) ($raw['negate'] ?? false),
            ];
        }

        return [$rows, $errors];
    }

    /** Whether a posted choice carries any outcome at all (a fight choice may not). @param  array<string, mixed>  $c */
    private function hasOutcomes(array $c): bool
    {
        foreach ((array) ($c['outcomes'] ?? []) as $o) {
            if (trim((string) (((array) $o)['kind'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * One outcome row, cleaned, or null for a blank one. $rolls is whether pass/fail make sense here
     * (a choice with a roll); a screen's own outcomes pass false.
     *
     * @param  array<string, mixed>  $o
     * @param  array<string, string>  $errors
     * @return ?array<string, mixed>
     */
    private function outcome(array $o, string $oat, string $nodeKey, bool $rolls, array &$errors): ?array
    {
        // The picker offers "injury:neural_damage" and "damage:hitpoints": the part before the
        // colon is the kind and the part after is a default for the text field, which wins if filled.
        [$kindWord, $kindRef] = array_pad(explode(':', trim((string) ($o['kind'] ?? '')), 2), 2, '');
        $kind = OutcomeKind::tryFrom($kindWord);

        if ($kindWord === '') {
            // A row with nothing in it is the blank the form pads with. A row with something typed in it and no kind
            // picked is NOT blank: skipping it silently is how somebody types a whole journal note and loses it.
            foreach (['amount', 'ref', 'item_id', 'entity_id', 'text', 'entry_slug', 'entry_text'] as $field) {
                if (trim((string) ($o[$field] ?? '')) !== '') {
                    $errors["{$oat}.kind"] = Text::get('admin.encounters.errors.outcome_no_kind', ['node' => $nodeKey]);

                    break;
                }
            }

            return null;
        }

        $branch = OutcomeBranch::tryFrom((string) ($o['branch'] ?? 'always')) ?? OutcomeBranch::Always;
        $ref = trim((string) ($o['ref'] ?? '')) ?: trim($kindRef);

        if ($kind === null) {
            $errors["{$oat}.kind"] = Text::get('admin.encounters.errors.kind', ['node' => $nodeKey]);

            return null;
        }

        if ($branch !== OutcomeBranch::Always && ! $rolls) {
            $errors["{$oat}.branch"] = Text::get('admin.encounters.errors.branch', ['node' => $nodeKey]);
        }

        if ($kind->needsAmount() && ! is_numeric($o['amount'] ?? null)) {
            $errors["{$oat}.amount"] = Text::get('admin.encounters.errors.amount', ['node' => $nodeKey, 'kind' => $kind->label()]);
        }

        if ($kind === OutcomeKind::Flag && preg_match(self::FLAG, $ref) !== 1) {
            $errors["{$oat}.ref"] = Text::get('admin.encounters.errors.flag', ['node' => $nodeKey]);
        }

        // The grid's outcomes need a run (or a map) to act on, so only the doors that have one may carry them.
        $door = EncounterStart::tryFrom((string) $this->input('starts_on'));

        if ($kind->isRunOnly() && $door !== EncounterStart::Run) {
            $errors["{$oat}.kind"] = Text::get('admin.encounters.errors.run_only', ['node' => $nodeKey, 'kind' => $kind->label()]);
        }

        if ($kind === OutcomeKind::GridMove) {
            $size = (int) Config::get('cyberspace.size', 128);

            if (! ($door?->isGrid() ?? false) || $door === EncounterStart::Run || $door === EncounterStart::AfterRun) {
                $errors["{$oat}.kind"] = Text::get('admin.encounters.errors.grid_move_door', ['node' => $nodeKey]);
            } elseif (preg_match('/^(\d+),\s*(\d+)$/', $ref, $m) !== 1 || (int) $m[1] >= $size || (int) $m[2] >= $size) {
                $errors["{$oat}.ref"] = Text::get('admin.encounters.errors.grid_position', ['node' => $nodeKey, 'size' => $size]);
            }
        }

        if ($kind === OutcomeKind::Journal || $kind === OutcomeKind::JournalDone) {
            if (preg_match(self::SLUG, $ref) !== 1) {
                $errors["{$oat}.ref"] = Text::get('admin.encounters.errors.journal_slug', ['node' => $nodeKey]);
            }
        }

        if ($kind === OutcomeKind::Journal) {
            $heading = trim((string) ($o['text'] ?? ''));
            $entrySlug = trim((string) ($o['entry_slug'] ?? ''));
            $entryText = trim((string) ($o['entry_text'] ?? ''));

            // A journal outcome must write something: a note, a line, or both.
            if ($heading === '' && $entryText === '') {
                $errors["{$oat}.text"] = Text::get('admin.encounters.errors.journal_empty', ['node' => $nodeKey]);
            }

            if (($entryText !== '') !== ($entrySlug !== '') || ($entrySlug !== '' && preg_match(self::SLUG, $entrySlug) !== 1)) {
                $errors["{$oat}.entry_slug"] = Text::get('admin.encounters.errors.journal_entry', ['node' => $nodeKey]);
            }
        }

        if ($kind === OutcomeKind::Message) {
            $delay = $o['amount'] ?? null;

            if ($ref === '' || trim((string) ($o['text'] ?? '')) === '' || trim((string) ($o['entry_text'] ?? '')) === '') {
                $errors["{$oat}.text"] = Text::get('admin.encounters.errors.message_empty', ['node' => $nodeKey]);
            }

            if (($delay !== null && $delay !== '' && ! is_numeric($delay)) || (float) $delay < 0 || (float) $delay > OutcomeKind::MESSAGE_MAX_DELAY) {
                $errors["{$oat}.amount"] = Text::get('admin.encounters.errors.message_delay', ['node' => $nodeKey, 'max' => OutcomeKind::MESSAGE_MAX_DELAY]);
            }
        }

        if ($kind === OutcomeKind::Injury && ! in_array($ref, OutcomeKind::INJURIES, true)) {
            $errors["{$oat}.ref"] = Text::get('admin.encounters.errors.injury', ['node' => $nodeKey, 'ref' => $ref]);
        }

        if ($kind === OutcomeKind::Damage && ! in_array($ref, OutcomeKind::DAMAGE, true)) {
            $errors["{$oat}.ref"] = Text::get('admin.encounters.errors.resource', ['node' => $nodeKey]);
        }

        $itemId = $kind->needsItem() ? (int) ($o['item_id'] ?? 0) : null;
        $entityId = $kind->needsEntity() ? (int) ($o['entity_id'] ?? 0) : null;

        if ($kind === OutcomeKind::Item && ! Item::query()->whereKey($itemId)->exists()) {
            $errors["{$oat}.item_id"] = Text::get('admin.encounters.errors.item', ['node' => $nodeKey]);
        }

        if ($kind === OutcomeKind::Dose && ($itemId === null || ! Item::query()->whereKey($itemId)->whereHas('drug')->exists())) {
            $errors["{$oat}.item_id"] = Text::get('admin.encounters.errors.dose_item', ['node' => $nodeKey]);
        }

        if ($entityId !== null && ! Entity::query()->whereKey($entityId)->exists()) {
            $errors["{$oat}.entity_id"] = Text::get('admin.encounters.errors.entity', ['node' => $nodeKey]);
        }

        return [
            'branch' => $branch->value,
            'kind' => $kind->value,
            'amount' => $kind->needsAmount() ? (float) ($o['amount'] ?? 0) : ($kind === OutcomeKind::Message ? (float) ($o['amount'] ?? 0) : null),
            'ref' => $kind->needsRef() ? $ref : null,
            'item_id' => $itemId,
            'entity_id' => $entityId,
            'text' => in_array($kind, [OutcomeKind::Journal, OutcomeKind::Message], true) ? (trim((string) ($o['text'] ?? '')) ?: null) : null,
            'entry_slug' => $kind === OutcomeKind::Journal ? (trim((string) ($o['entry_slug'] ?? '')) ?: null) : null,
            'entry_text' => in_array($kind, [OutcomeKind::Journal, OutcomeKind::Message], true) ? (trim((string) ($o['entry_text'] ?? '')) ?: null) : null,
        ];
    }

    /**
     * Clean the posted graph and say what is wrong with it.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function build(): array
    {
        $errors = [];
        $nodes = [];
        $seen = [];
        $registry = app(StatRegistry::class);
        $prefix = ($this->route('encounter')?->slug ?? (string) $this->input('slug')).'_';

        foreach ((array) $this->input('nodes', []) as $i => $raw) {
            $key = trim((string) ($raw['key'] ?? ''));
            $body = trim((string) ($raw['body'] ?? ''));

            // The form pre-fills a screen's key with "<slug>_"; a key that is still only that,
            // with no text, is an untouched blank row.
            if (($key === '' || $key === $prefix) && $body === '') {
                continue;
            }

            if (preg_match(self::KEY, $key) !== 1) {
                $errors["nodes.{$i}.key"] = Text::get('admin.encounters.errors.node_key');

                continue;
            }

            if (isset($seen[$key])) {
                $errors["nodes.{$i}.key"] = Text::get('admin.encounters.errors.node_duplicate', ['node' => $key]);

                continue;
            }

            if ($body === '') {
                $errors["nodes.{$i}.body"] = Text::get('admin.encounters.errors.node_body', ['node' => $key]);
            }

            $seen[$key] = true;
            $nodes[] = [
                'index' => $i, 'key' => $key, 'body' => $body,
                'entry' => (string) $this->input('entry') === (string) $i,
                'ends' => (bool) ($raw['ends'] ?? false),
                'run_end' => trim((string) ($raw['run_end'] ?? '')) ?: null,
                'raw' => (array) ($raw['choices'] ?? []),
                'rawOutcomes' => (array) ($raw['outcomes'] ?? []),
                'outcomes' => [],
                'choices' => [],
            ];
        }

        if ($nodes === []) {
            return [[], ['nodes' => Text::get('admin.encounters.errors.no_nodes')]];
        }

        // FIRST, every "to" key: a screen somebody has pointed at but not written. One message
        // naming them all, and nothing else is judged until they exist — the form comes back
        // with everything as typed.
        $missing = [];

        foreach ($nodes as $node) {
            // Same rows the real pass below reads: an ending's choices are ignored, a blank button is
            // skipped, and pass/fail keys only count on a choice that rolls.
            foreach ($node['ends'] ? [] : $node['raw'] as $c) {
                if (trim((string) ($c['label'] ?? '')) === '') {
                    continue;
                }

                $rolls = trim((string) ($c['check_stat'] ?? '')) !== '';
                $fights = trim((string) ($c['fight_npc_id'] ?? '')) !== '';

                // A fight choice goes by its three keys and has no plain next, pass or fail of its own.
                foreach ($fights ? ['win_key', 'lose_key', 'flee_key'] : ['next_key', ...($rolls ? ['pass_key', 'fail_key'] : [])] as $field) {
                    $to = trim((string) ($c[$field] ?? ''));

                    if ($to !== '' && ! isset($seen[$to])) {
                        $missing[$to] = $to;
                    }
                }
            }
        }

        if ($missing !== []) {
            return [[], ['nodes' => Text::get('admin.encounters.errors.missing_screens', ['keys' => implode(', ', $missing)])]];
        }

        if (count(array_filter($nodes, static fn (array $n): bool => $n['entry'])) !== 1) {
            $errors['entry'] = Text::get('admin.encounters.errors.one_entry');
        }

        foreach ($nodes as &$node) {
            // An ending shows its body and Continue; choices on it would never be offered.
            $rawChoices = $node['ends'] ? [] : $node['raw'];

            foreach ($rawChoices as $j => $c) {
                $label = trim((string) ($c['label'] ?? ''));

                if ($label === '') {
                    continue;
                }

                $at = "nodes.{$node['index']}.choices.{$j}";
                $nullable = static fn (string $f): ?string => ($v = trim((string) ($c[$f] ?? ''))) === '' ? null : $v;
                $stat = $nullable('check_stat');
                $hasCheck = $stat !== null;
                $npcId = $nullable('fight_npc_id');
                $fights = $npcId !== null;
                $choice = [
                    'label' => mb_substr($label, 0, 200),
                    'requires_flag' => $nullable('requires_flag'),
                    'cost' => max(0, (int) ($c['cost'] ?? 0)),
                    'next_key' => $fights ? null : $nullable('next_key'),
                    'check_stat' => $stat,
                    'difficulty' => $hasCheck ? (float) ($c['difficulty'] ?? 0) : null,
                    'pass_key' => $hasCheck ? $nullable('pass_key') : null,
                    'fail_key' => $hasCheck ? $nullable('fail_key') : null,
                    'fight_npc_id' => $fights ? (int) $npcId : null,
                    'win_key' => $fights ? $nullable('win_key') : null,
                    'lose_key' => $fights ? $nullable('lose_key') : null,
                    'flee_key' => $fights ? $nullable('flee_key') : null,
                    'outcomes' => [],
                ];

                if ($fights) {
                    if (! Npc::query()->whereKey((int) $npcId)->exists()) {
                        $errors["{$at}.fight_npc_id"] = Text::get('admin.encounters.errors.fight_npc', ['node' => $node['key']]);
                    }

                    // What a fight choice cannot also be. Rewards are the arrival outcomes of the win screen,
                    // which keeps "an outcome cannot lead anywhere" true.
                    if ($hasCheck || $nullable('next_key') !== null || $nullable('pass_key') !== null || $nullable('fail_key') !== null || $this->hasOutcomes($c)) {
                        $errors["{$at}.fight_npc_id"] = Text::get('admin.encounters.errors.fight_exclusive', ['node' => $node['key']]);
                    }
                }

                if ($choice['requires_flag'] !== null && preg_match(self::FLAG, $choice['requires_flag']) !== 1) {
                    $errors["{$at}.requires_flag"] = Text::get('admin.encounters.errors.flag', ['node' => $node['key']]);
                }

                if ($hasCheck && ! $registry->has($stat)) {
                    $errors["{$at}.check_stat"] = Text::get('admin.encounters.errors.stat', ['node' => $node['key'], 'stat' => $stat]);
                }

                if ($hasCheck && (! is_numeric($c['difficulty'] ?? null) || (float) $c['difficulty'] < 0 || (float) $c['difficulty'] > 200)) {
                    $errors["{$at}.difficulty"] = Text::get('admin.encounters.errors.difficulty', ['node' => $node['key']]);
                }

                foreach ($fights ? [] : (array) ($c['outcomes'] ?? []) as $k => $o) {
                    if (($row = $this->outcome((array) $o, "{$at}.outcomes.{$k}", $node['key'], $hasCheck, $errors)) !== null) {
                        $choice['outcomes'][] = $row;
                    }
                }

                $node['choices'][] = $choice;
            }

            // What the SCREEN does on arrival — valid on an ending too, which is the point of it.
            // It has no roll of its own, so it is always applied: a pass/fail branch makes no sense here.
            foreach ($node['rawOutcomes'] as $k => $o) {
                if (($row = $this->outcome((array) $o, "nodes.{$node['index']}.outcomes.{$k}", $node['key'], false, $errors)) !== null) {
                    $node['outcomes'][] = $row;
                }
            }

            if (! $node['ends'] && $node['choices'] === []) {
                $errors["nodes.{$node['index']}.choices"] = Text::get('admin.encounters.errors.no_choices', ['node' => $node['key']]);
            }

            // What an ending does to the run: required on every ending of a run encounter so an author decides, and
            // refused anywhere else, where there is no run to be told. Not a CHECK (nodes cascade, error 3823).
            $isRunDoor = (string) $this->input('starts_on') === EncounterStart::Run->value;
            $runEnd = $node['run_end'];

            if ($isRunDoor && $node['ends'] && EncounterRunEnd::tryFrom((string) $runEnd) === null) {
                $errors["nodes.{$node['index']}.run_end"] = Text::get('admin.encounters.errors.run_end_required', ['node' => $node['key']]);
            }

            // Two different mistakes, so the message says which: the encounter does not start in a run, or the
            // screen is not an ending.
            if ($runEnd !== null && ! $isRunDoor) {
                $door = EncounterStart::tryFrom((string) $this->input('starts_on'));

                $errors["nodes.{$node['index']}.run_end"] = Text::get('admin.encounters.errors.run_end_door', [
                    'node' => $node['key'],
                    'door' => $door?->label() ?? (string) $this->input('starts_on'),
                ]);
            } elseif ($runEnd !== null && ! $node['ends']) {
                $errors["nodes.{$node['index']}.run_end"] = Text::get('admin.encounters.errors.run_end_refused', ['node' => $node['key']]);
            }
        }
        unset($node);

        // The trap check: SQL cannot see a graph.
        $graph = new EncounterGraph(array_column(array_map(static fn (array $n): array => [
            'key' => $n['key'],
            'node' => [
                'ends' => $n['ends'],
                'choices' => array_map(static fn (array $c): array => [
                    'next' => $c['next_key'], 'pass' => $c['pass_key'], 'fail' => $c['fail_key'], 'check' => $c['check_stat'] !== null,
                ] + ($c['fight_npc_id'] === null ? [] : ['fight' => ['win' => $c['win_key'], 'lose' => $c['lose_key'], 'flee' => $c['flee_key']]]), $n['choices']),
            ],
        ], $nodes), 'node', 'key'));

        foreach ($graph->stuck() as $stuck) {
            $errors['nodes'] = Text::get('admin.encounters.errors.stuck', ['node' => $stuck]);
        }

        return [
            array_map(static fn (array $n): array => [
                'key' => $n['key'], 'body' => $n['body'], 'entry' => $n['entry'], 'ends' => $n['ends'], 'run_end' => $n['run_end'], 'outcomes' => $n['outcomes'], 'choices' => $n['choices'],
            ], $nodes),
            $errors,
        ];
    }
}
