<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Models\CharacterSkill;

/** What one call to SkillInjector::inject() did. */
final class InjectionResult
{
    private function __construct(
        public readonly ?CharacterSkill $progress = null,
        public readonly ?InjectionRefusal $refusal = null,
    ) {}

    public static function injected(CharacterSkill $progress): self
    {
        return new self(progress: $progress);
    }

    public static function refused(InjectionRefusal $refusal): self
    {
        return new self(refusal: $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }
}
