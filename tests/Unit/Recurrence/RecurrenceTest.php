<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\Recurrence;

use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\Recurrence\Frequency;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\Recurrence\RecurrencePart;
use Erenav\ICalendar\Recurrence\Weekday;
use Erenav\ICalendar\Recurrence\WeekdayRule;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Value;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurrenceTest extends TestCase
{
    public function test_is_a_value(): void
    {
        $this->assertInstanceOf(Value::class, Recurrence::daily());
    }

    public function test_fluent_construction(): void
    {
        $rule = Recurrence::weekly()->every(2)->on(Weekday::Monday, Weekday::Wednesday)->times(10);

        $this->assertSame(Frequency::Weekly, $rule->frequency);
        $this->assertSame(2, $rule->interval);
        $this->assertSame(10, $rule->count);
        $this->assertSame('FREQ=WEEKLY;COUNT=10;INTERVAL=2;BYDAY=MO,WE', $rule->toString());
    }

    public function test_monthly_with_ordinal_weekday_and_setpos(): void
    {
        $rule = Recurrence::monthly()->on(new WeekdayRule(Weekday::Thursday))->setPositions(-1);
        $this->assertSame('FREQ=MONTHLY;BYDAY=TH;BYSETPOS=-1', $rule->toString());
    }

    public function test_count_and_until_are_mutually_exclusive_in_constructor(): void
    {
        $this->expectException(InvalidValueException::class);
        new Recurrence(Frequency::Daily, count: 5, until: DateTimeValue::date(new \DateTimeImmutable('2026-12-31')));
    }

    public function test_programmatic_until_rejects_a_tzid_that_rrule_cannot_serialize(): void
    {
        $until = DateTimeValue::zoned(
            new \DateTimeImmutable('2026-12-31 23:59:59', new \DateTimeZone('America/New_York')),
            'America/New_York',
        );

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('UNTIL cannot carry a TZID');
        Recurrence::daily()->until($until);
    }

    public function test_until_clears_count_and_vice_versa(): void
    {
        $withUntil = Recurrence::daily()->times(5)->until(new \DateTimeImmutable('2026-12-31', new \DateTimeZone('UTC')));
        $this->assertNull($withUntil->count);
        $this->assertNotNull($withUntil->until);

        $withCount = Recurrence::daily()->until(new \DateTimeImmutable('2026-12-31', new \DateTimeZone('UTC')))->times(3);
        $this->assertNull($withCount->until);
        $this->assertSame(3, $withCount->count);
    }

    public function test_interval_must_be_positive(): void
    {
        $this->expectException(InvalidValueException::class);
        Recurrence::daily()->every(0);
    }

    public function test_count_and_interval_enforce_rfc_integer_upper_bound_without_overflow(): void
    {
        foreach (['FREQ=DAILY;COUNT=2147483648', 'FREQ=DAILY;INTERVAL=999999999999999999999999'] as $wire) {
            try {
                Recurrence::parse($wire);
                $this->fail(sprintf('Expected "%s" to be rejected.', $wire));
            } catch (InvalidValueException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidValueException::class);
        Recurrence::daily()->every(2147483648);
    }

    #[DataProvider('roundTripProvider')]
    public function test_parse_round_trips(string $rrule): void
    {
        $this->assertSame($rrule, Recurrence::parse($rrule)->toString());
    }

    /** @return array<string, array{string}> */
    public static function roundTripProvider(): array
    {
        return [
            'simple daily' => ['FREQ=DAILY'],
            'weekly with interval and days' => ['FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE,FR'],
            'count' => ['FREQ=DAILY;COUNT=10'],
            'until utc' => ['FREQ=WEEKLY;UNTIL=20261231T235959Z;BYDAY=MO'],
            'until date' => ['FREQ=DAILY;UNTIL=20261231'],
            'monthly last friday' => ['FREQ=MONTHLY;BYDAY=-1FR'],
            'monthly setpos' => ['FREQ=MONTHLY;BYDAY=TU,TH;BYSETPOS=1'],
            'yearly by month' => ['FREQ=YEARLY;BYMONTHDAY=15;BYMONTH=6'],
            'with wkst' => ['FREQ=WEEKLY;BYDAY=SA,SU;WKST=SU'],
        ];
    }

    public function test_parse_requires_freq(): void
    {
        $this->expectException(InvalidValueException::class);
        Recurrence::parse('INTERVAL=2;BYDAY=MO');
    }

    public function test_parse_preserves_unknown_parts_leniently_across_repeated_round_trips(): void
    {
        $wire = 'FREQ=DAILY;X-VENDOR=foo;IANA-THING=a,b;X-VENDOR=bar';
        $rule = Recurrence::parse($wire);
        $this->assertSame($wire, $rule->toString());
        $this->assertSame($wire, Recurrence::parse($rule->toString())->toString());
        $this->assertSame(['X-VENDOR', 'IANA-THING', 'X-VENDOR'], array_column($rule->unknownParts, 'name'));
    }

    public function test_strict_parse_rejects_unknown_parts(): void
    {
        $this->expectException(InvalidValueException::class);
        Recurrence::parse('FREQ=DAILY;X-VENDOR=foo', true);
    }

    #[DataProvider('invalidNumericProvider')]
    public function test_numeric_parts_are_validated(string $wire): void
    {
        $this->expectException(InvalidValueException::class);
        Recurrence::parse($wire);
    }

    /** @return array<string, array{string}> */
    public static function invalidNumericProvider(): array
    {
        return [
            'month' => ['FREQ=YEARLY;BYMONTH=13'],
            'month day zero' => ['FREQ=MONTHLY;BYMONTHDAY=0'],
            'month day positive bound' => ['FREQ=MONTHLY;BYMONTHDAY=32'],
            'month day negative bound' => ['FREQ=MONTHLY;BYMONTHDAY=-32'],
            'hour' => ['FREQ=DAILY;BYHOUR=24'],
            'minute' => ['FREQ=HOURLY;BYMINUTE=60'],
            'second negative' => ['FREQ=MINUTELY;BYSECOND=-1'],
            'second upper bound' => ['FREQ=MINUTELY;BYSECOND=61'],
            'year day zero' => ['FREQ=YEARLY;BYYEARDAY=0'],
            'year day positive bound' => ['FREQ=YEARLY;BYYEARDAY=367'],
            'year day negative bound' => ['FREQ=YEARLY;BYYEARDAY=-367'],
            'week number zero' => ['FREQ=YEARLY;BYWEEKNO=0'],
            'week number positive bound' => ['FREQ=YEARLY;BYWEEKNO=54'],
            'week number negative bound' => ['FREQ=YEARLY;BYWEEKNO=-54'],
            'week number frequency' => ['FREQ=MONTHLY;BYWEEKNO=1'],
            'set position' => ['FREQ=YEARLY;BYSETPOS=367'],
            'weekly month day' => ['FREQ=WEEKLY;BYMONTHDAY=1'],
            'daily year day' => ['FREQ=DAILY;BYYEARDAY=1'],
            'daily ordinal weekday' => ['FREQ=DAILY;BYDAY=1MO'],
            'weekday ordinal bound' => ['FREQ=MONTHLY;BYDAY=54MO'],
            'numeric weekday with week number' => ['FREQ=YEARLY;BYWEEKNO=1;BYDAY=1MO'],
            'set position alone' => ['FREQ=MONTHLY;BYSETPOS=1'],
            'count zero' => ['FREQ=DAILY;COUNT=0'],
            'count negative' => ['FREQ=DAILY;COUNT=-1'],
            'impossible until date' => ['FREQ=DAILY;UNTIL=20260231'],
            'impossible until time' => ['FREQ=DAILY;UNTIL=20260228T250000Z'],
        ];
    }

    public function test_weekday_rule_rejects_zero_ordinal(): void
    {
        $this->expectException(InvalidValueException::class);
        new WeekdayRule(Weekday::Monday, 0);
    }

    public function test_programmatic_lists_reject_invalid_element_types_with_library_exception(): void
    {
        $this->expectException(InvalidValueException::class);
        new Recurrence(Frequency::Daily, byHour: ['10']);
    }

    public function test_programmatic_byday_rejects_non_rule_elements_with_library_exception(): void
    {
        $this->expectException(InvalidValueException::class);
        new Recurrence(Frequency::Daily, byDay: [Weekday::Monday]);
    }

    public function test_unknown_part_cannot_shadow_a_standard_strongly_typed_part(): void
    {
        $this->expectException(InvalidValueException::class);
        new RecurrencePart('COUNT', '2');
    }

    public function test_unknown_part_rejects_structural_or_control_injection(): void
    {
        foreach (['a;b', "a\r\nDTSTART:20260101T000000Z", "a\0b", 'a\\'] as $value) {
            try {
                new RecurrencePart('X-FOO', $value);
                $this->fail('Expected the unsafe unknown RRULE value to be rejected.');
            } catch (InvalidValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unknown_text_with_an_escaped_semicolon_is_a_fixed_point(): void
    {
        $wire = 'FREQ=DAILY;X-FOO=a\\;b;IANA-FOO=c';
        $once = Recurrence::parse($wire)->toString();

        $this->assertSame($wire, $once);
        $this->assertSame($wire, Recurrence::parse($once)->toString());
    }
}
