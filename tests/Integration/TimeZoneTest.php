<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Integration;

use DateTimeImmutable;
use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Component\Observance;
use Erenav\ICalendar\Component\TimeZone;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\Parser\Parser;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Property\PropertyBag;
use Erenav\ICalendar\Recurrence\UnsupportedRecurrenceException;
use Erenav\ICalendar\Serializer\IcsSerializer;
use Erenav\ICalendar\TimeZone\TimeZoneGenerator;
use Erenav\ICalendar\TimeZone\TimeZoneResolver;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use Erenav\ICalendar\ValueType\Period;
use PHPUnit\Framework\TestCase;

final class TimeZoneTest extends TestCase
{
    private function observance(TimeZone $tz, bool $daylight): Observance
    {
        foreach (array_reverse($tz->observances()) as $observance) {
            if ($observance->isDaylight() === $daylight) {
                return $observance;
            }
        }

        $this->fail(($daylight ? 'DAYLIGHT' : 'STANDARD').' observance not found');
    }

    public function test_generates_dst_zone_with_derived_rules(): void
    {
        $tz = (new TimeZoneGenerator)->forIana('America/New_York');

        $this->assertSame('America/New_York', $tz->tzid());
        $this->assertGreaterThanOrEqual(2, count($tz->observances()));

        $daylight = $this->observance($tz, true);
        $this->assertSame('-0500', $daylight->offsetFrom()?->toString());
        $this->assertSame('-0400', $daylight->offsetTo()?->toString());
        $this->assertSame('FREQ=YEARLY;BYDAY=2SU;BYMONTH=3', $daylight->recurrenceRule()?->toString());

        $standard = $this->observance($tz, false);
        $this->assertSame('-0500', $standard->offsetTo()?->toString());
        $this->assertSame('FREQ=YEARLY;BYDAY=1SU;BYMONTH=11', $standard->recurrenceRule()?->toString());
    }

    public function test_generated_zone_bounds_superseded_rules_and_matches_iana_across_eras(): void
    {
        $zone = (new TimeZoneGenerator)->forIana('America/New_York');
        $ics = (new IcsSerializer)->serialize($zone);
        $this->assertStringContainsString('UNTIL=20061029T060000Z', $ics);

        $resolver = new TimeZoneResolver($zone);
        $native = new \DateTimeZone('America/New_York');
        foreach (['1974-01-10 09:00:00', '2006-03-10 09:00:00', '2007-03-12 09:00:00', '2026-07-03 13:00:00'] as $wall) {
            $fields = new DateTimeImmutable($wall, new \DateTimeZone('UTC'));
            $resolved = $resolver->resolveWall('America/New_York', $fields)->dateTime;
            $expected = new DateTimeImmutable($wall, $native);
            $this->assertSame($expected->getTimestamp(), $resolved->getTimestamp(), $wall);
        }
    }

    public function test_generator_does_not_project_only_half_of_a_stable_transition_cycle(): void
    {
        $zone = (new TimeZoneGenerator)->forIana('America/Godthab');
        $resolver = new TimeZoneResolver($zone);
        $wall = new DateTimeImmutable('2099-07-01 12:00:00', new \DateTimeZone('UTC'));
        $expected = new DateTimeImmutable('2099-07-01 12:00:00', new \DateTimeZone('America/Godthab'));

        $this->assertSame(
            $expected->getTimestamp(),
            $resolver->resolveWall('America/Godthab', $wall)->dateTime->getTimestamp(),
        );
    }

    public function test_generates_fixed_zone_without_dst(): void
    {
        $tz = (new TimeZoneGenerator)->forIana('Asia/Kolkata');

        $this->assertCount(1, $tz->observances());
        $standard = $tz->observances()[0];
        $this->assertFalse($standard->isDaylight());
        $this->assertSame('+0530', $standard->offsetTo()?->toString());
        $this->assertNull($standard->recurrenceRule());
    }

