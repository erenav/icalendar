<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\ValueType;

use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\ValueType\GeoValue;
use PHPUnit\Framework\TestCase;

final class GeoValueTest extends TestCase
{
    public function test_of_and_to_string(): void
    {
        $geo = GeoValue::of(37.386013, -122.082932);
        $this->assertSame('37.386013;-122.082932', $geo->toString());
    }

    public function test_parse_round_trips(): void
    {
        $geo = GeoValue::parse('37.386013;-122.082932');
        $this->assertSame(37.386013, $geo->latitude);
        $this->assertSame(-122.082932, $geo->longitude);
    }

    public function test_parse_preserves_external_precision_and_lexical_form(): void
    {
        $wire = '+37.386013123456789;-122.082932987654321';

        $this->assertSame($wire, GeoValue::parse($wire)->toString());
    }

    public function test_parse_rejects_malformed(): void
    {
        $this->expectException(InvalidValueException::class);
        GeoValue::parse('37.386013,-122.082932');
    }

    public function test_parse_rejects_non_rfc_exponent_notation(): void
    {
        $this->expectException(InvalidValueException::class);
        GeoValue::parse('1e1;2');
    }

    public function test_latitude_out_of_range_is_rejected(): void
    {
        $this->expectException(InvalidValueException::class);
        GeoValue::of(91.0, 0.0);
    }

    public function test_longitude_out_of_range_is_rejected(): void
    {
        $this->expectException(InvalidValueException::class);
        GeoValue::of(0.0, 181.0);
    }

    public function test_non_finite_coordinate_is_rejected(): void
    {
        $this->expectException(InvalidValueException::class);
        GeoValue::of(NAN, 0.0);
    }

    public function test_equality(): void
    {
        $this->assertTrue(GeoValue::of(1.5, 2.5)->equals(GeoValue::of(1.5, 2.5)));
        $this->assertFalse(GeoValue::of(1.5, 2.5)->equals(GeoValue::of(1.5, 2.6)));
    }
}
