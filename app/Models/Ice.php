<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PointsCast;
use App\Cyberspace\AttackTag;
use App\Cyberspace\DefeatTag;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A piece of intrusion countermeasure electronics.
 *
 * THE ONLY DEFENCE THAT FIGHTS BACK, and the only one that needed a table. An
 * IDS and a tracer are each a number on the machine; ICE carries integrity, an
 * attack, a hit chance and a list of what it does to whoever it beats — and
 * that is a definition rather than a property, so it lives once and is pointed
 * at, the way an entity and an operating system are.
 *
 * A LEVEL IS WHAT A SCAN REMEMBERS. `system_scans.ice_level` holds the rung and
 * never the id, because a scan has to outlive the machine it describes — the
 * same rule that keeps a foreign key off `system_contacts`. Renaming a piece of
 * ICE therefore renames it in every file anybody is holding, which is the
 * behaviour renaming an entity already has and the behaviour you want.
 *
 * Its numbers are BASES, like a deck's. Nothing resolves them yet because
 * nothing fights, but when something does it goes through App\Stats\Resolver
 * and App\Stats\DamageResolver rather than reading these columns — so a
 * resistance to ICE is a modifier, never a branch.
 */
#[Fillable(['level', 'name', 'integrity', 'attack', 'skill', 'detection', 'defeat_tags', 'attack_tags', 'grade_min', 'grade_max'])]
class Ice extends Model
{
    /** The table is already plural, and Laravel would look for `ices`. */
    protected $table = 'ice';

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'integrity' => PointsCast::class,
            'attack' => PointsCast::class,
            'skill' => 'integer',
            'detection' => 'integer',
            'defeat_tags' => 'array',
            'attack_tags' => 'array',
            'grade_min' => 'integer',
            'grade_max' => 'integer',
        ];
    }

    /**
     * The pieces that turn up on machines of this grade.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForGrade(Builder $query, int $grade): void
    {
        $query->where('grade_min', '<=', $grade)->where('grade_max', '>=', $grade);
    }

    /**
     * How often it lands, as a ratio.
     *
     * A MISS DOES NOTHING AT ALL — no attack, no damage — which is the whole
     * reason a low-grade guard is survivable rather than merely slow, and why
     * this is a separate number from `attack` instead of being folded into it
     * as an average. An average would make every fight the same fight.
     */
    public function hitChance(): float
    {
        return max(0.0, min(1.0, $this->skill / 100));
    }

    /**
     * What happens to somebody this beats.
     *
     * NULL AND EMPTY ARE ONE ANSWER here, deliberately: a piece of ICE that
     * carries nothing beyond the waiting screen is ordinary, and `[]` and "not
     * set" should not be two ways of writing it. The same call
     * `programs.modifiers` makes.
     *
     * Each entry is a tag and, for the levelled ones, which rung — so the
     * stored shape is `[['tag' => 'flatline', 'level' => 2], …]`. A tag the
     * vocabulary no longer knows is DROPPED rather than thrown on: this is read
     * on a screen, and content that has been retired should stop appearing
     * rather than take a page down with it.
     *
     * @return list<array{tag: DefeatTag, level: ?int}>
     */
    public function defeatTags(): array
    {
        $tags = [];

        foreach ($this->defeat_tags ?? [] as $entry) {
            $tag = DefeatTag::tryFrom(is_array($entry) ? ($entry['tag'] ?? '') : (string) $entry);

            if ($tag === null) {
                continue;
            }

            $level = is_array($entry) ? ($entry['level'] ?? null) : null;

            $tags[] = ['tag' => $tag, 'level' => $tag->isLevelled() ? (int) ($level ?? 1) : null];
        }

        return $tags;
    }

    /** Does this carry the named consequence, at any strength? */
    public function carries(DefeatTag $tag): bool
    {
        foreach ($this->defeatTags() as $entry) {
            if ($entry['tag'] === $tag) {
                return true;
            }
        }

        return false;
    }

    /**
     * What this does WHILE it is fighting.
     *
     * The same shape and the same forgiveness as `defeatTags()` — null and `[]`
     * are one answer, and a tag the vocabulary no longer knows is dropped
     * rather than thrown on. The difference is when they matter: these are in
     * force for as long as the thing is awake, so they are read on every turn
     * that needs them rather than once at the end.
     *
     * @return list<array{tag: AttackTag, level: ?int}>
     */
    public function attackTags(): array
    {
        $tags = [];

        foreach ($this->attack_tags ?? [] as $entry) {
            $tag = AttackTag::tryFrom(is_array($entry) ? ($entry['tag'] ?? '') : (string) $entry);

            if ($tag === null) {
                continue;
            }

            $level = is_array($entry) ? ($entry['level'] ?? null) : null;

            $tags[] = ['tag' => $tag, 'level' => $tag->isLevelled() ? (int) ($level ?? 1) : null];
        }

        return $tags;
    }

    /**
     * Which rung of this attack tag it carries, or null for none at all.
     *
     * THE HIGHEST IF A ROW SOMEHOW CARRIES TWO. Nothing authors a duplicate and
     * the editor that would is not written, but a hand-edited row is content
     * like any other and the worse of two answers should not win by being
     * second in a list.
     */
    public function attackLevel(AttackTag $tag): ?int
    {
        $levels = [];

        foreach ($this->attackTags() as $entry) {
            if ($entry['tag'] === $tag) {
                $levels[] = $entry['level'] ?? 1;
            }
        }

        return $levels === [] ? null : max($levels);
    }
}
