<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\SystemContent;

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

    public function __construct(
        public string $label,
        public int $sizeQb,
        public ?int $encryptionSkillId,
        public int $valueSealed,
        public int $valueBroken,
        /** The grade of the system it came off; null on a file taken before this was recorded. */
        public ?int $grade = null,
    ) {}

    public static function fromContent(SystemContent $content): self
    {
        return new self(
            label: (string) $content->label,
            sizeQb: (int) $content->size_qb,
            encryptionSkillId: $content->encryption_skill_id,
            valueSealed: (int) $content->value_sealed,
            valueBroken: (int) $content->value_broken,
            grade: $content->system?->grade,
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
            sizeQb: (int) ($stored['size_qb'] ?? 0),
            encryptionSkillId: isset($stored['encryption_skill_id']) ? (int) $stored['encryption_skill_id'] : null,
            valueSealed: (int) ($stored['value_sealed'] ?? 0),
            valueBroken: (int) ($stored['value_broken'] ?? 0),
            grade: isset($stored['grade']) ? (int) $stored['grade'] : null,
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
