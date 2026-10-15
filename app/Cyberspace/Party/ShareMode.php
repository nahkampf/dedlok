<?php

declare(strict_types=1);

namespace App\Cyberspace\Party;

use App\Support\Text;

/**
 * How a party divides something (creds, or the files pulled), chosen by the owner when the party forms.
 *
 * Even: everybody the same. Half: the owner takes half, the others share the rest evenly. Full: the owner takes all.
 */
enum ShareMode: string
{
    case Even = 'even';
    case Half = 'half';
    case Full = 'full';

    /** The owner's fraction of the whole, for a party of `$runners` in all (owner included). */
    public function ownerShare(int $runners): float
    {
        if ($runners <= 1) {
            return 1.0;
        }

        return match ($this) {
            self::Even => 1 / $runners,
            self::Half => 0.5,
            self::Full => 1.0,
        };
    }

    /** What EACH other runner gets, as a fraction of the whole. */
    public function memberShare(int $runners): float
    {
        return $runners <= 1 ? 0.0 : (1.0 - $this->ownerShare($runners)) / ($runners - 1);
    }

    public function label(): string
    {
        return Text::get("cyberspace.party.share.{$this->value}");
    }
}
