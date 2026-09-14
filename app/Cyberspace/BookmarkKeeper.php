<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Bookmark;
use App\Models\Character;

/**
 * The only writer of `bookmarks`, because the rule that makes them safe cannot
 * be expressed in the schema.
 *
 * A WAYPOINT MUST SIT ON GROUND THE CHARACTER HAS SWEPT. That is a fact about
 * a bitmap in another table, so no CHECK can reach it, and it is the single
 * most important thing here: without it a position could be bookmarked sight
 * unseen, coordinates would become tradeable, and the map would be solved by
 * whoever posted a list of them. Exploration is paced by patience, and this is
 * what stops that being optional.
 *
 * A SYSTEM BOOKMARK HAS NO SUCH GATE and deliberately so. Any address may be
 * saved, seen or not, because trading addresses is expected and harmless: an
 * address carries no position, so it is a door rather than a map.
 */
final class BookmarkKeeper
{
    public function __construct(
        private readonly Grid $grid,
    ) {}

    public static function fromConfig(): self
    {
        return new self(Grid::fromConfig());
    }

    /**
     * Save a position.
     *
     * The label is optional; without one the screen falls back to the
     * coordinates, which is a perfectly good name for a place.
     */
    public function markPosition(Character $character, Position $position, ?string $label = null): BookmarkResult
    {
        if (! $this->grid->contains($position)) {
            return BookmarkResult::refused(BookmarkRefusal::OffTheGrid);
        }

        if (! (new Terrain($character))->hasSwept($position)) {
            return BookmarkResult::refused(BookmarkRefusal::NeverSwept);
        }

        $existing = Bookmark::query()
            ->where('character_id', $character->id)
            ->where('x', $position->x)
            ->where('y', $position->y)
            ->first();

        if ($existing !== null) {
            return BookmarkResult::refused(BookmarkRefusal::AlreadySaved);
        }

        return BookmarkResult::saved(Bookmark::query()->create([
            'character_id' => $character->id,
            'kind' => BookmarkKind::Waypoint,
            'x' => $position->x,
            'y' => $position->y,
            'label' => $this->clean($label),
        ]));
    }

    /**
     * Save an address.
     *
     * NOT checked against anything standing there. An address somebody traded
     * you is worth saving before you have been to it — that is what a traded
     * address is FOR — and one that has already died is worth saving no less,
     * since the screen will simply say nothing answers to it.
     */
    public function markAddress(Character $character, string $address, ?string $label = null): BookmarkResult
    {
        $address = Address::normalise($address);

        if ($address === null) {
            return BookmarkResult::refused(BookmarkRefusal::NotAnAddress);
        }

        $existing = Bookmark::query()
            ->where('character_id', $character->id)
            ->where('address', $address)
            ->first();

        if ($existing !== null) {
            return BookmarkResult::refused(BookmarkRefusal::AlreadySaved);
        }

        return BookmarkResult::saved(Bookmark::query()->create([
            'character_id' => $character->id,
            'kind' => BookmarkKind::System,
            'address' => $address,
            'label' => $this->clean($label),
        ]));
    }

    /** Rename one, or clear the name back to its fallback. */
    public function rename(Character $character, Bookmark $bookmark, ?string $label): BookmarkResult
    {
        if ($bookmark->character_id !== $character->id) {
            return BookmarkResult::refused(BookmarkRefusal::NotYours);
        }

        $bookmark->update(['label' => $this->clean($label)]);

        return BookmarkResult::saved($bookmark);
    }

    /**
     * Forget one.
     *
     * Nothing confirms this, unlike destroying an item: a bookmark is a note,
     * and losing a note costs a walk rather than an object.
     */
    public function forget(Character $character, Bookmark $bookmark): BookmarkResult
    {
        if ($bookmark->character_id !== $character->id) {
            return BookmarkResult::refused(BookmarkRefusal::NotYours);
        }

        $bookmark->delete();

        return BookmarkResult::forgotten();
    }

    /**
     * A label, or null.
     *
     * Blank and whitespace-only come back as null rather than as an empty
     * string, so "no name" is one state instead of two that render alike.
     */
    private function clean(?string $label): ?string
    {
        $label = trim($label ?? '');

        return $label === '' ? null : mb_substr($label, 0, 48);
    }
}
