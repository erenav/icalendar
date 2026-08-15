<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\ValueType;

use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\ValueType\CalAddress;
use PHPUnit\Framework\TestCase;

final class CalAddressTest extends TestCase
{
    public function test_from_email_adds_mailto_scheme(): void
    {
        $address = CalAddress::fromEmail('jane@example.com');
        $this->assertSame('mailto:jane@example.com', $address->toString());
        $this->assertSame('jane@example.com', $address->email());
    }

    public function test_from_uri_preserves_non_mailto(): void
    {
        $address = CalAddress::fromUri('https://example.com/principals/jane');
        $this->assertSame('https://example.com/principals/jane', $address->toString());
        $this->assertNull($address->email());
    }

    public function test_from_uri_rejects_missing_scheme(): void
    {
        $this->expectException(InvalidValueException::class);
        CalAddress::fromUri('jane@example.com');
    }

    public function test_from_email_rejects_empty(): void
    {
        $this->expectException(InvalidValueException::class);
        CalAddress::fromEmail('   ');
    }

    public function test_email_extraction_is_case_insensitive_on_scheme(): void
    {
        $this->assertSame('jane@example.com', CalAddress::fromUri('MAILTO:jane@example.com')->email());
    }

    public function test_equality(): void
    {
        $this->assertTrue(CalAddress::fromEmail('a@b.com')->equals(CalAddress::fromUri('mailto:a@b.com')));
    }

    public function test_rejects_content_line_injection(): void
    {
        foreach ([
            "victim@example.test\r\nATTENDEE:mailto:injected@example.test",
            "mailto:victim@example.test\nATTENDEE:mailto:injected@example.test",
        ] as $address) {
            try {
                str_starts_with($address, 'mailto:')
                    ? CalAddress::fromUri($address)
                    : CalAddress::fromEmail($address);
                $this->fail('Expected a calendar-address line break to be rejected.');
            } catch (InvalidValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
