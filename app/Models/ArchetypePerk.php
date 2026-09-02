<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Text;
use Database\Factories\ArchetypePerkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One perk on one archetype.
 *
 * Two shapes, and the difference is not cosmetic. A LIVE perk carries
 * `modifiers` that the resolver acts on, and its card text is generated from
 * them, so what a player reads cannot drift from what the numbers do. A
 * DISPLAY-ONLY perk carries `display_modifier` and `display_unit`: it renders
 * and changes nothing. The second shape is the older one, kept so perks can be
 * migrated one at a time rather than in a single change.
 *
 * The database refuses a row that is both or neither.
 */
#[Fillable([
    'archetype_id', 'key', 'label', 'modifiers',
    'display_modifier', 'display_unit', 'position',
])]
class ArchetypePerk extends Model
{
    /** @use HasFactory<ArchetypePerkFactory> */
    use HasFactory;

    /** A display-only perk whose unit is this borrows an attribute's name. */
    public const UNIT_ATTRIBUTE = 'attribute';

    protected function casts(): array
    {
        return [
            'modifiers' => 'array',
            'display_modifier' => 'integer',
            'position' => 'integer',
        ];
    }

    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }

    /** Is this perk wired to the resolver, or still display-only? */
    public function isLive(): bool
    {
        return $this->modifiers !== null && $this->modifiers !== [];
    }

    /**
     * What the card calls it.
     *
     * An attribute perk borrows the attribute's own name, so renaming
     * "Reflex" happens in one place rather than two. Everything else stores
     * its own label, because "Scar tissue" is the perk's name and not the name
     * of anything it changes.
     */
    public function displayLabel(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }

        return $this->display_unit === self::UNIT_ATTRIBUTE
            ? Text::get("character.names.{$this->key}")
            : $this->key;
    }

    protected static function booted(): void
    {
        // The roster caches perks alongside their archetype.
        static::saved(Archetype::forgetRoster(...));
        static::deleted(Archetype::forgetRoster(...));
    }
}
