<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\ValueType;

use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\ValueType\UtcOffset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UtcOffsetTest extends TestCase
{
    public function test_of_builds_signed_offset(): void
    {
        $this->assertSame('+0500', UtcOffset::of(5, 0)->toString());
        $this->assertSame('-0730', UtcOffset::of(7, 30, negative: true)->toString());
    }

    public function test_seconds_component_only_emitted_when_present(): void
    {
        $this->assertSame('+0000', UtcOffset::of(0, 0)->toString());
        $this->assertSame('+053045', UtcOffset::of(5, 30, 45)->toString());
    }

    #[DataProvider('roundTripProvider')]
    public function test_parse_round_trips(string $literal): void
    {
        $this->assertSame($literal, UtcOffset::parse($literal)->toString());
    }

    /** @return array<string, array{string}> */
    public static function roundTripProvider(): array
    {
        return [
            'positive' => ['+0500'],
            'negative' => ['-0730'],
            'zero' => ['+0000'],
            'with seconds' => ['+053045'],
        ];
    }

    public function test_parse_rejects_negative_zero(): void
    {
        $this->expectException(InvalidValueException::class);
        UtcOffset::parse('-0000');
    }

    public function test_parse_rejects_unsigned(): void
    {
        $this->expectException(InvalidValueException::class);
        UtcOffset::parse('0500');
    }

    public function test_total_seconds(): void
    {
        $this->assertSame(18000, UtcOffset::parse('+0500')->totalSeconds);
        $this->assertSame(-27000, UtcOffset::parse('-0730')->totalSeconds);
    }

    public function test_equality(): void
    {
        $this->assertTrue(UtcOffset::of(5, 0)->equals(UtcOffset::fromSeconds(18000)));
    }

    public function test_component_ranges_are_validated(): void
    {
        $this->expectException(InvalidValueException::class);
        UtcOffset::parse('+246000');
    }

    public function test_maximum_grammar_value_stays_rfc_serializable(): void
    {
        $this->assertSame('+235960', UtcOffset::parse('+235960')->toString());
    }
}
