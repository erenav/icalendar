<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\Builder;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\Parameter\PartStat;
use Erenav\ICalendar\Parameter\Role;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use Erenav\ICalendar\ValueType\Period;
use PHPUnit\Framework\TestCase;

final class EventBuilderTest extends TestCase
{
    public function test_builds_an_immutable_event(): void
    {
        $event = Event::build()
            ->uid('meeting-42@app.test')
            ->summary('Sprint Planning')
            ->location('Room 4')
            ->status(EventStatus::Confirmed)
            ->get();

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('meeting-42@app.test', $event->uid());
        $this->assertSame('Sprint Planning', $event->summary());
        $this->assertSame('Room 4', $event->location());
        $this->assertSame(EventStatus::Confirmed, $event->status());
    }

    public function test_accepts_datetime_interface_for_dates(): void
    {
        $event = Event::build()
            ->starts(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC')))
            ->ends(new DateTime('2026-07-01 11:00:00', new DateTimeZone('UTC')))
            ->get();

        $this->assertSame('20260701T100000Z', $event->start()?->toString());
        $this->assertSame('20260701T110000Z', $event->end()?->toString());
    }

    public function test_lasting_and_ends_are_mutually_exclusive(): void
    {
        $event = Event::build()
            ->starts(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC')))
            ->ends(new DateTimeImmutable('2026-07-01 11:00:00', new DateTimeZone('UTC')))
            ->lasting(Duration::hours(2))
            ->get();

        $this->assertNull($event->property('DTEND'));
        $this->assertSame('PT2H', $event->duration()?->toString());
    }

    public function test_ends_clears_a_previously_set_duration(): void
    {
        $event = Event::build()
            ->lasting(Duration::hours(2))
            ->ends(new DateTimeImmutable('2026-07-01 11:00:00', new DateTimeZone('UTC')))
            ->get();

        $this->assertNull($event->duration());
        $this->assertNotNull($event->property('DTEND'));
    }

    public function test_lasting_accepts_date_interval(): void
    {
        $event = Event::build()->lasting(new DateInterval('PT90M'))->get();
        $this->assertSame('PT90M', $event->duration()?->toString());
    }

    public function test_add_attendee_appends_with_parameters(): void
    {
        $event = Event::build()
            ->addAttendee('alice@app.test', role: Role::Chair, rsvp: true, name: 'Alice')
            ->addAttendee('bob@app.test', partStat: PartStat::Accepted)
            ->get();

        $this->assertCount(2, $event->attendees());

        $alice = $event->attendees()[0];
        $this->assertSame('mailto:alice@app.test', $alice->address()->toString());
        $this->assertSame(Role::Chair, $alice->role());
        $this->assertTrue($alice->rsvp());
        $this->assertSame('Alice', $alice->commonName());

        $bob = $event->attendees()[1];
        $this->assertSame(PartStat::Accepted, $bob->participationStatus());
    }

    public function test_add_attendee_rejects_vtodo_only_participation_states(): void
    {
        foreach ([PartStat::Completed, PartStat::InProcess] as $status) {
            try {
                Event::build()->addAttendee('alice@app.test', partStat: $status);
                $this->fail("Expected {$status->value} to be rejected for VEVENT.");
            } catch (InvalidValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_organizer_with_common_name(): void
    {
        $event = Event::build()->organizer('boss@app.test', name: 'The Boss')->get();
        $organizer = $event->organizer();

        $this->assertSame('mailto:boss@app.test', $organizer?->address()->toString());
        $this->assertSame('The Boss', $organizer?->commonName());
    }

    public function test_organizer_accepts_explicit_uri(): void
    {
        $event = Event::build()->organizer('https://dir.test/u/1')->get();
        $this->assertSame('https://dir.test/u/1', $event->organizer()?->address()->toString());
    }

    public function test_organizer_normalizes_sent_by_as_a_calendar_address(): void
    {
        $organizer = Event::build()
            ->organizer('boss@app.test', sentBy: 'assistant@app.test')
            ->get()
            ->organizer();

        $this->assertSame('mailto:assistant@app.test', $organizer?->sentBy());
        $this->assertSame('mailto:assistant@app.test', $organizer?->sentByAddress()?->toString());
    }

    public function test_sequence_rejects_negative_values(): void
    {
        $this->expectException(InvalidValueException::class);

        Event::build()->sequence(-1);
    }

    public function test_categories_replace_and_clear(): void
    {
        $this->assertSame(['work', 'planning'], Event::build()->categories('work', 'planning')->get()->categories());
        $this->assertSame(['x'], Event::build()->categories('work')->categories('x')->get()->categories());
        $this->assertSame([], Event::build()->categories('work')->categories()->get()->categories());
    }

    public function test_color_has_matching_read_api(): void
    {
        $this->assertSame('blue', Event::build()->color('blue')->get()->color());
    }

    public function test_add_recurrence_period_keeps_each_period_parameter_context_separate(): void
    {
        $utc = Period::lasting(
            DateTimeValue::utc(new DateTimeImmutable('2026-07-01 10:00:00Z')),
            Duration::hours(1),
        );
        $zoned = Period::lasting(
            DateTimeValue::zoned(new DateTimeImmutable('2026-07-02 10:00:00', new DateTimeZone('America/New_York')), 'America/New_York'),
            Duration::hours(2),
        );
        $event = Event::build()->addRecurrencePeriod($utc, $zoned)->get();

        $this->assertSame([$utc, $zoned], $event->recurrenceDatePeriods());
        $this->assertCount(2, $event->properties->all('RDATE'));
    }

    public function test_recurrence_date_builders_split_parameter_incompatible_values_without_reordering(): void
    {
        $newYorkOne = DateTimeValue::zoned(new DateTimeImmutable('2026-07-01 09:00:00'), 'America/New_York');
        $newYorkTwo = DateTimeValue::zoned(new DateTimeImmutable('2026-07-02 09:00:00'), 'America/New_York');
        $losAngeles = DateTimeValue::zoned(new DateTimeImmutable('2026-07-03 09:00:00'), 'America/Los_Angeles');
        $utc = DateTimeValue::utc(new DateTimeImmutable('2026-07-04 09:00:00Z'));
        $date = DateTimeValue::date(new DateTimeImmutable('2026-07-05'));

        $event = Event::build()
            ->addRecurrenceDate($newYorkOne, $newYorkTwo, $losAngeles, $utc, $date)
            ->addExceptionDate($newYorkOne, $losAngeles)
            ->get();

        $rdates = $event->properties->all('RDATE');
        $this->assertCount(4, $rdates);
        $this->assertSame([$newYorkOne, $newYorkTwo], $rdates[0]->values);
        $this->assertSame([$losAngeles], $rdates[1]->values);
        $this->assertSame([$utc], $rdates[2]->values);
        $this->assertSame([$date], $rdates[3]->values);

        $exdates = $event->properties->all('EXDATE');
        $this->assertCount(2, $exdates);
        $this->assertSame([$newYorkOne], $exdates[0]->values);
        $this->assertSame([$losAngeles], $exdates[1]->values);
    }

    public function test_property_escape_hatch_appends(): void
    {
        $event = Event::build()
            ->property('X-FLAG', 'a')
            ->property('X-FLAG', 'b')
            ->get();

        $this->assertCount(2, $event->properties->all('X-FLAG'));
    }

    public function test_to_builder_round_trips_and_keeps_original_immutable(): void
    {
        $original = Event::build()
            ->uid('1')
            ->summary('Hi')
            ->addAttendee('a@app.test', role: Role::Chair)
            ->get();

        $edited = $original->toBuilder()->summary('Bye')->get();

        $this->assertSame('1', $edited->uid());
        $this->assertSame('Bye', $edited->summary());
        $this->assertCount(1, $edited->attendees());
        $this->assertSame('Hi', $original->summary());
    }
}