    public function test_non_iana_id_is_skipped_or_throws(): void
    {
        $this->assertNull((new TimeZoneGenerator)->tryForIana('Custom/Made-Up'));

        $this->expectException(InvalidValueException::class);
        (new TimeZoneGenerator)->forIana('Custom/Made-Up');
    }

    public function test_generator_accepts_iana_backward_compatibility_links(): void
    {
        $zone = (new TimeZoneGenerator)->forIana('US/Eastern');
        $fixed = (new TimeZoneGenerator)->forIana('GMT');

        $this->assertSame('US/Eastern', $zone->tzid());
        $this->assertNotEmpty($zone->observances());
        $this->assertSame('GMT', $fixed->tzid());
        $this->assertSame('+0000', $fixed->observances()[0]->offsetTo()?->toString());
    }

    public function test_serializes_to_valid_vtimezone(): void
    {
        $tz = (new TimeZoneGenerator)->forIana('America/New_York');
        $ics = (new IcsSerializer)->serialize($tz);

        $this->assertStringContainsString('BEGIN:VTIMEZONE', $ics);
        $this->assertStringContainsString('TZID:America/New_York', $ics);
        $this->assertStringContainsString('BEGIN:DAYLIGHT', $ics);
        $this->assertStringContainsString('TZOFFSETTO:-0400', $ics);
        $this->assertStringContainsString('RRULE:FREQ=YEARLY;BYDAY=2SU;BYMONTH=3', $ics);
        $this->assertStringContainsString('END:VTIMEZONE', $ics);
    }

    public function test_calendar_auto_includes_used_time_zones(): void
    {
        $calendar = Calendar::build()
            ->prodId('-//Test//EN')
            ->add(
                Event::build()
                    ->uid('1@test')
                    ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-07-01 09:30'), 'America/New_York')),
            )
            ->get();

        $this->assertCount(0, $calendar->timeZones());

        $withZones = $calendar->withTimeZones();
        $this->assertCount(1, $withZones->timeZones());
        $this->assertSame('America/New_York', $withZones->timeZones()[0]->tzid());
        // VTIMEZONE is prepended, before the event.
        $this->assertInstanceOf(TimeZone::class, $withZones->components()[0]);
    }

    public function test_calendar_auto_includes_timezone_used_by_period_rdate(): void
    {
        $period = Period::lasting(
            DateTimeValue::zoned(new DateTimeImmutable('2026-07-02 09:30'), 'America/New_York'),
            Duration::hours(1),
        );
        $calendar = Calendar::build()
            ->prodId('-//Test//EN')
            ->add(new Event(new PropertyBag(new Property('RDATE', $period))))
            ->get()
            ->withTimeZones();

        $this->assertCount(1, $calendar->timeZones());
        $this->assertSame('America/New_York', $calendar->timeZones()[0]->tzid());
    }

    public function test_with_time_zones_does_not_duplicate_existing(): void
    {
        $calendar = Calendar::build()
            ->prodId('-//Test//EN')
            ->add(
                Event::build()
                    ->uid('1@test')
                    ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-07-01 09:30'), 'America/New_York')),
            )
            ->get()
            ->withTimeZones();

        $this->assertCount(1, $calendar->withTimeZones()->timeZones());
    }

