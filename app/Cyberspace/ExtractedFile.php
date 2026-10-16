<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\SystemContent;
use App\Support\Qb;

/**
 * What a file was, before it became a generic "Encrypted Data Dump" row.
 *
 * A VALUE OBJECT OVER A JSON BLOB, the same shape `App\Characters\DownedCause`
 * takes for the same reason: the blob has exactly one author and one reader,
 * and decryption and selling are not designed, so columns modelled against
 * either would be modelled wrong. This is the shape it becomes the day they
 * settle.
 *
 * Every field here started life on a `system_contents` row — see that model —
 * and is copied rather than referenced, because the content row it came from
 * is deleted the moment extraction takes it. The file has to outlive its own
 * origin, the same way a scan outlives the machine it read.
 */
final readonly class ExtractedFile
{
    /** The item a file becomes once decrypted; its row is the same row, so the value it fetches is the broken one. */
    public const DECRYPTED_SLUG = 'memory-dump';

    /** The item every file is while it is still sealed; anything else carrying a file has been broken open. */
    public const SEALED_SLUG = 'encrypted-data-dump';

    public function __construct(
        public string $label,
        public float $sizeQb,
        public ?int $encryptionSkillId,
        public int $valueSealed,
        public int $valueBroken,
        /** The grade of the system it came off; null on a file taken before this was recorded. */
        public ?int $grade = null,
        /** Where it came from; null on a file taken before this was recorded. */
        public ?FileOrigin $origin = null,
        /** The exchange job this file was seeded for; null on an ordinary file. */
        public ?int $missionId = null,
    ) {}

    public static function fromContent(SystemContent $content): self
    {
        return new self(
            label: (string) $content->label,
            sizeQb: Qb::of($content->size_qb),
            encryptionSkillId: $content->encryption_skill_id,
            valueSealed: (int) $content->value_sealed,
            valueBroken: (int) $content->value_broken,
            grade: $content->system?->grade,
            origin: FileOrigin::fromSystem($content->system, $content->encryption),
            missionId: $content->mission_id,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'size_qb' => $this->sizeQb,
            'encryption_skill_id' => $this->encryptionSkillId,
            'value_sealed' => $this->valueSealed,
            'value_broken' => $this->valueBroken,
            'grade' => $this->grade,
            'origin' => $this->origin?->toArray(),
            'mission_id' => $this->missionId,
        ];
    }

    /** @param  array<string, mixed>|null  $stored */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null || ! isset($stored['label'])) {
            return null;
        }

        return new self(
            label: (string) $stored['label'],
            sizeQb: Qb::of($stored['size_qb'] ?? 0),
            encryptionSkillId: isset($stored['encryption_skill_id']) ? (int) $stored['encryption_skill_id'] : null,
            valueSealed: (int) ($stored['value_sealed'] ?? 0),
            valueBroken: (int) ($stored['value_broken'] ?? 0),
            grade: isset($stored['grade']) ? (int) $stored['grade'] : null,
            origin: FileOrigin::fromArray($stored['origin'] ?? null),
            missionId: isset($stored['mission_id']) ? (int) $stored['mission_id'] : null,
        );
    }

    /** What a buyer starts from: the broken value once decrypted, the sealed value before. */
    public function stockPrice(bool $decrypted): int
    {
        return $decrypted ? $this->valueBroken : $this->valueSealed;
    }

    /** What breaking it is worth, over selling it sealed — the number a player weighs sitting on one against. */
    public function decryptionPremium(): int
    {
        return $this->valueBroken - $this->valueSealed;
    }
}
