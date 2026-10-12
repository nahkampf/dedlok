<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A level 0 journal note: a heading with a slug. Written only by `App\Journal\Journal`. */
#[Fillable(['character_id', 'slug', 'title', 'completed'])]
class JournalNote extends Model
{
    protected function casts(): array
    {
        return ['completed' => 'boolean'];
    }

    /** @return HasMany<JournalEntry, $this> */
    public function entries(): HasMany
    {
        // Newest first.
        return $this->hasMany(JournalEntry::class, 'note_id')->orderByDesc('id');
    }
}