    public function test_parsed_vtimezone_is_typed_and_round_trips(): void
    {
        $ics = (new IcsSerializer)->serialize(
            Calendar::build()
                ->prodId('-//Test//EN')
                ->add(Event::build()->uid('1@test')->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-07-01 09:30'), 'America/New_York')))
                ->get()
                ->withTimeZones(),
        );

        $calendar = Parser::lenient()->parseCalendar($ics);
        $this->assertCount(1, $calendar->timeZones());

        $tz = $calendar->timeZones()[0];
        $this->assertInstanceOf(TimeZone::class, $tz);
        $this->assertSame('America/New_York', $tz->tzid());
        $this->assertSame('-0400', $this->observance($tz, true)->offsetTo()?->toString());

        // Stable fixed point through another round trip.
        $second = (new IcsSerializer)->serialize(Parser::lenient()->parseCalendar($ics));
        $this->assertSame($ics, $second);
    }

    public function test_embedded_outlook_timezone_resolves_effective_start_and_end(): void
    {
        $calendar = Parser::lenient()->parseCalendar((string) file_get_contents(__DIR__.'/../Fixtures/outlook.ics'));
        $occurrences = $calendar->occurrencesBetween(
            new DateTimeImmutable('2026-07-03T17:00:00Z'),
            new DateTimeImmutable('2026-07-03T17:00:00Z'),
        );

        $this->assertCount(1, $occurrences);
        $this->assertSame('2026-07-03T17:00:00+00:00', $occurrences[0]->start->format(DATE_ATOM));
        $this->assertSame('2026-07-03T18:00:00+00:00', $occurrences[0]->end?->format(DATE_ATOM));
    }

    public function test_embedded_definition_is_authoritative_even_when_tzid_is_an_iana_name(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0200\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\nUID:embedded-authority\r\nDTSTART;TZID=America/New_York:20260703T090000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $occurrences = $calendar->occurrencesBetween(
            new DateTimeImmutable('2026-07-03T07:00:00Z'),
            new DateTimeImmutable('2026-07-03T07:00:00Z'),
        );

        $this->assertCount(1, $occurrences);
        $this->assertSame('2026-07-03T07:00:00+00:00', $occurrences[0]->start->format(DATE_ATOM));
    }

    public function test_embedded_iana_definition_drives_override_matching_and_exdates(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->fixedEmbeddedIanaCalendar(<<<'ICS'
BEGIN:VEVENT
UID:embedded-single
DTSTART;TZID=America/New_York:20260701T090000
DURATION:PT1H
RRULE:FREQ=DAILY;COUNT=4
EXDATE;TZID=America/New_York:20260703T090000
END:VEVENT
BEGIN:VEVENT
UID:embedded-single
RECURRENCE-ID;TZID=America/New_York:20260702T090000
DTSTART;TZID=America/New_York:20260702T100000
SUMMARY:Moved once
END:VEVENT
ICS));

        $occurrences = $calendar->occurrencesBetween(
            new DateTimeImmutable('2026-07-01T00:00:00Z'),
            new DateTimeImmutable('2026-07-05T00:00:00Z'),
        );

        $this->assertSame(
            ['2026-07-01T07:00:00+00:00', '2026-07-02T08:00:00+00:00', '2026-07-04T07:00:00+00:00'],
            array_map(static fn ($occurrence): string => $occurrence->start->format(DATE_ATOM), $occurrences),
        );
        $this->assertSame([false, true, false], array_map(
            static fn ($occurrence): bool => $occurrence->isOverride,
            $occurrences,
        ));
        $this->assertSame('2026-07-02T07:00:00+00:00', $occurrences[1]->recurrenceId->format(DATE_ATOM));
        $this->assertSame('Moved once', $occurrences[1]->event->summary());
    }

    public function test_embedded_iana_definition_drives_range_materialization(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->fixedEmbeddedIanaCalendar(<<<'ICS'
BEGIN:VEVENT
UID:embedded-range
DTSTART;TZID=America/New_York:20260701T090000
DURATION:PT1H
RRULE:FREQ=DAILY;COUNT=4
END:VEVENT
BEGIN:VEVENT
UID:embedded-range
RECURRENCE-ID;TZID=America/New_York;RANGE=THISANDFUTURE:20260702T090000
DTSTART;TZID=America/New_York:20260702T110000
DURATION:PT2H
SUMMARY:Shifted range
END:VEVENT
ICS));

        $occurrences = $calendar->occurrencesBetween(
            new DateTimeImmutable('2026-07-01T00:00:00Z'),
            new DateTimeImmutable('2026-07-05T00:00:00Z'),
        );

