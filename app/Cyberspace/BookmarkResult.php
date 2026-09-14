<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Bookmark;

/**
 * What came of saving or forgetting a way back.
 *
 * The same shape as TravelResult: a refusal is a value, not an exception.
 */
final class BookmarkResult
{
    private function __construct(
        public readonly ?Bookmark $bookmark,
        public readonly ?BookmarkRefusal $refusal,
    ) {}

    public static function saved(Bookmark $bookmark): self
    {
        return new self($bookmark, null);
    }

    /** Forgotten. There is nothing left to hand back. */
    public static function forgotten(): self
    {
        return new self(null, null);
    }

    public static function refused(BookmarkRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }
}
