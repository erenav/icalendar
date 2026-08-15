<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\Parser;

use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Component\GenericComponent;
use Erenav\ICalendar\Exception\ParseException;
use Erenav\ICalendar\Parameter\CuType;
use Erenav\ICalendar\Parameter\PartStat;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Parameter\Role;
use Erenav\ICalendar\Parser\Parser;
use Erenav\ICalendar\Property\AlarmAction;
use Erenav\ICalendar\Property\Classification;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\Property\Transparency;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\Scheduling\Method;
use Erenav\ICalendar\ValueType\CalAddress;
use Erenav\ICalendar\ValueType\RawValue;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private function ics(string ...$lines): string
    {
        return implode("\r\n", $lines);
    }

    public function test_parses_a_calendar_with_an_event(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->ics(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//EN',
            'BEGIN:VEVENT',
            'UID:1@test',
            'SUMMARY:Hi',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ));

        $this->assertInstanceOf(Calendar::class, $calendar);
        $this->assertSame('2.0', $calendar->version());
        $this->assertCount(1, $calendar->events());

        $event = $calendar->events()[0];
        $this->assertSame('1@test', $event->uid());
        $this->assertSame('Hi', $event->summary());
        $this->assertSame(EventStatus::Confirmed, $event->status());
    }

    public function test_explicit_float_value_type_hydrates_geo(): void
    {
        $event = Parser::strict()->parseCalendar($this->ics(
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'GEO;VALUE=FLOAT:+37.386013;-122.082932',
            'END:VEVENT',
            'END:VCALENDAR',
        ))->events()[0];

        $this->assertSame('+37.386013;-122.082932', $event->geo()?->toString());
    }

    public function test_parses_the_three_datetime_forms(): void
    {
        $event = $this->firstEvent(
            'DTSTAMP:20260620T120000Z',
            'DTSTART;TZID=America/New_York:20260701T093000',
            'DTEND;VALUE=DATE:20260702',
        );

        $this->assertTrue($event->timestamp()?->isUtc);
        $this->assertSame('America/New_York', $event->start()?->tzid);
        $this->assertSame('20260701T093000', $event->start()?->toString());
        $this->assertTrue($event->end()?->isDateOnly);
        $this->assertSame('20260702', $event->end()?->toString());
    }

    public function test_parses_attendee_parameters_into_enums(): void
    {
        $event = $this->firstEvent(
            'ATTENDEE;CN="Doe, Alice";ROLE=CHAIR;PARTSTAT=ACCEPTED;RSVP=TRUE:mailto:alice@test',
        );

        $attendee = $event->attendees()[0];
        $this->assertSame('mailto:alice@test', $attendee->address()->toString());
        $this->assertSame(Role::Chair, $attendee->role());
        $this->assertSame(PartStat::Accepted, $attendee->participationStatus());
        $this->assertSame('Doe, Alice', $attendee->commonName());
        $this->assertTrue($attendee->rsvp());
    }

    public function test_malformed_scheduling_parameters_remain_lossless_but_typed_access_is_safe(): void
    {
        $event = $this->firstEvent(
            'ATTENDEE;RSVP=MAYBE;DELEGATED-TO="mailto:good@test",missing-scheme;SENT-BY=bad;DIR=relative:mailto:alice@test',
            'ORGANIZER;SENT-BY=also-bad;DIR=relative:mailto:boss@test',
        );
        $attendee = $event->attendees()[0];
        $organizer = $event->organizer();

        $this->assertNull($attendee->rsvp());
        $this->assertSame(['mailto:good@test'], array_map(
            static fn (CalAddress $address): string => $address->toString(),
            $attendee->delegatedTo(),
        ));
        $this->assertNull($attendee->sentBy());
        $this->assertSame('relative', $attendee->directory());
        $this->assertNull($attendee->directoryUri());
        $this->assertNotNull($organizer);
        $this->assertSame('also-bad', $organizer->sentBy());
        $this->assertNull($organizer->sentByAddress());
        $this->assertSame('relative', $organizer->directory());
        $this->assertNull($organizer->directoryUri());

        $this->assertSame('MAYBE', $attendee->property->parameter('RSVP')?->value());
        $this->assertSame('missing-scheme', $attendee->property->parameter('DELEGATED-TO')?->values[1] ?? null);
    }

    public function test_enumerated_tokens_are_case_insensitive(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->ics(
            'BEGIN:VCALENDAR',
            'METHOD:reply',
            'BEGIN:VEVENT',
            'STATUS:cancelled',
            'CLASS:private',
            'TRANSP:transparent',
            'ATTENDEE;ROLE=chair;PARTSTAT=accepted;CUTYPE=room:mailto:alice@test',
            'BEGIN:VALARM',
            'ACTION:display',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ));
        $event = $calendar->events()[0];

        $this->assertSame(Method::Reply, $calendar->schedulingMethod());
        $this->assertSame(EventStatus::Cancelled, $event->status());
        $this->assertSame(Classification::Private, $event->classification());
        $this->assertSame(Transparency::Transparent, $event->transparency());
        $this->assertSame(Role::Chair, $event->attendees()[0]->role());
        $this->assertSame(PartStat::Accepted, $event->attendees()[0]->participationStatus());
        $this->assertSame(CuType::Room, $event->attendees()[0]->userType());
        $this->assertSame(AlarmAction::Display, $event->alarms()[0]->action());
    }

    public function test_zoned_dst_gap_and_fold_follow_rfc_5545_resolution(): void
    {
        $event = $this->firstEvent(
            'DTSTART;TZID=America/New_York:20260308T023000',
            'DTEND;TZID=America/New_York:20261101T013000',
        );

        $gap = $event->start();
        $fold = $event->end();
        $this->assertSame('20260308T023000', $gap?->toString());
        $this->assertSame('America/New_York', $gap?->dateTime->getTimezone()->getName());
        $this->assertSame('20260308T073000Z', $gap?->dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
        $this->assertSame('20261101T013000', $fold?->toString());
        $this->assertSame('20261101T053000Z', $fold?->dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
    }

    public function test_invalid_text_escape_is_preserved_raw_leniently_and_rejected_strictly(): void
    {
        $ics = $this->ics('BEGIN:VEVENT', 'SUMMARY:keep\\qverbatim', 'END:VEVENT');
        $lenient = Parser::lenient()->parse($ics);
        $this->assertInstanceOf(RawValue::class, $lenient->property('SUMMARY')?->value());
        $this->assertSame('keep\\qverbatim', $lenient->property('SUMMARY')?->value()->toString());

        $this->expectException(ParseException::class);
        Parser::strict()->parse($ics);
    }

    public function test_unescapes_text_and_splits_categories(): void
    {
        $event = $this->firstEvent(
            'SUMMARY:Sprint Planning\\, Q3',
            'DESCRIPTION:Line one\\nLine two',
            'CATEGORIES:work,planning',
        );

        $this->assertSame('Sprint Planning, Q3', $event->summary());
        $this->assertSame("Line one\nLine two", $event->description());
        $this->assertSame(['work', 'planning'], $event->categories());
    }

    public function test_unknown_property_is_preserved_as_raw(): void
    {
        $event = $this->firstEvent('X-CUSTOM-FLAG;X-PARAM=1:keep;me,verbatim');

        $property = $event->property('X-CUSTOM-FLAG');
        $this->assertInstanceOf(RawValue::class, $property?->value());
        $this->assertSame('keep;me,verbatim', $property->value()->toString());
        $this->assertSame('1', $property->parameter('X-PARAM')?->value());
    }

    public function test_rrule_is_parsed_into_a_typed_recurrence(): void
    {
        $event = $this->firstEvent('RRULE:FREQ=WEEKLY;BYDAY=MO,WE');

        $value = $event->property('RRULE')?->value();
        $this->assertInstanceOf(Recurrence::class, $value);
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO,WE', $value->toString());
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO,WE', $event->recurrenceRule()?->toString());
    }

    public function test_unknown_components_become_generic(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->ics(
            'BEGIN:VCALENDAR',
            'BEGIN:VTODO',
            'UID:todo-1',
            'SUMMARY:Buy milk',
            'END:VTODO',
            'END:VCALENDAR',
        ));

        $todo = $calendar->components()[0];
        $this->assertInstanceOf(GenericComponent::class, $todo);
        $this->assertSame('VTODO', $todo->wireName());
        $this->assertSame('Buy milk', $todo->property('SUMMARY')?->value()->toString());
    }

    public function test_strict_mode_rejects_property_outside_component(): void
    {
        $this->expectException(ParseException::class);
        Parser::strict()->parse("SUMMARY:orphan\r\n");
    }

    public function test_strict_rejects_unknown_rrule_parts_while_lenient_preserves_them(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nRRULE:FREQ=DAILY;X-VENDOR=meaningful\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $lenient = Parser::lenient()->parseCalendar($ics)->events()[0]->recurrenceRule();
        $this->assertSame('FREQ=DAILY;X-VENDOR=meaningful', $lenient?->toString());

        $this->expectException(ParseException::class);
        Parser::strict()->parseCalendar($ics);
    }

    public function test_lenient_preserves_duplicate_known_rrule_parts_as_raw_data(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nRRULE:FREQ=DAILY;COUNT=2;COUNT=3\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $event = Parser::lenient()->parseCalendar($ics)->events()[0];

        $this->assertNull($event->recurrenceRule());
        $this->assertSame('FREQ=DAILY;COUNT=2;COUNT=3', $event->property('RRULE')?->value()->toString());

        $this->expectException(ParseException::class);
        Parser::strict()->parseCalendar($ics);
    }

    public function test_lenient_preserves_oversized_integers_without_php_overflow(): void
    {
        $tooLarge = '999999999999999999999999999999999999';
        $ics = $this->ics(
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'SEQUENCE:'.$tooLarge,
            'DURATION:P'.$tooLarge.'D',
            'RRULE:FREQ=DAILY;COUNT='.$tooLarge,
            'END:VEVENT',
            'END:VCALENDAR',
        );
        $event = Parser::lenient()->parseCalendar($ics)->events()[0];

        $this->assertNull($event->sequence());
        $this->assertSame($tooLarge, $event->property('SEQUENCE')?->value()->toString());
        $this->assertNull($event->duration());
        $this->assertSame('P'.$tooLarge.'D', $event->property('DURATION')?->value()->toString());
        $this->assertNull($event->recurrenceRule());
        $this->assertSame('FREQ=DAILY;COUNT='.$tooLarge, $event->property('RRULE')?->value()->toString());

        $this->expectException(ParseException::class);
        Parser::strict()->parseCalendar($ics);
    }

    public function test_strict_mode_rejects_malformed_date(): void
    {
        $this->expectException(ParseException::class);
        Parser::strict()->parse($this->ics(
            'BEGIN:VEVENT',
            'DTSTART:not-a-date',
            'END:VEVENT',
        ));
    }

    public function test_impossible_dates_are_raw_in_lenient_mode_and_rejected_in_strict_mode(): void
    {
        $ics = $this->ics('BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'DTSTART;VALUE=DATE:20260231', 'END:VEVENT', 'END:VCALENDAR');
        $lenient = Parser::lenient()->parseCalendar($ics)->events()[0];
        $this->assertSame('20260231', $lenient->property('DTSTART')?->value()->toString());
        $this->assertNull($lenient->start());

        $this->expectException(ParseException::class);
        Parser::strict()->parseCalendar($ics);
    }

    public function test_strict_rejects_tzid_on_utc_date_time(): void
    {
        $ics = $this->ics('BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'DTSTART;TZID=Europe/Paris:20260701T100000Z', 'END:VEVENT', 'END:VCALENDAR');

        $event = Parser::lenient()->parseCalendar($ics)->events()[0];
        $this->assertNull($event->start());
        $this->assertInstanceOf(RawValue::class, $event->property('DTSTART')?->value());

        $this->expectException(ParseException::class);
        Parser::strict()->parseCalendar($ics);
    }

    public function test_strict_rejects_tzid_on_date_but_lenient_preserves_it_raw(): void
    {
        $ics = $this->ics('BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'DTSTART;VALUE=DATE;TZID=Europe/Paris:20260701', 'END:VEVENT', 'END:VCALENDAR');

        $event = (new Parser)->parse($ics)->events()[0];
        self::assertNull($event->start());
        self::assertInstanceOf(RawValue::class, $event->property('DTSTART')?->value());
        self::assertSame('Europe/Paris', $event->property('DTSTART')?->parameter('TZID')?->value());

        $this->expectException(ParseException::class);
        (new Parser(strict: true))->parse($ics);
    }

    public function test_controlling_parameters_cannot_be_comma_multivalued(): void
    {
        $cases = [
            ['DTSTART;TZID=Europe/Paris,America/New_York:20260701T100000', 'TZID', ['Europe/Paris', 'America/New_York']],
            ['DTSTART;VALUE=DATE,DATE-TIME:20260701', 'VALUE', ['DATE', 'DATE-TIME']],
            ['ATTACH;ENCODING=BASE64,8BIT;VALUE=BINARY:aGVsbG8=', 'ENCODING', ['BASE64', '8BIT']],
        ];

        foreach ($cases as [$line, $parameterName, $expectedValues]) {
            $ics = $this->ics('BEGIN:VCALENDAR', 'BEGIN:VEVENT', $line, 'END:VEVENT', 'END:VCALENDAR');
            $propertyName = substr($line, 0, (int) strpos($line, ';'));
            $property = Parser::lenient()->parseCalendar($ics)->events()[0]->property($propertyName);

            $this->assertInstanceOf(RawValue::class, $property?->value());
            $this->assertInstanceOf(RawParameter::class, $property?->parameter($parameterName));
            $this->assertSame($expectedValues, $property?->parameter($parameterName)?->values);

            try {
                Parser::strict()->parseCalendar($ics);
                $this->fail(sprintf('Expected multi-valued %s to be rejected.', $parameterName));
            } catch (ParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_tzid_on_a_utc_period_is_preserved_raw_leniently_and_rejected_strictly(): void
    {
        $ics = $this->ics(
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'RDATE;TZID=Europe/Paris;VALUE=PERIOD:20260701T100000Z/PT1H',
            'END:VEVENT',
            'END:VCALENDAR',
        );
        $property = Parser::lenient()->parseCalendar($ics)->events()[0]->property('RDATE');

        $this->assertInstanceOf(RawValue::class, $property?->value());
        $this->assertSame('20260701T100000Z/PT1H', $property?->value()->toString());

        $this->expectException(ParseException::class);
        Parser::strict()->parseCalendar($ics);
    }

    public function test_tzid_on_a_non_temporal_typed_value_is_preserved_raw_leniently_and_rejected_strictly(): void
    {
        $ics = $this->ics(
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'SUMMARY;TZID=Europe/Paris:Provider text',
            'END:VEVENT',
            'END:VCALENDAR',
        );
        $property = Parser::lenient()->parseCalendar($ics)->events()[0]->property('SUMMARY');

        $this->assertInstanceOf(RawValue::class, $property?->value());
        $this->assertSame('Europe/Paris', $property?->parameter('TZID')?->value());

        $this->expectException(ParseException::class);
        Parser::strict()->parseCalendar($ics);
    }

    public function test_contradictory_or_unknown_encoding_never_produces_a_mislabeled_typed_value(): void
    {
        $cases = [
            'SUMMARY;VALUE=TEXT;ENCODING=BASE64:plain',
            'ATTACH;VALUE=BINARY;ENCODING=8BIT:aGVsbG8=',
        ];

        foreach ($cases as $line) {
            $ics = $this->ics('BEGIN:VCALENDAR', 'BEGIN:VEVENT', $line, 'END:VEVENT', 'END:VCALENDAR');
            $propertyName = substr($line, 0, (int) strpos($line, ';'));

            $this->assertInstanceOf(
                RawValue::class,
                Parser::lenient()->parseCalendar($ics)->events()[0]->property($propertyName)?->value(),
            );

            try {
                Parser::strict()->parseCalendar($ics);
                $this->fail('Expected a contradictory standard encoding to be rejected.');
            } catch (ParseException) {
                $this->addToAssertionCount(1);
            }
        }

        $unknown = $this->ics(
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'SUMMARY;ENCODING=X-PROVIDER:opaque',
            'END:VEVENT',
            'END:VCALENDAR',
        );
        $property = Parser::strict()->parseCalendar($unknown)->events()[0]->property('SUMMARY');
        $this->assertInstanceOf(RawValue::class, $property?->value());
        $this->assertSame('X-PROVIDER', $property?->parameter('ENCODING')?->value());
    }

    public function test_duplicate_parameter_names_are_preserved_ambiguously_and_rejected_strictly(): void
    {
        $ics = $this->ics(
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'DTSTART;TZID=Europe/Paris;TZID=America/New_York:20260701T100000',
            'END:VEVENT',
            'END:VCALENDAR',
        );
        $event = Parser::lenient()->parseCalendar($ics)->events()[0];
        $parameter = $event->property('DTSTART')?->parameter('TZID');

        $this->assertNull($event->start());
        $this->assertInstanceOf(RawValue::class, $event->property('DTSTART')?->value());
        $this->assertInstanceOf(RawParameter::class, $parameter);
        $this->assertSame(['Europe/Paris', 'America/New_York'], $parameter->values);

        $this->expectException(ParseException::class);
        Parser::strict()->parseCalendar($ics);
    }

    public function test_strict_rejects_mismatched_component_end(): void
    {
        $this->expectException(ParseException::class);
        Parser::strict()->parse("BEGIN:VEVENT\r\nEND:VTODO\r\n");
    }

    public function test_lenient_mode_keeps_malformed_date_as_raw(): void
    {
        $event = $this->firstEvent('DTSTART:not-a-date');
        $this->assertInstanceOf(RawValue::class, $event->property('DTSTART')?->value());
    }

    private function firstEvent(string ...$eventLines): Event
    {
        $lines = ['BEGIN:VCALENDAR', 'BEGIN:VEVENT', ...$eventLines, 'END:VEVENT', 'END:VCALENDAR'];
        $calendar = Parser::lenient()->parseCalendar(implode("\r\n", $lines));

        return $calendar->events()[0];
    }
}