        $this->assertSame(
            ['2026-07-01T07:00:00+00:00', '2026-07-02T09:00:00+00:00', '2026-07-03T09:00:00+00:00', '2026-07-04T09:00:00+00:00'],
            array_map(static fn ($occurrence): string => $occurrence->start->format(DATE_ATOM), $occurrences),
        );
        $this->assertSame(
            ['2026-07-01T07:00:00+00:00', '2026-07-02T07:00:00+00:00', '2026-07-03T07:00:00+00:00', '2026-07-04T07:00:00+00:00'],
            array_map(static fn ($occurrence): string => $occurrence->recurrenceId->format(DATE_ATOM), $occurrences),
        );
        $this->assertSame('20260704T110000', $occurrences[3]->event->start()?->toString());
        $this->assertSame('20260704T130000', $occurrences[3]->event->effectiveEnd()?->toString());
        $this->assertSame('Shifted range', $occurrences[3]->event->summary());
    }

    public function test_embedded_iana_definition_drives_period_lookup_and_materialization(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->fixedEmbeddedIanaCalendar(<<<'ICS'
BEGIN:VEVENT
UID:embedded-period
DTSTART;TZID=America/New_York:20260701T090000
RDATE;TZID=America/New_York;VALUE=PERIOD:20260705T090000/20260705T113000
END:VEVENT
ICS));

        $occurrences = $calendar->occurrencesBetween(
            new DateTimeImmutable('2026-07-05T07:00:00Z'),
            new DateTimeImmutable('2026-07-05T07:00:00Z'),
        );

        $this->assertCount(1, $occurrences);
        $this->assertSame('2026-07-05T07:00:00+00:00', $occurrences[0]->start->format(DATE_ATOM));
        $this->assertSame('2026-07-05T09:30:00+00:00', $occurrences[0]->end?->format(DATE_ATOM));
        $this->assertSame('20260705T090000', $occurrences[0]->event->start()?->toString());
        $this->assertSame('20260705T113000', $occurrences[0]->event->effectiveEnd()?->toString());
    }

    public function test_explicit_period_end_must_follow_start_after_embedded_resolution(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VTIMEZONE\r\nTZID:Forward/Gap\r\nBEGIN:DAYLIGHT\r\nDTSTART:20260701T020000\r\nTZOFFSETFROM:+0000\r\nTZOFFSETTO:+0400\r\nEND:DAYLIGHT\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\nUID:backward-period\r\nDTSTART;TZID=Forward/Gap:20260630T120000\r\nRDATE;TZID=Forward/Gap;VALUE=PERIOD:20260701T053000/20260701T060000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $this->expectException(UnsupportedRecurrenceException::class);
        $this->expectExceptionMessage('PERIOD end must resolve to an instant after its start');
        $calendar->occurrencesBetween(
            new DateTimeImmutable('2026-06-30T00:00:00Z'),
            new DateTimeImmutable('2026-07-02T00:00:00Z'),
        );
    }

    public function test_embedded_custom_timezone_recurrence_and_range_keep_wall_time_across_dst(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->customEasternCalendar(<<<'ICS'
BEGIN:VEVENT
UID:custom-range
DTSTART;TZID=Eastern Standard Time:20260301T090000
DURATION:PT1H
RRULE:FREQ=WEEKLY;COUNT=3
END:VEVENT
BEGIN:VEVENT
UID:custom-range
RECURRENCE-ID;TZID=Eastern Standard Time;RANGE=THISANDFUTURE:20260308T090000
DTSTART;TZID=Eastern Standard Time:20260308T100000
DURATION:PT2H
END:VEVENT
ICS));

        $occurrences = $calendar->occurrencesBetween(
            new DateTimeImmutable('2026-03-01T00:00:00Z'),
            new DateTimeImmutable('2026-04-01T00:00:00Z'),
        );

        $this->assertSame(
            ['2026-03-01T14:00:00+00:00', '2026-03-08T14:00:00+00:00', '2026-03-15T14:00:00+00:00'],
            array_map(static fn ($occurrence): string => $occurrence->start->format(DATE_ATOM), $occurrences),
        );
        $this->assertSame('20260315T100000', $occurrences[2]->event->start()?->toString());
        $this->assertSame($occurrences[2]->start->getTimestamp(), $occurrences[2]->event->start()?->dateTime->getTimestamp());
        $this->assertSame('20260315T120000', $occurrences[2]->event->effectiveEnd()?->toString());
        $this->assertSame('2026-03-15T16:00:00+00:00', $occurrences[2]->end?->format(DATE_ATOM));
    }

    public function test_period_rdate_day_duration_uses_embedded_timezone_dst_rules(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->customEasternCalendar(<<<'ICS'
BEGIN:VEVENT
UID:custom-period
DTSTART;TZID=Eastern Standard Time:20260301T120000
RDATE;TZID=Eastern Standard Time;VALUE=PERIOD:20260307T120000/P1D
END:VEVENT
ICS));

        $occurrences = $calendar->occurrencesBetween(
            new DateTimeImmutable('2026-03-07T00:00:00Z'),
            new DateTimeImmutable('2026-03-07T23:59:59Z'),
        );

        $this->assertCount(1, $occurrences);
        $this->assertSame('2026-03-07T17:00:00+00:00', $occurrences[0]->start->format(DATE_ATOM));
        $this->assertSame('2026-03-08T16:00:00+00:00', $occurrences[0]->end?->format(DATE_ATOM));
        $this->assertSame('20260308T120000', $occurrences[0]->event->effectiveEnd()?->toString());
    }

    public function test_event_end_can_apply_embedded_timezone_durations_directly(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->customEasternCalendar(<<<'ICS'
BEGIN:VEVENT
UID:custom-direct-end
DTSTART;TZID=Eastern Standard Time:20260307T120000
DURATION:P1D
END:VEVENT
BEGIN:VEVENT
UID:custom-fold-end
DTSTART;TZID=Eastern Standard Time:20261101T003000
DURATION:PT2H
END:VEVENT
ICS));
        $resolver = TimeZoneResolver::fromCalendar($calendar);

        $dayEnd = $calendar->events()[0]->end($resolver);
        $foldEnd = $calendar->events()[1]->end($resolver);

        $this->assertSame('20260308T120000', $dayEnd?->toString());
        $this->assertSame('2026-03-08T16:00:00+00:00', $dayEnd?->dateTime->format(DATE_ATOM));
        $this->assertSame('20261101T063000Z', $foldEnd?->toString());
    }

    public function test_embedded_timezone_rule_generated_gap_fails_closed(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->customEasternCalendar(<<<'ICS'
BEGIN:VEVENT
UID:custom-gap
DTSTART;TZID=Eastern Standard Time:20260301T023000
RRULE:FREQ=WEEKLY;COUNT=3
END:VEVENT
ICS));

        $this->expectException(UnsupportedRecurrenceException::class);
        $calendar->occurrencesBetween(
            new DateTimeImmutable('2026-03-01T00:00:00Z'),
            new DateTimeImmutable('2026-04-01T00:00:00Z'),
        );
    }

    public function test_embedded_timezone_explicit_gap_and_fold_use_rfc_offsets(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->customEasternCalendar(<<<'ICS'
BEGIN:VEVENT
UID:resolver-context
DTSTART:20260101T000000Z
END:VEVENT
ICS));
        $resolver = TimeZoneResolver::fromCalendar($calendar);
        $utc = new \DateTimeZone('UTC');

        $gap = $resolver->resolveWall('Eastern Standard Time', new DateTimeImmutable('2026-03-08 02:30:00', $utc));
        $fold = $resolver->resolveWall('Eastern Standard Time', new DateTimeImmutable('2026-11-01 01:30:00', $utc));

        $this->assertSame('2026-03-08T07:30:00+00:00', $gap->dateTime->format(DATE_ATOM));
        $this->assertSame('2026-11-01T05:30:00+00:00', $fold->dateTime->format(DATE_ATOM));
    }

    public function test_embedded_timezone_cannot_export_a_second_fold_instant_as_the_first(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->customEasternCalendar(<<<'ICS'
BEGIN:VEVENT
UID:resolver-context
DTSTART:20260101T000000Z
END:VEVENT
ICS));
        $resolver = TimeZoneResolver::fromCalendar($calendar);

        $this->expectException(UnsupportedRecurrenceException::class);
        $this->expectExceptionMessage('second occurrence');
        $resolver->valueAtInstant(
            new DateTimeImmutable('2026-11-01T06:30:00Z'),
            'Eastern Standard Time',
        );
    }

    public function test_malformed_embedded_timezone_fails_only_when_its_tzid_is_used(): void
    {
        $prefix = "BEGIN:VCALENDAR\r\nBEGIN:VTIMEZONE\r\nTZID:Broken/Zone\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:+0000\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";
        $unused = Parser::lenient()->parseCalendar($prefix."BEGIN:VEVENT\r\nUID:utc\r\nDTSTART:20260701T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->assertCount(1, $unused->occurrencesBetween(
            new DateTimeImmutable('2026-07-01T10:00:00Z'),
            new DateTimeImmutable('2026-07-01T10:00:00Z'),
        ));

        $used = Parser::lenient()->parseCalendar($prefix."BEGIN:VEVENT\r\nUID:custom\r\nDTSTART;TZID=Broken/Zone:20260701T100000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->expectException(UnsupportedRecurrenceException::class);
        $used->occurrencesBetween(
            new DateTimeImmutable('2026-07-01T00:00:00Z'),
            new DateTimeImmutable('2026-07-02T00:00:00Z'),
        );
    }

    public function test_malformed_embedded_iana_claim_cannot_fall_back_to_php_timezone_data(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nTZID:America/New_York\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0200\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\nUID:invalid-authority\r\nDTSTART;TZID=America/New_York:20260703T090000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $resolver = TimeZoneResolver::fromCalendar($calendar);

        $this->assertTrue($resolver->usesEmbedded('America/New_York'));
        $this->assertFalse($resolver->canResolve('America/New_York'));
        $this->expectException(UnsupportedRecurrenceException::class);
        $calendar->occurrencesBetween(
            new DateTimeImmutable('2026-07-03T00:00:00Z'),
            new DateTimeImmutable('2026-07-04T00:00:00Z'),
        );
    }

    public function test_can_resolve_returns_false_for_an_unsynchronized_observance_rule(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VTIMEZONE\r\nTZID:Unsynchronized/Zone\r\nBEGIN:STANDARD\r\nDTSTART:19700102T000000\r\nTZOFFSETFROM:+0000\r\nTZOFFSETTO:+0000\r\nRRULE:FREQ=YEARLY;BYMONTH=1;BYMONTHDAY=1\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n");
        $resolver = TimeZoneResolver::fromCalendar($calendar);

        $this->assertTrue($resolver->usesEmbedded('Unsynchronized/Zone'));
        $this->assertFalse($resolver->canResolve('Unsynchronized/Zone'));

        $this->expectException(UnsupportedRecurrenceException::class);
        $resolver->resolveWall(
            'Unsynchronized/Zone',
            new DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')),
        );
    }

    private function customEasternCalendar(string $events): string
    {
        $events = str_replace("\n", "\r\n", trim($events));

        return "BEGIN:VCALENDAR\r\nBEGIN:VTIMEZONE\r\nTZID:Eastern Standard Time\r\nBEGIN:STANDARD\r\nDTSTART:16011104T020000\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nRRULE:FREQ=YEARLY;BYDAY=1SU;BYMONTH=11\r\nEND:STANDARD\r\nBEGIN:DAYLIGHT\r\nDTSTART:16010311T020000\r\nTZOFFSETFROM:-0500\r\nTZOFFSETTO:-0400\r\nRRULE:FREQ=YEARLY;BYDAY=2SU;BYMONTH=3\r\nEND:DAYLIGHT\r\nEND:VTIMEZONE\r\n{$events}\r\nEND:VCALENDAR\r\n";
    }

    private function fixedEmbeddedIanaCalendar(string $events): string
    {
        $events = str_replace("\n", "\r\n", trim($events));

        return "BEGIN:VCALENDAR\r\nBEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0200\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n{$events}\r\nEND:VCALENDAR\r\n";
    }
}
