<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\City\EncounterGraph;
use App\City\EncounterStart;
use App\City\OutcomeBranch;
use App\City\OutcomeKind;
use App\Models\Encounter;
use App\Models\Entity;
use App\Models\Item;
use App\Models\Npc;
use App\Stats\StatRegistry;
use App\Support\Text;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
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

    /** @var list<array<string, mixed>> */
    private array $nodes = [];

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
                ? ['required', 'string', 'regex:/^[a-z0-9_]{1,40}$/', 'unique:encounters,slug']
                : ['prohibited'],
            'title' => ['required', 'string', 'max:150'],
            'intro' => ['nullable', 'string', 'max:5000'],
            'starts_on' => ['required', Rule::enum(EncounterStart::class)],
            'weight' => ['required', 'integer', 'between:1,100'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id', 'prohibited_if:starts_on,place'],
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
            [$this->nodes, $errors] = $this->build();

            foreach ($errors as $field => $message) {
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
            'intro' => ($data['intro'] ?? '') === '' ? null : $data['intro'],
            'required_flag' => ($data['required_flag'] ?? '') === '' ? null : $data['required_flag'],
            'once' => $this->boolean('once'),
            'retired' => $this->boolean('retired'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function nodeRows(): array
    {
        return $this->nodes;
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
            'amount' => $kind->needsAmount() ? (float) ($o['amount'] ?? 0) : null,
            'ref' => $kind->needsRef() ? $ref : null,
            'item_id' => $itemId,
            'entity_id' => $entityId,
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
                'key' => $n['key'], 'body' => $n['body'], 'entry' => $n['entry'], 'ends' => $n['ends'], 'outcomes' => $n['outcomes'], 'choices' => $n['choices'],
            ], $nodes),
            $errors,
        ];
    }
}
