<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Skill;
use App\Models\System;

/**
 * Where a stolen file came from, copied off the system the moment it was taken.
 *
 * COPIED, NOT REFERENCED, as everything on an `ExtractedFile` is: the system relocates, is renamed or burns, its owner
 * may go, and a recipe asking for "20 Kernel Logs (AuraOS)" has to keep meaning something. Slugs and ids are the stable
 * keys a recipe filters on, the names are for display, and nothing here has a foreign key. A decrypted file keeps all of
 * it, because decryption converts the row in place.
 *
 * Files taken before this was recorded have none (`ExtractedFile::$origin` is null), so a recipe filter on any of it
 * never matches them.
 */
final readonly class FileOrigin
{
    /** The fields a component may be named by, and a recipe line filtered on. */
    public const HEADLINES = ['grade', 'os', 'owner', 'system_type', 'encryption', 'security'];

    public function __construct(
        public ?string $systemType = null,
        public ?int $ownerId = null,
        public ?string $ownerName = null,
        public ?string $osSlug = null,
        public ?string $osTitle = null,
        public ?int $idsLevel = null,
        public ?int $iceLevel = null,
        public ?string $encryptionSlug = null,
        public ?string $encryptionTitle = null,
    ) {}

    public static function fromSystem(?System $system, ?Skill $encryption): self
    {
        if ($system === null) {
            return new self(encryptionSlug: $encryption?->slug, encryptionTitle: $encryption?->title);
        }

        $os = $system->operatingSystem;

        return new self(
            systemType: $system->type->value,
            ownerId: $system->owner_id,
            ownerName: $system->owner?->name,
            osSlug: $os?->slug,
            osTitle: $os?->title,
            idsLevel: (int) $system->ids_detection,
            iceLevel: (int) ($system->ice?->level ?? 0),
            encryptionSlug: $encryption?->slug,
            encryptionTitle: $encryption?->title,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'system_type' => $this->systemType,
            'owner_id' => $this->ownerId,
            'owner_name' => $this->ownerName,
            'os_slug' => $this->osSlug,
            'os_title' => $this->osTitle,
            'ids_level' => $this->idsLevel,
            'ice_level' => $this->iceLevel,
            'encryption_slug' => $this->encryptionSlug,
            'encryption_title' => $this->encryptionTitle,
        ];
    }

    /** @param  array<string, mixed>|null  $stored */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null) {
            return null;
        }

        return new self(
            systemType: $stored['system_type'] ?? null,
            ownerId: isset($stored['owner_id']) ? (int) $stored['owner_id'] : null,
            ownerName: $stored['owner_name'] ?? null,
            osSlug: $stored['os_slug'] ?? null,
            osTitle: $stored['os_title'] ?? null,
            idsLevel: isset($stored['ids_level']) ? (int) $stored['ids_level'] : null,
            iceLevel: isset($stored['ice_level']) ? (int) $stored['ice_level'] : null,
            encryptionSlug: $stored['encryption_slug'] ?? null,
            encryptionTitle: $stored['encryption_title'] ?? null,
        );
    }
}
