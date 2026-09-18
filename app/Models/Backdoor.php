<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\BackdoorKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Access somebody keeps on a machine they have already been inside.
 *
 * It points at the SYSTEM rather than at an address, which is the opposite of
 * what a contact, a scan and a run all do — see the migration for why: those
 * are recollections and this is access, and access to a machine that has gone
 * is nothing at all.
 *
 * `App\Cyberspace\Backdoors` is the only writer.
 */
#[Fillable(['character_id', 'system_id', 'kind', 'installed_at'])]
class Backdoor extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => BackdoorKind::class,
            'installed_at' => 'datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeOfKind(Builder $query, BackdoorKind $kind): void
    {
        $query->where('kind', $kind->value);
    }

    /**
     * The address it opens, read off the machine and never stored.
     *
     * Null where the machine has gone, which the cascade makes unreachable
     * through an ordinary read — but a relation loaded before a delete can
     * still answer, and "nothing there" is the answer every address-holder in
     * this codebase has to tolerate.
     */
    public function address(): ?string
    {
        return $this->system?->address;
    }
}
