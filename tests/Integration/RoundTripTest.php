<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Integration;

use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Parameter\Role;
use Erenav\ICalendar\Parser\Parser;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\Serializer\IcsSerializer;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use Erenav\ICalendar\ValueType\RawValue;
use PHPUnit\Framework\TestCase;

final class RoundTripTest extends TestCase
{
    /**
     * A realistic calendar with VTIMEZONE, folding, escaped text, an RRULE,
     * quoted parameters, and an unmodelled X- property.
     */
    private function sampleIcs(): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Example Corp//Cal 1.0//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VTIMEZONE',
            'TZID:America/New_York',
            'BEGIN:STANDARD',
            'DTSTART:20071104T020000',
            'TZOFFSETFROM:-0400',
            'TZOFFSETTO:-0500',
            'TZNAME:EST',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:abc-123@example.com',
            'DTSTAMP:20260620T120000Z',
            'DTSTART;TZID=America/New_York:20260701T093000',
            'DTEND;TZID=America/New_York:20260701T103000',
            'SUMMARY:Sprint Planning\\, Q3',
            'DESCRIPTION:Line one\\nLine two',
            'LOCATION:Room 4',
            'RRULE:FREQ=WEEKLY;BYDAY=MO,WE',
            'CATEGORIES:work,planning',
            'STATUS:CONFIRMED',
            'ORGANIZER;CN=The Boss:mailto:boss@example.com',
            'ATTENDEE;CN="Doe, Alice";ROLE=CHAIR;PARTSTAT=ACCEPTED;RSVP=TRUE:mailto:alic',
            ' e@example.com',
            'X-CUSTOM-FLAG;X-PARAM=1:hello world',
            'BEGIN:VALARM',
            'ACTION:DISPLAY',
            'TRIGGER:-PT15M',
            'DESCRIPTION:Reminder',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    public function test_serialize_of_parse_is_a_stable_fixed_point(): void
    {
        $parser = Parser::lenient();
        $serializer = new IcsSerializer;

        $once = $serializer->serialize($parser->parse($this->sampleIcs()));
        $twice = $serializer->serialize($parser->parse($once));

        // Output is canonicalised on first pass, then stable forever after —
        // proof that no data degrades across repeated round trips (Level-1).
        $this->assertSame($once, $twice);
    }

    public function test_round_trip_preserves_unmodelled_data(): void
    {
        $output = (new IcsSerializer)->serialize(Parser::lenient()->parse($this->sampleIcs()));

        $this->assertStringContainsString('X-CUSTOM-FLAG;X-PARAM=1:hello world', $output);
        $this->assertStringContainsString('BEGIN:VTIMEZONE', $output);
        $this->assertStringContainsString('BEGIN:STANDARD', $output);
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=MO,WE', $output);
        $this->assertStringContainsString('TZOFFSETFROM:-0400', $output);
    }

    public function test_parsed_model_exposes_typed_data(): void
    {
        $calendar = Parser::lenient()->parseCalendar($this->sampleIcs());
        $event = $calendar->events()[0];

        $this->assertSame('abc-123@example.com', $event->uid());
        $this->assertSame('Sprint Planning, Q3', $event->summary());
        $this->assertSame("Line one\nLine two", $event->description());
        $this->assertSame('America/New_York', $event->start()?->tzid);
        $this->assertSame(EventStatus::Confirmed, $event->status());
        $this->assertSame(['work', 'planning'], $event->categories());
        $this->assertSame(Role::Chair, $event->attendees()[0]->role());
        $this->assertCount(1, $event->alarms());

        // VTIMEZONE survived as a generic component alongside the event.
        $this->assertCount(2, $calendar->components());
    }

    public function test_build_serialize_parse_full_circle(): void
    {
        $original = Calendar::build()
            ->prodId('-//Erenav//EN')
            ->add(
                Event::build()
                    ->uid('x@test')
                    ->summary('Café meeting; bring "notes", please')
                    ->starts(DateTimeValue::zoned(new \DateTimeImmutable('2026-07-01 09:30:00'), 'Europe/Paris'))
                    ->lasting(Duration::hours(1))
                    ->addAttendee('a@test', role: Role::Chair),
            )
            ->get();

        $ics = (new IcsSerializer)->serialize($original);
        $reparsed = Parser::lenient()->parseCalendar($ics);
        $event = $reparsed->events()[0];

        $this->assertSame('x@test', $event->uid());
        $this->assertSame('Café meeting; bring "notes", please', $event->summary());
        $this->assertSame('Europe/Paris', $event->start()?->tzid);
        $this->assertSame('PT1H', $event->duration()?->toString());
        $this->assertSame(Role::Chair, $event->attendees()[0]->role());
    }

    public function test_unknown_rrule_parts_survive_repeated_component_round_trips(): void
    {
        $input = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:unknown-rule\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;X-FIRST=one;IANA-PART=a,b;X-LAST=three\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $parser = Parser::lenient();
        $serializer = new IcsSerializer;
        $once = $serializer->serialize($parser->parseCalendar($input));
        $twice = $serializer->serialize($parser->parseCalendar($once));

        $this->assertSame($once, $twice);
        $this->assertStringContainsString('RRULE:FREQ=DAILY;X-FIRST=one;IANA-PART=a,b;X-LAST=three', $twice);
    }

    public function test_period_rdate_survives_repeated_component_round_trips(): void
    {
        $input = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:period\r\nDTSTART:20260701T100000Z\r\nRDATE;VALUE=PERIOD:20260702T100000Z/PT2H,20260703T100000Z/20260703T130000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $parser = Parser::lenient();
        $serializer = new IcsSerializer;
        $once = $serializer->serialize($parser->parseCalendar($input));
        $twice = $serializer->serialize($parser->parseCalendar($once));

        $this->assertSame($once, $twice);
        $this->assertStringContainsString('RDATE;VALUE=PERIOD:20260702T100000Z/PT2H,20260703T100000Z/202607', $twice);
        $this->assertCount(2, $parser->parseCalendar($twice)->events()[0]->recurrenceDatePeriods());
    }

    public function test_explicit_value_encoding_and_invalid_tzid_combinations_are_not_dropped_leniently(): void
    {
        $input = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:params\r\nDTSTART;TZID=Provider/Zone:20260701T100000Z\r\nX-DATA;VALUE=X-VENDOR;ENCODING=8BIT:opaque\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $calendar = Parser::lenient()->parseCalendar($input);
        $output = (new IcsSerializer)->serialize($calendar);

        $this->assertStringContainsString('DTSTART;TZID=Provider/Zone:20260701T100000Z', $output);
        $this->assertStringContainsString('X-DATA;VALUE=X-VENDOR;ENCODING=8BIT:opaque', $output);
        $this->assertNull($calendar->events()[0]->start());
        $this->assertInstanceOf(RawValue::class, $calendar->events()[0]->property('DTSTART')?->value());
    }

    public function test_request_status_structural_semicolons_survive_repeated_round_trips(): void
    {
        $input = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:request-status\r\nREQUEST-STATUS:2.0;Success\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $parser = Parser::lenient();
        $serializer = new IcsSerializer;
        $once = $serializer->serialize($parser->parseCalendar($input));
        $twice = $serializer->serialize($parser->parseCalendar($once));

        $this->assertSame($once, $twice);
        $this->assertStringContainsString('REQUEST-STATUS:2.0;Success', $twice);
        $this->assertStringNotContainsString('REQUEST-STATUS:2.0\\;Success', $twice);
    }
}
