<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Leech;
use App\Models\System;
use App\Models\SystemContent;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Programs\ProgramKind;
use App\Stats\Modifier;
use App\Stats\Resolver;
use App\Stats\SkillStats;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that founds a Data Fortress.
 *
 * A Data Fortress Kernel is SPENT by running it once on a truly empty square ("Found here" on the map): it leaves a
 * basic fortress, owned by the character. Needs the Node Administration skill, and a character holds only `fortress.cap`.
 * Defences, Leechers and everything hosted come later; a new fortress has no ICE, no IDS and nothing running.
 */
final class Fortresses
{
    /** The slug of the item a subnet fortress is founded with. */
    public const CERTIFICATE_SLUG = 'subnet_certificate';

    public function found(Character $character, InventoryItem $kernel): FortressRefusal|System
    {
        if ($character->current_run_id !== null || $character->current_fight_id !== null || $character->current_hunt_id !== null) {
            return FortressRefusal::Busy;
        }

        $at = $character->gridPosition();

        if ($at === null) {
            return FortressRefusal::NotJackedIn;
        }

        if ($kernel->character_id !== $character->getKey() || $kernel->item?->program?->kind !== ProgramKind::Kernel) {
            return $kernel->character_id !== $character->getKey() ? FortressRefusal::NotYours : FortressRefusal::NotAKernel;
        }

        $slug = (string) config('cyberspace.fortress.skill');

        // A skill that is not in the game gates nothing (the crafting gates' rule).
        if (SkillStats::skills()->has($slug) && $character->stat(SkillStats::skillId($slug))->value < 1) {
            return FortressRefusal::NoSkill;
        }

        // On a subnet a fortress needs that subnet's certificate, which is locked into the fortress's vault once it stands.
        $certificate = null;

        if ($at->grid !== 1) {
            $certificate = $character->inventory()->loose()->where('grid_id', $at->grid)
                ->whereHas('item', fn ($q) => $q->where('slug', self::CERTIFICATE_SLUG))->first();

            if ($certificate === null) {
                return FortressRefusal::NoCertificate;
            }
        }

        return DB::transaction(function () use ($character, $kernel, $at, $certificate): FortressRefusal|System {
            // The character row is the lock: two presses cannot both pass the cap.
            Character::query()->whereKey($character->getKey())->lockForUpdate()->first();

            if (System::query()->at($at)->exists()) {
                return FortressRefusal::Occupied;
            }

            $owned = System::query()->where('owner_character_id', $character->getKey())->count();

            $cap = (int) app(Resolver::class)->resolve('fortress_slots', (float) config('cyberspace.fortress.cap', 1), $character->modifiers())->value;

            if ($owned >= $cap) {
                return FortressRefusal::AtTheCap;
            }

            $system = System::query()->create([
                'address' => Address::generate(null, $at->grid === 1 ? null : Grid::forId($at->grid)->prefix()),
                'grid_id' => $at->grid,
                'x' => $at->x,
                'y' => $at->y,
                'type' => SystemType::Fortress,
                'grade' => Grade::MIN,
                'designation' => SystemNamer::fromConfig()->designate(),
                'owner_character_id' => $character->getKey(),
                ...$this->kernelNumbers($kernel),
            ]);

            // The certificate goes into the vault and stays: nothing withdraws it (FortressVault) and no raid takes it.
            if ($certificate !== null) {
                SystemContent::query()->create([
                    'system_id' => $system->getKey(),
                    'kind' => ContentKind::Item->value,
                    'item_id' => $certificate->item_id,
                    'quantity' => 1,
                    'deposited_by' => $character->getKey(),
                    'meta' => ['locked' => true, 'grid_id' => $at->grid],
                ]);
                $certificate->delete();
            }

            // Spent: one copy of the Kernel.
            if ($kernel->quantity > 1) {
                $kernel->decrement('quantity');
            } else {
                $kernel->delete();
            }

            return $system;
        });
    }

    /**
     * What the spent Kernel makes of the fortress: its own `items.modifiers` resolved against the config bases, and kept on
     * the system because the item is gone once it is spent.
     *
     * @return array{kernel_integrity_max: int, kernel_resistance: int, kernel_demand_percent: int}
     */
    private function kernelNumbers(InventoryItem $kernel): array
    {
        $modifiers = [];

        foreach ((array) $kernel->item?->modifiers as $shape) {
            if (is_array($shape)) {
                $modifiers[] = Modifier::fromArray($shape + ['source' => $kernel->item->displayName()]);
            }
        }

        $resolver = app(Resolver::class);

        return [
            'kernel_integrity_max' => $resolver->resolve('kernel_integrity', (float) config('cyberspace.fortress.kernel_integrity', 100), $modifiers)->toInt(),
            'kernel_resistance' => $resolver->resolve('kernel_resistance', (float) config('cyberspace.fortress.kernel_resistance', 0), $modifiers)->toInt(),
            'kernel_demand_percent' => $resolver->resolve('kernel_demand', 100.0, $modifiers)->toInt(),
        ];
    }

    /**
     * A burned Kernel: everything on the fortress is deleted but the Kernel, which is Frozen for `frozen_hours`. The
     * owner is told. Saving it is FortressHost::settle() finding the totals able to run the Kernel again.
     */
    public function freeze(System $fortress): void
    {
        DB::transaction(function () use ($fortress): void {
            InventoryItem::query()->where('fortress_system_id', $fortress->getKey())->delete();
            SystemContent::query()->where('system_id', $fortress->getKey())->delete();
            Leech::query()->where('fortress_system_id', $fortress->getKey())->delete();

            $fortress->forceFill([
                'frozen_until' => now()->addHours((int) config('cyberspace.fortress.frozen_hours', 8)),
                'abandoned_at' => $fortress->abandoned_at ?? now(),
                'kernel_integrity' => $fortress->kernelIntegrityMax(),
            ])->save();

            $owner = Character::query()->find($fortress->owner_character_id);

            if ($owner !== null) {
                (new Notifier)->send($owner, Category::Cyberspace, 'fortress_burned', [
                    'hours' => (int) config('cyberspace.fortress.frozen_hours', 8),
                ]);
            }
        });
    }

    /** Is the Kernel frozen right now? */
    public static function isFrozen(System $fortress): bool
    {
        return $fortress->frozen_until !== null && $fortress->frozen_until->isFuture();
    }

    /** Tell the owner a run has begun, or has reached the core. */
    public static function alarm(System $fortress, string $key): void
    {
        $definitions = FortressAccess::definitions($fortress);
        $ids = collect([$fortress->owner_character_id]);

        foreach (DB::table('fortress_members')->where('system_id', $fortress->getKey())->get() as $m) {
            $roles = $m->roles === null ? [$m->role] : (array) json_decode((string) $m->roles, true);

            foreach ($roles as $role) {
                if (in_array('alarm', $definitions[$role] ?? [], true)) {
                    $ids->push($m->character_id);

                    break;
                }
            }
        }

        $ids = $ids->unique();

        foreach (Character::query()->whereKey($ids)->get() as $who) {
            (new Notifier)->send($who, Category::Cyberspace, $key, ['system' => $fortress->displayName()]);
        }
    }
}
