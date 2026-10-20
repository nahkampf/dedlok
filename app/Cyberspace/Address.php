<?php

declare(strict_types=1);

namespace App\Cyberspace;

use Closure;

/**
 * An address: 8 hexadecimal characters, unique, random, and meaningless.
 *
 * MEANINGLESS IS THE POINT. A position cannot be read out of an address, so
 * knowing one tells you where to arrive and nothing about where it is — which
 * is what makes trading addresses harmless and expected. It is the same act as
 * inviting somebody onto a shared run, and at four million positions the
 * constraint on exploration was never secrecy. What paces exploration is
 * patience: you walk, you bookmark the frontier, you come back.
 *
 * ADDRESSES DO NOT SURVIVE. A burned system takes its address out of existence
 * and a relocated one comes back wearing a new one, so an address is a fact
 * about a system right now rather than a name it keeps. Anything storing one —
 * a bookmark, a contact, a scan — is storing something that can stop resolving,
 * and every one of those has to treat "nothing there" as an ordinary answer
 * rather than an error.
 *
 * Subnet addresses are a different space and deliberately not confusable with
 * these: a prefix naming the subnet (2 to 5 lower-case letters) and 16 bits of
 * address, `mil:4A19`. The prefix is what removes the guess about which space an
 * address belongs to, so it is stored whole and is globally unique; 16 bits is
 * 65,536 of them, so a collision is ordinary there and the writer retries.
 */
final class Address
{
    public const LENGTH = 8;

    /** Upper case, and the schema's CHECK says the same thing in SQL. */
    private const PATTERN = '/^([0-9A-F]{8}|[a-z]{2,5}:[0-9A-F]{4})$/';

    /**
     * A new address.
     *
     * The source of randomness is injected so a test can pin it — the same
     * reason MarketSeeder takes its roll — and defaults to random_bytes(),
     * which is the right generator for a value whose only job is to be
     * unguessable and unique.
     *
     * Uniqueness is NOT checked here: it is a unique index on the table, and
     * the retry belongs to whatever is writing the row. Four bytes is 4.3
     * billion values against a grid of four million, so a collision is a thing
     * that happens once in a long while and never twice in a row.
     *
     * A subnet's address takes its prefix (`grids.prefix`) and two random bytes.
     *
     * @param  ?Closure(int): string  $bytes
     */
    public static function generate(?Closure $bytes = null, ?string $prefix = null): string
    {
        $bytes ??= static fn (int $length): string => random_bytes($length);

        return $prefix === null
            ? strtoupper(bin2hex($bytes(self::LENGTH / 2)))
            : $prefix.':'.strtoupper(bin2hex($bytes(2)));
    }

    public static function isValid(string $address): bool
    {
        return preg_match(self::PATTERN, $address) === 1;
    }

    /**
     * What a player typed, as an address — or null if it is not one.
     *
     * Trimmed and upper-cased, because a typed address is going to arrive with
     * a stray space and in whatever case the keyboard was in, and neither of
     * those is the player being wrong. Null is the answer for everything else,
     * including the empty string: it means "that is not an address", which the
     * jump screen says rather than throwing.
     */
    public static function normalise(string $input): ?string
    {
        // The prefix is lower case and the hex upper, so each half is folded to its own case.
        $input = trim($input);
        $address = str_contains($input, ':')
            ? strtolower(strstr($input, ':', true)).':'.strtoupper(substr(strstr($input, ':'), 1))
            : strtoupper($input);

        return self::isValid($address) ? $address : null;
    }
}
