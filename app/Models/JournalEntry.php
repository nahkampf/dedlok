<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One line under a journal note. Written only by `App\Journal\Journal`. */
#[Fillable(['character_id', 'note_id', 'slug', 'body', 'completed'])]
class JournalEntry extends Model
{
    protected function casts(): array
    {
        return ['completed' => 'boolean'];
    }
}
