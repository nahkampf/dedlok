<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Text;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A safehouse somebody has sensed and is working out. Written only by `App\Safehouses\Leads`.
 *
 * @property int $id
 * @property int $character_id
 * @property int $target_id
 * @property int $safehouse_id
 * @property int $number
 * @property float $discovery
 * @property float $security
 * @property bool $inside
 */
#[Fillable(['character_id', 'target_id', 'safehouse_id', 'number', 'discovery', 'security', 'inside'])]
class SafehouseLead extends Model
{
    protected function casts(): array
    {
        return ['number' => 'integer', 'discovery' => 'float', 'security' => 'float', 'inside' => 'boolean'];
    }

    /** @return BelongsTo<Character, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'target_id');
    }

    /** @return BelongsTo<Safehouse, $this> */
    public function safehouse(): BelongsTo
    {
        return $this->belongsTo(Safehouse::class);
    }

    /** Discovery has passed the home's stealth: the owner is known. */
    public function isKnown(): bool
    {
        return $this->discovery > $this->safehouse->stealth;
    }

    /** Security scouted past the home's own: breaking in carries no penalty. */
    public function isCleared(): bool
    {
        return $this->security > $this->safehouse->security;
    }

    /** "Suspicious place 2", then "Safehouse: Handle" once the owner is known. */
    public function name(): string
    {
        return $this->isKnown()
            ? Text::get('safehouse.lead.known_name', ['handle' => (string) $this->target->handle])
            : Text::get('safehouse.lead.unknown_name', ['number' => $this->number]);
    }

    /** The Tasks heading and text for where this lead has got to. @return array{0: string, 1: string} */
    public function entry(): array
    {
        $replace = ['district' => (string) $this->safehouse->district->name, 'handle' => (string) $this->target->handle];

        return match (true) {
            $this->isKnown() && $this->isCleared() => [$this->name(), Text::get('safehouse.lead.task_cleared', $replace)],
            $this->isKnown() => [$this->name(), Text::get('safehouse.lead.task_known', $replace)],
            default => [Text::get('safehouse.lead.task_unknown_title', $replace), Text::get('safehouse.lead.task_unknown', $replace)],
        };
    }
}
