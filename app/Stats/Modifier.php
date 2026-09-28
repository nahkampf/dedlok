<?php

declare(strict_types=1);

namespace App\Stats;

use Illuminate\Support\Facades\Config;

/**
 * One change to one or more stats, from one source.
 *
 * Everything that alters a number becomes one of these before it reaches the
 * Resolver: skills, implants, drugs, injuries, archetype perks, gear, weather.
 * That is the whole extensibility story — a new KIND of source never touches
 * the arithmetic.
 */
final readonly class Modifier
{
    public function __construct(
        public Op $op,
        public float $amount,
        public Target $target,
        /** Human-readable origin, shown in the breakdown. */
        public ?string $source = null,
        /** Only meaningful for Op::Override. */
        public int $priority = 0,
        /**
         * When this applies. Null is the ordinary case and the one to prefer:
         * an unconditional modifier can be resolved once and cached, and a
         * conditional one cannot. See App\Stats\Condition.
         */
        public ?Condition $condition = null,
    ) {}

    /**
     * The same modifier, worth a share of itself.
     *
     * Additive amounts scale directly; a multiplier scales its distance from
     * one (a ×1.2 at half is ×1.1), so a share never flips it into a penalty.
     * An override is a value rather than a quantity and is left alone.
     * Drawbacks scale with the benefit: a copy is a fraction of the whole
     * program, not a fraction of its good side.
     */
    public function scaledBy(float $factor): self
    {
        $amount = match ($this->op) {
            Op::Multiplier => 1.0 + ($this->amount - 1.0) * $factor,
            Op::Override => $this->amount,
            default => $this->amount * $factor,
        };

        return new self($this->op, $amount, $this->target, $this->source, $this->priority, $this->condition);
    }

    /**
     * How far this is from doing nothing, whichever direction — the ranking a
     * stack of diminishing modifiers is sorted by, strongest first. A −10%
     * scan duration is as strong as a +10% anything.
     */
    public function magnitude(): float
    {
        return abs($this->op === Op::Multiplier ? $this->amount - 1.0 : $this->amount);
    }

    /**
     * Build from config data.
     *
     * Unknown keys are rejected rather than ignored, and so is an unknown
     * predicate inside a condition: a modifier that quietly does nothing is a
     * balance bug with no symptom.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $known = ['op', 'amount', 'target', 'source', 'priority', 'condition'];
        $unknown = array_diff(array_keys($data), $known);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown modifier keys: '.implode(', ', $unknown).
                '. Known keys are: '.implode(', ', $known).'.'
            );
        }

        foreach (['op', 'amount', 'target'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new \InvalidArgumentException("Modifier is missing [{$required}].");
            }
        }

        $op = Op::tryFrom((string) $data['op']);

        if ($op === null) {
            throw new \InvalidArgumentException(
                "Unknown modifier op [{$data['op']}]. Known ops: ".
                implode(', ', array_column(Op::cases(), 'value')).'.'
            );
        }

        return new self(
            op: $op,
            amount: (float) $data['amount'],
            target: Target::fromArray($data['target']),
            source: $data['source'] ?? null,
            priority: (int) ($data['priority'] ?? 0),
            condition: isset($data['condition']) ? Condition::fromArray((array) $data['condition']) : null,
        );
    }

    public function appliesTo(Stat $stat): bool
    {
        return $this->target->matches($stat);
    }

    /**
     * Does this modifier depend on state that moves between reads?
     *
     * The split the whole caching story rests on: unconditional modifiers
     * resolve once and can be held, conditional ones have to be judged at the
     * moment of the check.
     */
    public function isConditional(): bool
    {
        return $this->condition !== null;
    }

    /**
     * Is this modifier live, given what is true right now?
     *
     * A conditional modifier with NO context is not live. That is the
     * deliberate direction: resolving without a context is asking for the
     * context-free answer, and quietly folding in a bonus that is only
     * sometimes earned would make that answer wrong in a way nothing reports.
     */
    public function isLiveIn(?Context $context): bool
    {
        if ($this->condition === null) {
            return true;
        }

        return $context !== null && $this->condition->holds($context);
    }

    /**
     * This modifier as card text: "+5% max HP".
     *
     * Generated from the modifier itself, so a card cannot drift from what
     * the resolver does. One lookup for both kinds of target: a stat id, or a
     * tag set named as a whole. An unnamed target renders as the bare amount
     * rather than as its key — "−5% damage+hitpoints" is a debug string, and a
     * card is not where one belongs.
     */
    public function effectText(): string
    {
        $amount = $this->op === Op::Multiplier
            ? '×'.$this->amount
            : self::signed($this->amount).($this->op === Op::PercentageOfBase ? '%' : '');

        // Read as an array, not through Text::get(): a stat id can carry a
        // dot (check.routing), which a config path would take for nesting.
        $name = Config::get('strings.character.names')[$this->target->nameKey()] ?? null;

        return is_string($name) ? $amount.' '.$name : $amount;
    }

    /**
     * Signed display value. The sign is always shown — "+5" and "−10" read as
     * modifiers, where "5" and "10" read as absolute values. U+2212 minus, not
     * a hyphen, so a column of effects lines up.
     */
    public static function signed(float $amount): string
    {
        return ($amount < 0 ? '−' : '+').(abs($amount) == (int) abs($amount)
            ? (string) (int) abs($amount)
            : (string) abs($amount));
    }
}
