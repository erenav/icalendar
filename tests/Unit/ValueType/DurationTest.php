<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\ValueType;

use DateInterval;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\ValueType\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    public function test_single_unit_factories(): void
    {
        $this->assertSame('P2W', Duration::weeks(2)->toString());
        $this->assertSame('P3D', Duration::days(3)->toString());
        $this->assertSame('PT5H', Duration::hours(5)->toString());
        $this->assertSame('PT30M', Duration::minutes(30)->toString());
        $this->assertSame('PT20S', Duration::seconds(20)->toString());
        $this->assertSame('PT0S', Duration::zero()->toString());
    }

    public function test_negative_factory_derives_sign(): void
    {
        $this->assertSame('-PT1H', Duration::hours(-1)->toString());
        $this->assertTrue(Duration::hours(-1)->negative);
        $this->assertSame(1, Duration::hours(-1)->hours);
    }

    public function test_combined_components(): void
    {
        $duration = Duration::of(days: 15, hours: 5, minutes: 0, seconds: 20);
        $this->assertSame('P15DT5H20S', $duration->toString());
    }

    public function test_week_form_cannot_mix_with_other_components(): void
    {
        $this->expectException(InvalidValueException::class);
        Duration::of(weeks: 1, days: 2);
    }

    public function test_negative_component_is_rejected(): void
    {
        $this->expectException(InvalidValueException::class);
        Duration::of(days: -2);
    }

    #[DataProvider('roundTripProvider')]
    public function test_parse_round_trips(string $literal): void
    {
        $this->assertSame($literal, Duration::parse($literal)->toString());
    }

    /** @return array<string, array{string}> */
    public static function roundTripProvider(): array
    {
        return [
            'weeks' => ['P2W'],
            'days' => ['P3D'],
            'days and time' => ['P15DT5H20S'],
            'time only' => ['PT1H30M'],
            'negative' => ['-PT1H30M'],
            'seconds' => ['PT45S'],
            'zero' => ['PT0S'],
        ];
    }

    public function test_parse_rejects_garbage(): void
    {
        $this->expectException(InvalidValueException::class);
        Duration::parse('1 hour');
    }

    public function test_parse_rejects_empty_duration(): void
    {
        $this->expectException(InvalidValueException::class);
        Duration::parse('PT');
    }

    public function test_parse_rejects_components_and_totals_that_would_overflow(): void
    {
        foreach (['P999999999999999999999999D', 'P'.PHP_INT_MAX.'D'] as $wire) {
            try {
                Duration::parse($wire);
                $this->fail(sprintf('Expected "%s" to be rejected.', $wire));
            } catch (InvalidValueException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidValueException::class);
        Duration::seconds(PHP_INT_MIN);
    }

    public function test_to_seconds(): void
    {
        $this->assertSame(3600, Duration::hours(1)->toSeconds());
        $this->assertSame(604800, Duration::weeks(1)->toSeconds());
        $this->assertSame(-90, Duration::of(minutes: 1, seconds: 30, negative: true)->toSeconds());
    }

    public function test_equality_is_semantic(): void
    {
        $this->assertTrue(Duration::weeks(1)->equals(Duration::days(7)));
        $this->assertFalse(Duration::days(1)->equals(Duration::hours(1)));
    }

    public function test_from_date_interval(): void
    {
        $duration = Duration::fromDateInterval(new DateInterval('P1DT2H'));
        $this->assertSame('P1DT2H', $duration->toString());
    }

    public function test_from_date_interval_rejects_months_and_years(): void
    {
        $this->expectException(InvalidValueException::class);
        Duration::fromDateInterval(new DateInterval('P1M'));
    }

    public function test_from_date_interval_preserves_sign(): void
    {
        $interval = new DateInterval('PT2H');
        $interval->invert = 1;
        $this->assertSame('-PT2H', Duration::fromDateInterval($interval)->toString());
    }

    public function test_to_date_interval_round_trips_via_seconds(): void
    {
        $original = Duration::of(days: 2, hours: 3, minutes: 4, seconds: 5);
        $interval = $original->toDateInterval();
        $this->assertSame($original->toSeconds(), Duration::fromDateInterval($interval)->toSeconds());
    }

    public function test_to_date_interval_folds_weeks_into_days(): void
    {
        $interval = Duration::weeks(2)->toDateInterval();
        $this->assertSame(14, $interval->d);
    }

    public function test_to_date_interval_marks_negative(): void
    {
        $this->assertSame(1, Duration::hours(-1)->toDateInterval()->invert);
    }
}
