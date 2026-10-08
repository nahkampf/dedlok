<?php

declare(strict_types=1);

namespace App\Models;

use App\Decks\Hardware;
use App\Decks\Variant;
use App\Support\Qb;
use App\Support\Text;
use Database\Factories\DeckModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a module is worth: the socket it wants, and the one number that
 * describes it.
 *
 * It does not say what KIND of module it is — `items.hardware` does, and
 * repeating it here would be a second answer to the same question, free to
 * disagree with the first. Everything on this row that depends on the kind
 * therefore takes it as an argument rather than looking it up: a spec line
 * that lazily loaded its own item to find out what unit to print would be a
 * query per row in every list a module appears in.
 *
 * Four measure columns, at most one of them filled, enforced by the schema.
 * They are not one generic `rating` because qFLOPS, QB, QB/s and watts do not
 * convert into one another.
 */
#[Fillable(['item_id', 'variant', 'power_draw', 'thermal_load', 'qflops', 'quantabits', 'bandwidth', 'watts', 'cooling', 'storage', 'thermal_warning', 'thermal_limit'])]
class DeckModule extends Model
{
    /** @use HasFactory<DeckModuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'variant' => Variant::class,
            'power_draw' => 'integer',
            'thermal_load' => 'integer',
            'qflops' => 'float',
            'quantabits' => 'float',
            'bandwidth' => 'integer',
            'watts' => 'integer',
            'cooling' => 'integer',
            'storage' => 'float',
            'thermal_warning' => 'integer',
            'thermal_limit' => 'integer',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** The number this kind of module is measured by, if it has one. */
    public function measure(Hardware $hardware): int|float|null
    {
        $column = $hardware->measure();

        return $column === null ? null : $this->{$column};
    }

    /** That number with its unit: "1,200 qFLOPS", "45 W". */
    public function measureLabel(Hardware $hardware): ?string
    {
        $column = $hardware->measure();
        $value = $this->measure($hardware);

        if ($column === null || $value === null) {
            return null;
        }

        return Text::get("decks.units.{$column}", ['value' => in_array($column, ['qflops', 'quantabits', 'storage'], true) ? Qb::format($value) : number_format($value)]);
    }

    /** One line: "Socket K · 1,200 qFLOPS · 45 W draw". */
    public function summary(Hardware $hardware): string
    {
        $parts = array_filter([
            $this->variant?->label(),
            $this->measureLabel($hardware),
            $hardware->draws() && $this->power_draw > 0
                ? Text::get('decks.units.draw', ['value' => number_format($this->power_draw)])
                : null,
            // Only where there is any. Most modules run cool enough that a
            // zero on the spec line would be noise.
            $hardware->heats() && $this->thermal_load > 0
                ? Text::get('decks.units.thermal_load', ['value' => number_format($this->thermal_load)])
                : null,
        ]);

        return implode(Text::get('items.summary.separator'), $parts);
    }
}
