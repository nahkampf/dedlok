<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/** A choice for one side's turn: a kind, and for Load the round it is to take. */
final readonly class Action
{
    public function __construct(public ActionKind $kind, public ?string $round = null) {}

    public static function attack(): self
    {
        return new self(ActionKind::Attack);
    }

    /** Load (or switch to) the round with this key. */
    public static function load(string $round): self
    {
        return new self(ActionKind::Load, $round);
    }

    public static function createDistance(): self
    {
        return new self(ActionKind::CreateDistance);
    }

    public static function closeIn(): self
    {
        return new self(ActionKind::CloseIn);
    }

    public static function disengage(): self
    {
        return new self(ActionKind::Disengage);
    }

    public static function use(int $rowId): self
    {
        return new self(ActionKind::Use, (string) $rowId);
    }

    public static function brace(): self
    {
        return new self(ActionKind::Brace);
    }
}
