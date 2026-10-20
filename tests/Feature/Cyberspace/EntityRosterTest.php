<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Entities\EntityCategory;
use App\Models\Entity;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who owns the machines, and which of them a runner can deal with.
 *
 * `interactable` is the only column here that decides anything: it is the
 * difference between scenery that owns a machine and somebody with an
 * Exchange, a set of books and work to hand out. It defaults to false for the
 * reason `archetypes.playable` does — nothing becomes real by being forgotten.
 */
final class EntityRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_roster_arrives_with_the_schema_and_is_mostly_scenery(): void
    {
        $this->assertGreaterThan(100, Entity::query()->count());

        // Ten desks against a hundred and more companies: being somebody you
        // can work for is the exception and has to be authored.
        $this->assertSame(11, Entity::query()->interactable()->count());
        $this->assertGreaterThan(100, Entity::query()->where('interactable', false)->count());
    }

    public function test_every_category_has_somebody_in_it(): void
    {
        $categories = Entity::query()->interactable()->get()
            ->map(fn (Entity $entity): string => $entity->category->value)
            ->unique()
            ->values()
            ->all();

        sort($categories);

        $expected = EntityCategory::values();
        sort($expected);

        $this->assertSame($expected, $categories);
    }

    /** Somebody you can work for says who they are in their own words. */
    public function test_an_interactable_entity_describes_itself(): void
    {
        foreach (Entity::query()->interactable()->get() as $entity) {
            $this->assertNotNull($entity->description, "{$entity->name} has no description");
            $this->assertNotSame('', trim((string) $entity->business));
        }
    }

    /**
     * The five promoted ones were already in the world, which is the whole
     * reason they were promoted rather than invented: standing has something
     * to be lost against on the day it exists. The grid itself is seeded by a
     * command rather than by a migration, so what is asserted here is that
     * they are corporations that CAN own machines — and one does.
     */
    public function test_a_promoted_entity_owns_machines(): void
    {
        $promoted = Entity::query()
            ->interactable()
            ->where('category', EntityCategory::Corporation)
            ->get();

        $this->assertCount(5, $promoted);

        $entity = $promoted->firstOrFail();
        System::factory()->ownedBy($entity)->create();

        $this->assertSame(1, $entity->systems()->count());
    }

    public function test_a_category_is_an_enum_and_not_a_string(): void
    {
        $entity = Entity::query()->interactable()->firstOrFail();

        $this->assertInstanceOf(EntityCategory::class, $entity->category);
        $this->assertNotSame('', $entity->category->label());
    }
}
