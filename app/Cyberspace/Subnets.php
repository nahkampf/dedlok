<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\System;
use App\Support\Text;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `grids` rows beyond the main one, and of a subnet's gateway pair (docs/CYBERSPACE.md, *Subnets*).
 *
 * Creating a subnet makes the grid, its gateway at the entry square and the partner gateway somewhere free on the main grid,
 * each pointing at the other. The main-grid gateway is grade 9 and carries the ICE the author picked; the subnet side is the way out: undefended, and no run may be started against it. The prefix, size and entry square are
 * permanent: addresses embed the prefix and systems stand on the coordinates.
 */
final readonly class Subnets
{
    /** @param  Closure(int, int): int  $roll */
    public function __construct(private Closure $roll) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        return new self($roll ?? static fn (int $min, int $max): int => random_int($min, $max));
    }

    /**
     * @param  array{slug: string, name: string, prefix: string, size: int, entry_x: int, entry_y: int}  $grid
     * @param  array<string, mixed>  $spec
     * @param  list<array<string, string>>  $rules  Entry rules for the main-grid gateway.
     * @return ?int The new grid's id, or null when the main grid has no free square for the partner gateway.
     */
    public function create(array $grid, array $spec, ?int $iceId, array $rules): ?int
    {
        $at = Grid::fromConfig()->freePosition($this->roll);

        if ($at === null) {
            return null;
        }

        return DB::transaction(function () use ($grid, $spec, $iceId, $rules, $at): int {
            $id = DB::table('grids')->insertGetId([
                ...$grid,
                'spec' => json_encode($spec),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inner = $this->gateway($id, new Position($grid['entry_x'], $grid['entry_y'], $id), Text::get('admin.subnets.gateway_out'), $grid['prefix'], $iceId);
            $outer = $this->gateway(1, $at, Text::get('admin.subnets.gateway_in', ['name' => $grid['name']]), null, $iceId);
            $outer->update(['links_to_system_id' => $inner->getKey(), 'entry_rules' => $rules ?: null]);
            $inner->update(['links_to_system_id' => $outer->getKey()]);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<array<string, string>>  $rules
     */
    public function update(int $id, string $name, array $spec, ?int $iceId, array $rules): void
    {
        DB::transaction(function () use ($id, $name, $spec, $iceId, $rules): void {
            DB::table('grids')->where('id', $id)->update(['name' => $name, 'spec' => json_encode($spec), 'updated_at' => now()]);

            foreach (System::query()->where('type', SystemType::Gateway->value)->where('grid_id', $id)->get() as $inner) {
                $inner->linksTo?->update([
                    'ice_id' => $iceId,
                    'ice_bounty' => false,
                    'name' => Text::get('admin.subnets.gateway_in', ['name' => $name]),
                    'entry_rules' => $rules ?: null,
                ]);
            }
        });
    }

    /** Is anybody on this grid, or due to come back to it? */
    public function occupied(int $id): bool
    {
        return DB::table('characters')->where('grid_id', $id)->where(fn ($q) => $q->whereNotNull('grid_x')->orWhereNotNull('resume_x'))->exists();
    }

    /** Remove the subnet, its systems and its partner gateway. False while anybody is on it. */
    public function delete(int $id): bool
    {
        if ($id === 1 || $this->occupied($id)) {
            return false;
        }

        DB::transaction(function () use ($id): void {
            $partners = System::query()->where('grid_id', $id)->where('type', SystemType::Gateway->value)->pluck('links_to_system_id')->filter();

            System::query()->whereIn('id', $partners)->update(['links_to_system_id' => null]);
            System::query()->where('grid_id', $id)->update(['links_to_system_id' => null]);
            System::query()->whereIn('id', $partners)->delete();
            System::query()->where('grid_id', $id)->delete();
            DB::table('characters')->where('grid_id', $id)->update(['grid_id' => 1, 'resume_x' => null, 'resume_y' => null]);
            DB::table('grids')->where('id', $id)->delete();
        });

        return true;
    }

    private function gateway(int $gridId, Position $at, string $name, ?string $prefix, ?int $iceId): System
    {
        $used = fn (string $a): bool => System::query()->where('address', $a)->exists();

        do {
            $address = Address::generate(null, $prefix);
        } while ($used($address));

        return System::query()->create([
            'grid_id' => $gridId,
            'address' => $address,
            'x' => $at->x,
            'y' => $at->y,
            'type' => SystemType::Gateway,
            'grade' => Grade::MAX,
            'name' => $name,
            // The way out carries no defences at all; only the way in is guarded.
            ...($gridId === 1 ? [...Defences::roll(SystemType::Gateway, Grade::MAX, $this->roll)->columns(), 'ice_id' => $iceId, 'ice_bounty' => false] : []),
        ]);
    }
}
