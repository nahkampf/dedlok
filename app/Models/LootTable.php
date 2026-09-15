<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\SystemType;
use App\Market\Rarity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * How good a drop is, by how hard the machine is.
 *
 * One row per (type, grade band, rarity), holding a weight that is relative
 * within its band. Weight 0 means never, which is how ultra-rare stays out of
 * the bottom seven grades without a rule in code.
 *
 * A TYPED ROW BEATS AN UNTYPED ONE, so a table for silos can be authored later
 * without touching the rows every other machine uses. Nothing ships typed.
 */
#[Fillable(['system_type', 'grade_min', 'grade_max', 'rarity', 'weight'])]
class LootTable extends Model
{
    protected function casts(): array
    {
        return [
            'system_type' => SystemType::class,
            'grade_min' => 'integer',
            'grade_max' => 'integer',
            'rarity' => Rarity::class,
            'weight' => 'integer',
        ];
    }

    /**
     * The weights in force for this machine, by rarity.
     *
     * Read once for a whole restock rather than per node: a pass over three
     * thousand machines asking the same forty rows is three thousand answers
     * that were always going to be the same.
     *
     * Not `all()`: that is Eloquent's, it takes a column list, and shadowing
     * it with a different signature is a trap for whoever calls the base one.
     *
     * @return Collection<int, self>
     */
    public static function weights(): Collection
    {
        return static::query()->get();
    }

    /** Does this row cover that machine? */
    public function covers(SystemType $type, int $grade): bool
    {
        return $grade >= $this->grade_min
            && $grade <= $this->grade_max
            && ($this->system_type === null || $this->system_type === $type);
    }
}
