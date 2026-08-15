<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\Component;

use DateTimeImmutable;
use DateTimeZone;
use Erenav\ICalendar\Component\Alarm;
use Erenav\ICalendar\Component\ComponentList;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Parameter\CuType;
use Erenav\ICalendar\Parameter\ParameterBag;
use Erenav\ICalendar\Parameter\PartStat;
use Erenav\ICalendar\Parameter\Range;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Parameter\Role;
use Erenav\ICalendar\Property\Attendee;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Property\PropertyBag;
use Erenav\ICalendar\ValueType\CalAddress;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use Erenav\ICalendar\ValueType\GeoValue;
use Erenav\ICalendar\ValueType\IntegerValue;
use Erenav\ICalendar\ValueType\TextValue;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    private function utc(string $time): DateTimeValue
    {
        return DateTimeValue::utc(new DateTimeImmutable($time, new DateTimeZone('UTC')));
    }

    public function test_wire_name(): void
    {
        $this->assertSame('VEVENT', (new Event)->wireName());
    }

    public function test_scalar_getters(): void
    {
        $event = new Event(new PropertyBag(
            new Property('UID', new TextValue('1@test')),
            new Property('SUMMARY', new TextValue('Standup')),
            new Property('LOCATION', new TextValue('Room 4')),
            new Property('PRIORITY', new IntegerValue(5)),
        ));

        $this->assertSame('1@test', $event->uid());
        $this->assertSame('Standup', $event->summary());
        $this->assertSame('Room 4', $event->location());
        $this->assertSame(5, $event->priority());
        $this->assertNull($event->description());
    }

    public function test_start_and_explicit_end(): void
    {
        $event = new Event(new PropertyBag(
            new Property('DTSTART', $this->utc('2026-07-01 10:00:00')),
            new Property('DTEND', $this->utc('2026-07-01 11:30:00')),
        ));

        $this->assertSame('20260701T100000Z', $event->start()?->toString());
        $this->assertSame('20260701T113000Z', $event->end()?->toString());
    }

    public function test_end_is_computed_from_duration(): void
    {
        $event = new Event(new PropertyBag(
            new Property('DTSTART', $this->utc('2026-07-01 10:00:00')),
            new Property('DURATION', Duration::hours(1)),
        ));

        $this->assertSame('20260701T110000Z', $event->end()?->toString());
    }

    public function test_duration_end_uses_utc_when_the_second_fold_occurrence_has_no_unambiguous_local_form(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $event = Event::build()
            ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-11-01 00:30:00', $zone), 'America/New_York'))
            ->lasting(Duration::hours(2))
            ->get();

        $end = $event->end();
        $this->assertNotNull($end);
        $this->assertTrue($end->isUtc);
        $this->assertSame('20261101T063000Z', $end->toString());
    }

    public function test_end_is_null_without_end_or_duration(): void
    {
        $event = new Event(new PropertyBag(new Property('DTSTART', $this->utc('2026-07-01 10:00:00'))));
        $this->assertNull($event->end());
        $this->assertSame('20260701T100000Z', $event->effectiveEnd()?->toString());
    }

    public function test_effective_end_exposes_implicit_one_day_date_duration(): void
    {
        $event = Event::build()
            ->starts(DateTimeValue::date(new DateTimeImmutable('2026-07-01')))
            ->get();

        $this->assertNull($event->end());
        $this->assertSame('20260702', $event->effectiveEnd()?->toString());
    }

    public function test_status_enum_value(): void
    {
        $event = new Event(new PropertyBag(new Property('STATUS', EventStatus::Confirmed)));
        $this->assertSame(EventStatus::Confirmed, $event->status());
    }

    public function test_status_falls_back_from_text(): void
    {
        $event = new Event(new PropertyBag(new Property('STATUS', new TextValue('CANCELLED'))));
        $this->assertSame(EventStatus::Cancelled, $event->status());
    }

    public function test_unknown_status_text_is_null(): void
    {
        $event = new Event(new PropertyBag(new Property('STATUS', new TextValue('WHATEVER'))));
        $this->assertNull($event->status());
    }

    public function test_geo(): void
    {
        $event = new Event(new PropertyBag(new Property('GEO', GeoValue::of(37.0, -122.0))));
        $this->assertSame(37.0, $event->geo()?->latitude);
    }

    public function test_categories_flattens_multi_value_and_repeated_properties(): void
    {
        $event = new Event(new PropertyBag(
            new Property('CATEGORIES', [new TextValue('work'), new TextValue('urgent')]),
            new Property('CATEGORIES', new TextValue('personal')),
        ));

        $this->assertSame(['work', 'urgent', 'personal'], $event->categories());
    }

    public function test_attendees_are_typed_and_lossless(): void
    {
        $event = new Event(new PropertyBag(
            new Property('ATTENDEE', new TextValue('mailto:a@test')),
            new Property('ATTENDEE', new TextValue('mailto:b@test')),
        ));

        $this->assertCount(2, $event->attendees());
        $this->assertContainsOnlyInstancesOf(Attendee::class, $event->attendees());
        $this->assertSame('mailto:a@test', $event->attendees()[0]->address()->toString());
        // The underlying property stays reachable for anything not surfaced.
        $this->assertSame($event->property('ATTENDEE'), $event->attendees()[0]->property);
    }

    public function test_standard_attendee_scheduling_parameters_are_exposed(): void
    {
        $parameters = new ParameterBag(
            new RawParameter('DELEGATED-TO', 'mailto:one@test', 'urn:uuid:two'),
            new RawParameter('DELEGATED-FROM', 'mailto:source@test'),
            new RawParameter('MEMBER', 'mailto:group-a@test', 'mailto:group-b@test'),
            new RawParameter('SENT-BY', 'mailto:assistant@test'),
            new RawParameter('DIR', 'https://directory.test/alice'),
            new RawParameter('LANGUAGE', 'en-US'),
        );
        $attendee = (new Event(new PropertyBag(
            new Property('ATTENDEE', CalAddress::fromEmail('alice@test'), $parameters),
        )))->attendees()[0];

        $this->assertSame(['mailto:one@test', 'urn:uuid:two'], array_map(static fn (CalAddress $address): string => $address->toString(), $attendee->delegatedTo()));
        $this->assertSame(['mailto:source@test'], array_map(static fn (CalAddress $address): string => $address->toString(), $attendee->delegatedFrom()));
        $this->assertSame(['mailto:group-a@test', 'mailto:group-b@test'], array_map(static fn (CalAddress $address): string => $address->toString(), $attendee->members()));
        $this->assertSame('mailto:assistant@test', $attendee->sentBy()?->toString());
        $this->assertSame('https://directory.test/alice', $attendee->directory());
        $this->assertSame('https://directory.test/alice', $attendee->directoryUri()?->toString());
        $this->assertSame('en-US', $attendee->language());
    }

    public function test_singleton_scheduling_accessors_fail_closed_on_multiple_values(): void
    {
        $attendee = (new Event(new PropertyBag(new Property(
            'ATTENDEE',
            CalAddress::fromEmail('alice@test'),
            new ParameterBag(
                new RawParameter('ROLE', Role::Chair->value, Role::ReqParticipant->value),
                new RawParameter('PARTSTAT', PartStat::Accepted->value, PartStat::Declined->value),
                new RawParameter('CUTYPE', CuType::Individual->value, CuType::Group->value),
                new RawParameter('RSVP', 'TRUE', 'FALSE'),
                new RawParameter('SENT-BY', 'mailto:one@test', 'mailto:two@test'),
                new RawParameter('DIR', 'https://directory.test/one', 'https://directory.test/two'),
                new RawParameter('LANGUAGE', 'en', 'fr'),
            ),
        ))))->attendees()[0];
        $organizer = (new Event(new PropertyBag(new Property(
            'ORGANIZER',
            CalAddress::fromEmail('boss@test'),
            new ParameterBag(
                new RawParameter('SENT-BY', 'mailto:one@test', 'mailto:two@test'),
                new RawParameter('DIR', 'https://directory.test/one', 'https://directory.test/two'),
                new RawParameter('LANGUAGE', 'en', 'fr'),
            ),
        ))))->organizer();

        $this->assertNull($attendee->role());
        $this->assertNull($attendee->participationStatus());
        $this->assertNull($attendee->userType());
        $this->assertNull($attendee->rsvp());
        $this->assertNull($attendee->sentBy());
        $this->assertNull($attendee->directory());
        $this->assertNull($attendee->language());
        $this->assertNotNull($organizer);
        $this->assertNull($organizer->sentBy());
        $this->assertNull($organizer->directory());
        $this->assertNull($organizer->language());
    }

    public function test_recurrence_range_does_not_type_an_ambiguous_raw_parameter(): void
    {
        $recurrenceId = new Property(
            'RECURRENCE-ID',
            $this->utc('2026-07-01 10:00:00'),
            new ParameterBag(new RawParameter('RANGE', Range::ThisAndFuture->value, 'X-OTHER')),
        );

        $this->assertNull((new Event(new PropertyBag($recurrenceId)))->recurrenceRange());
    }

    public function test_alarms_are_read_from_children(): void
    {
        $event = new Event(
            new PropertyBag(new Property('UID', new TextValue('1'))),
            new ComponentList(new Alarm),
        );

        $this->assertCount(1, $event->alarms());
    }

    public function test_unmodelled_property_remains_accessible(): void
    {
        $event = new Event(new PropertyBag(new Property('X-CUSTOM', new TextValue('hi'))));
        $this->assertSame('hi', $event->property('X-CUSTOM')?->value()->toString());
    }
}
