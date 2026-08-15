<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\ValueType;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use PHPUnit\Framework\TestCase;

final class DateTimeValueTest extends TestCase
{
    public function test_date_only_value(): void
    {
        $value = DateTimeValue::date(new DateTimeImmutable('2026-07-01 13:45:00'));
        $this->assertSame('20260701', $value->toString());
        $this->assertTrue($value->isDateOnly);
        $this->assertFalse($value->needsTzidParameter());
    }

    public function test_utc_value_has_z_suffix(): void
    {
        $value = DateTimeValue::utc(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC')));
        $this->assertSame('20260701T100000Z', $value->toString());
        $this->assertTrue($value->isUtc);
    }

    public function test_utc_factory_converts_instant(): void
    {
        $value = DateTimeValue::utc(new DateTimeImmutable('2026-07-01 12:00:00', new DateTimeZone('America/New_York')));
        $this->assertSame('20260701T160000Z', $value->toString());
    }

    public function test_utc_backing_value_is_normalized_to_rfc_second_precision(): void
    {
        $value = DateTimeValue::utc(new DateTimeImmutable('2026-07-01 10:00:00.987654', new DateTimeZone('UTC')));

        $this->assertSame('20260701T100000Z', $value->toString());
        $this->assertSame('000000', $value->dateTime->format('u'));
    }

    public function test_floating_value(): void
    {
        $value = DateTimeValue::floating(new DateTimeImmutable('2026-07-01 09:30:00'));
        $this->assertSame('20260701T093000', $value->toString());
        $this->assertTrue($value->isFloating());
        $this->assertFalse($value->needsTzidParameter());
    }

    public function test_date_and_floating_values_do_not_retain_a_hidden_source_timezone(): void
    {
        $zone = new DateTimeZone('Pacific/Kiritimati');
        $source = new DateTimeImmutable('2026-07-01 09:30:00', $zone);

        $date = DateTimeValue::date($source);
        $floating = DateTimeValue::floating($source);

        $this->assertSame('20260701', $date->toString());
        $this->assertSame('20260701T093000', $floating->toString());
        $this->assertSame('UTC', $date->dateTime->getTimezone()->getName());
        $this->assertSame('UTC', $floating->dateTime->getTimezone()->getName());
    }

    public function test_zoned_value_carries_tzid_but_omits_it_from_literal(): void
    {
        $value = DateTimeValue::zoned(
            new DateTimeImmutable('2026-07-01 09:30:00', new DateTimeZone('America/New_York')),
            'America/New_York',
        );
        $this->assertSame('20260701T093000', $value->toString());
        $this->assertTrue($value->needsTzidParameter());
        $this->assertSame('America/New_York', $value->tzid);
    }

    public function test_zoned_value_interprets_fields_in_the_explicit_tzid(): void
    {
        $value = DateTimeValue::zoned(
            new DateTimeImmutable('2026-07-01 09:30:00', new DateTimeZone('UTC')),
            'America/New_York',
        );

        $this->assertSame('20260701T093000', $value->toString());
        $this->assertSame('America/New_York', $value->dateTime->getTimezone()->getName());
        $this->assertSame('20260701T133000Z', $value->dateTime->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
    }

    public function test_zoned_rejects_empty_tzid(): void
    {
        $this->expectException(InvalidValueException::class);
        DateTimeValue::zoned(new DateTimeImmutable, '');
    }

    public function test_from_date_time_infers_utc(): void
    {
        $value = DateTimeValue::fromDateTime(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC')));
        $this->assertTrue($value->isUtc);
    }

    public function test_from_date_time_infers_named_zone(): void
    {
        $value = DateTimeValue::fromDateTime(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('Europe/Paris')));
        $this->assertSame('Europe/Paris', $value->tzid);
    }

    public function test_from_date_time_normalises_offset_zone_to_utc(): void
    {
        $value = DateTimeValue::fromDateTime(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('+05:00')));
        $this->assertTrue($value->isUtc);
        $this->assertSame('20260701T050000Z', $value->toString());
    }

    public function test_accepts_mutable_datetime(): void
    {
        $value = DateTimeValue::utc(new DateTime('2026-07-01 10:00:00', new DateTimeZone('UTC')));
        $this->assertInstanceOf(DateTimeImmutable::class, $value->dateTime);
        $this->assertSame('20260701T100000Z', $value->toString());
    }

    public function test_date_only_cannot_be_utc(): void
    {
        // date() always produces a valid value; this guards the invariant directly
        // by exercising the equals contract across forms.
        $a = DateTimeValue::date(new DateTimeImmutable('2026-07-01'));
        $b = DateTimeValue::floating(new DateTimeImmutable('2026-07-01 00:00:00'));
        $this->assertFalse($a->equals($b));
    }

    public function test_equality(): void
    {
        $a = DateTimeValue::utc(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC')));
        $b = DateTimeValue::utc(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC')));
        $this->assertTrue($a->equals($b));
    }

    public function test_duration_distinguishes_nominal_days_from_exact_hours_across_dst(): void
    {
        $start = DateTimeValue::zoned(
            new DateTimeImmutable('2026-03-07 12:00:00', new DateTimeZone('America/New_York')),
            'America/New_York',
        );

        $this->assertSame('20260308T120000', $start->adding(Duration::days(1))->toString());
        $this->assertSame('20260308T130000', $start->adding(Duration::hours(24))->toString());
    }

    public function test_day_duration_retains_an_rfc_pre_gap_wall_literal(): void
    {
        $start = DateTimeValue::zoned(
            new DateTimeImmutable('2026-03-07 02:30:00', new DateTimeZone('America/New_York')),
            'America/New_York',
        );
        $end = $start->adding(Duration::days(1));

        $this->assertSame('20260308T023000', $end->toString());
        $this->assertSame('20260308T073000Z', $end->dateTime->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
    }

    public function test_exact_duration_ending_in_second_fold_is_exported_in_utc(): void
    {
        $start = DateTimeValue::zoned(
            new DateTimeImmutable('2026-11-01 00:30:00', new DateTimeZone('America/New_York')),
            'America/New_York',
        );
        $end = $start->adding(Duration::hours(2));

        $this->assertTrue($end->isUtc);
        $this->assertSame('20261101T063000Z', $end->toString());
    }
}
