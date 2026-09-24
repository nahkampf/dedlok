<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One system a job names, and whether it has been dealt with.
 *
 * IT CARRIES BOTH THE ID AND THE ADDRESS, which is the opposite of what
 * contacts, scans and runs do. Those store an address alone because they are
 * MEMORIES and must outlive their subject. A target is not a memory: it is
 * somewhere the runner has to go, so it needs the id to be findable — and the
 * address beside it is what makes a void detectable without a sweep. Same id,
 * different address, means the machine relocated out from under the job.
 */
#[Fillable(['mission_id', 'system_id', 'address', 'satisfied_at'])]
class MissionTarget extends Model
{
    protected function casts(): array
    {
        return [
            'satisfied_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Mission, $this> */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /** @return BelongsTo<System, $this> */
    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    public function isSatisfied(): bool
    {
        return $this->satisfied_at !== null;
    }

    /**
     * Is the machine this names still the machine this names?
     *
     * THREE WAYS TO ANSWER NO and they are one answer: the row is gone
     * (burned by somebody else), or the row is there under a new address
     * (relocated, which a lockdown's close also causes). None of them is the
     * runner's fault, which is why the job voids rather than fails.
     */
    public function stillStands(): bool
    {
        $system = $this->system()->first();

        return $system !== null && $system->address === $this->address;
    }
}
