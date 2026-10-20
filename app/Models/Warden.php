<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\Position;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A hunter that walks a subnet. `App\Cyberspace\Wardens` is the only writer.
 *
 * @property int $id
 * @property int $grid_id
 * @property int $x
 * @property int $y
 * @property int $grade
 * @property Carbon $moved_at
 */
#[Fillable(['grid_id', 'x', 'y', 'grade', 'moved_at'])]
class Warden extends Model
{
    protected function casts(): array
    {
        return ['grid_id' => 'integer', 'x' => 'integer', 'y' => 'integer', 'grade' => 'integer', 'moved_at' => 'datetime'];
    }

    public function position(): Position
    {
        return new Position($this->x, $this->y, $this->grid_id);
    }
}
