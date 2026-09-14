<?php

declare(strict_types=1);

namespace Tests\Unit\Cyberspace;

use App\Cyberspace\Address;
use PHPUnit\Framework\TestCase;

/**
 * Eight hex characters, random, and carrying no information.
 *
 * The last part is the design: a position cannot be read out of an address, so
 * trading one is harmless and expected. Nothing here should ever learn to
 * derive one from the other.
 */
final class AddressTest extends TestCase
{
    public function test_an_address_is_eight_upper_case_hex_characters(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{8}$/', Address::generate());
        }
    }

    /** Injected, so a test can pin it — the same reason the market's roll is. */
    public function test_the_source_of_randomness_can_be_pinned(): void
    {
        $address = Address::generate(static fn (int $length): string => str_repeat("\xAB", $length));

        $this->assertSame('ABABABAB', $address);
    }

    public function test_it_knows_what_is_not_an_address(): void
    {
        $this->assertTrue(Address::isValid('A3F91C08'));

        $this->assertFalse(Address::isValid('a3f91c08'), 'lower case is not the stored form');
        $this->assertFalse(Address::isValid('A3F91C0'), 'seven characters');
        $this->assertFalse(Address::isValid('A3F91C089'), 'nine characters');
        $this->assertFalse(Address::isValid('A3F91C0G'), 'G is not a hex digit');
        $this->assertFalse(Address::isValid(''));
    }

    /**
     * A typed address arrives with a stray space and in whatever case the
     * keyboard was in, and neither of those is the player being wrong.
     */
    public function test_what_a_player_types_is_normalised(): void
    {
        $this->assertSame('A3F91C08', Address::normalise('  a3f91c08 '));
        $this->assertSame('A3F91C08', Address::normalise('A3F91C08'));
    }

    /** Null means "that is not an address", which a screen can say. */
    public function test_normalising_something_that_is_not_an_address_gives_null(): void
    {
        $this->assertNull(Address::normalise('mil:4A19'), 'a subnet address is its own space');
        $this->assertNull(Address::normalise('hello'));
        $this->assertNull(Address::normalise(''));
    }
}
