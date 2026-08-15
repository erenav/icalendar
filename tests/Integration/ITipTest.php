<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Exception\SchedulingException;
use Erenav\ICalendar\Parameter\ParameterBag;
use Erenav\ICalendar\Parameter\PartStat;
use Erenav\ICalendar\Parameter\Range;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Parser\Parser;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Scheduling\ITip;
use Erenav\ICalendar\Scheduling\Method;
use Erenav\ICalendar\Serializer\IcsSerializer;
use Erenav\ICalendar\ValueType\CalAddress;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\IntegerValue;
use Erenav\ICalendar\ValueType\RawValue;
use Erenav\ICalendar\ValueType\TextValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ITipTest extends TestCase
{
    private function invitation(): Event
    {
        return Event::build()
            ->uid('meeting@acme.test')
            ->summary('Sprint Planning')
            ->starts(DateTimeValue::utc(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC'))))
            ->organizer('boss@acme.test', name: 'The Boss')
            ->addAttendee('alice@acme.test')
            ->get();
    }

    public function test_request_sets_method_and_default_sequence(): void
    {
        $calendar = ITip::request($this->invitation());

        $this->assertSame(Method::Request, $calendar->schedulingMethod());
        $event = $calendar->events()[0];
        $this->assertSame(0, $event->sequence());
        $this->assertNotNull($event->timestamp()); // DTSTAMP stamped
    }

    public function test_cancel_cancels_and_bumps_sequence(): void
    {
        $oldStamp = DateTimeValue::utc(new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC')));
        $event = $this->invitation()->toBuilder()->sequence(2)->timestamp($oldStamp)->get();
        $calendar = ITip::cancel($event);

        $this->assertSame(Method::Cancel, $calendar->schedulingMethod());
        $this->assertSame(EventStatus::Cancelled, $calendar->events()[0]->status());
        $this->assertSame(3, $calendar->events()[0]->sequence());
        $this->assertNotSame($oldStamp->toString(), $calendar->events()[0]->timestamp()?->toString());
    }

    public function test_reply_carries_partstat_and_organizer(): void
    {
        $calendar = ITip::reply($this->invitation(), 'alice@acme.test', PartStat::Accepted);

        $this->assertSame(Method::Reply, $calendar->schedulingMethod());
        $event = $calendar->events()[0];
        $this->assertSame('meeting@acme.test', $event->uid());
        $this->assertSame('mailto:boss@acme.test', $event->organizer()?->address()->toString());

        $attendee = $event->attendees()[0];
        $this->assertSame('mailto:alice@acme.test', $attendee->address()->toString());
        $this->assertSame(PartStat::Accepted, $attendee->participationStatus());
    }

    public function test_reply_without_uid_throws(): void
    {
        $this->expectException(SchedulingException::class);
        ITip::reply(Event::build()->summary('No UID')->get(), 'a@test', PartStat::Declined);
    }

    public function test_publish_multiple_events(): void
    {
        $calendar = ITip::publish([$this->invitation(), $this->invitation()]);
        $this->assertSame(Method::Publish, $calendar->schedulingMethod());
        $this->assertCount(2, $calendar->events());
    }

    public function test_publish_rejects_an_empty_event_list(): void
    {
        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('without an event');

        ITip::publish([]);
    }

    public function test_itip_message_round_trips(): void
    {
        $ics = (new IcsSerializer)->serialize(ITip::request($this->invitation()));

        $this->assertStringContainsString('METHOD:REQUEST', $ics);

        $calendar = Parser::lenient()->parseCalendar($ics);
        $this->assertSame(Method::Request, $calendar->schedulingMethod());
        $this->assertSame(0, $calendar->events()[0]->sequence());
    }

    public function test_reply_preserves_range_and_scheduling_parameters(): void
    {
        $parameters = new ParameterBag(
            new RawParameter('CN', 'Alice'),
            new RawParameter('SENT-BY', 'mailto:delegate@acme.test'),
            new RawParameter('MEMBER', 'mailto:engineering@acme.test'),
            new RawParameter('DELEGATED-TO', 'mailto:bob@acme.test'),
            new RawParameter('RSVP', 'TRUE'),
            new RawParameter('LANGUAGE', 'en'),
        );
        $organizerParameters = new ParameterBag(
            new RawParameter('SENT-BY', 'mailto:assistant@acme.test'),
            new RawParameter('DIR', 'https://directory.test/boss'),
            new RawParameter('LANGUAGE', 'en-GB'),
        );
        $event = Event::build()->uid('series')->sequence(7)->starts(DateTimeValue::utc(new DateTimeImmutable('2026-07-02', new DateTimeZone('UTC'))))
            ->recurrenceId(DateTimeValue::utc(new DateTimeImmutable('2026-07-02', new DateTimeZone('UTC'))), Range::ThisAndFuture)
            ->attendeeProperty(new Property('ATTENDEE', CalAddress::fromEmail('alice@acme.test'), $parameters))
            ->organizerProperty(new Property('ORGANIZER', CalAddress::fromEmail('boss@acme.test'), $organizerParameters))->get();

        $reply = ITip::reply($event, 'alice@acme.test', PartStat::Accepted)->events()[0];
        $this->assertSame(Range::ThisAndFuture, $reply->recurrenceRange());
        $this->assertSame(7, $reply->sequence());
        $this->assertSame('Alice', $reply->attendees()[0]->commonName());
        $this->assertSame('delegate@acme.test', $reply->attendees()[0]->sentBy()?->email());
        $this->assertSame('engineering@acme.test', $reply->attendees()[0]->members()[0]->email());
        $this->assertSame('bob@acme.test', $reply->attendees()[0]->delegatedTo()[0]->email());
        $this->assertNull($reply->attendees()[0]->rsvp());
        $this->assertNull($reply->property('ATTENDEE')?->parameter('RSVP'));
        $this->assertSame('en', $reply->attendees()[0]->language());
        $organizer = $reply->organizer();
        $this->assertNotNull($organizer);
        $this->assertSame('mailto:assistant@acme.test', $organizer->sentBy());
        $this->assertSame('mailto:assistant@acme.test', $organizer->sentByAddress()?->toString());
        $this->assertSame('https://directory.test/boss', $organizer->directory());
        $this->assertSame('https://directory.test/boss', $organizer->directoryUri()?->toString());
        $this->assertSame('en-GB', $organizer->language());
    }

    public function test_reply_copies_complete_identity_and_recurrence_properties(): void
    {
        $uid = new Property('UID', new TextValue('series'), new ParameterBag(new RawParameter('X-UID-META', 'opaque')));
        $start = new Property(
            'DTSTART',
            DateTimeValue::zoned(new DateTimeImmutable('2026-07-02 09:00:00', new DateTimeZone('America/New_York')), 'America/New_York'),
            new ParameterBag(new RawParameter('TZID', 'America/New_York'), new RawParameter('X-START-META', 'opaque')),
        );
        $recurrenceId = new Property(
            'RECURRENCE-ID',
            DateTimeValue::zoned(new DateTimeImmutable('2026-07-02 09:00:00', new DateTimeZone('America/New_York')), 'America/New_York'),
            new ParameterBag(
                new RawParameter('TZID', 'America/New_York'),
                Range::ThisAndFuture,
                new RawParameter('X-PROVIDER-SLOT', 'abc'),
            ),
        );
        $sequence = new Property('SEQUENCE', new IntegerValue(7), new ParameterBag(new RawParameter('X-REVISION-META', 'opaque')));
        $attendee = new Property(
            'ATTENDEE',
            CalAddress::fromEmail('alice@acme.test'),
            new ParameterBag(PartStat::Tentative, new RawParameter('X-ATTENDEE-META', 'opaque')),
        );
        $organizer = new Property(
            'ORGANIZER',
            CalAddress::fromEmail('boss@acme.test'),
            new ParameterBag(new RawParameter('X-ORGANIZER-META', 'opaque')),
        );

        $source = Event::build()
            ->uidProperty($uid)
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'))))
            ->startProperty($start)
            ->recurrenceIdProperty($recurrenceId)
            ->sequenceProperty($sequence)
            ->attendeeProperty($attendee)
            ->organizerProperty($organizer)
            ->get();

        $reply = ITip::reply($source, 'alice@acme.test', PartStat::Accepted)->events()[0];

        $this->assertSame($uid, $reply->property('UID'));
        $this->assertSame($start, $reply->property('DTSTART'));
        $this->assertSame($recurrenceId, $reply->property('RECURRENCE-ID'));
        $this->assertSame($sequence, $reply->property('SEQUENCE'));
        $this->assertSame($organizer, $reply->property('ORGANIZER'));
        $this->assertNotSame($source->timestamp()?->toString(), $reply->timestamp()?->toString());
        $attendeeMeta = $reply->property('ATTENDEE')?->parameter('X-ATTENDEE-META');
        $this->assertInstanceOf(RawParameter::class, $attendeeMeta);
        $this->assertSame('opaque', $attendeeMeta->value());
        $this->assertSame(PartStat::Accepted, $reply->attendees()[0]->participationStatus());

        $serialized = (new IcsSerializer)->serialize(ITip::reply($source, 'alice@acme.test', PartStat::Accepted));
        $this->assertStringContainsString('UID;X-UID-META=opaque:series', $serialized);

        $roundTripped = Parser::lenient()->parseCalendar($serialized)->events()[0];
        $this->assertSame('America/New_York', $roundTripped->start()?->tzid);
        $this->assertSame(Range::ThisAndFuture, $roundTripped->recurrenceRange());
        $slotMeta = $roundTripped->property('RECURRENCE-ID')?->parameter('X-PROVIDER-SLOT');
        $this->assertInstanceOf(RawParameter::class, $slotMeta);
        $this->assertSame('abc', $slotMeta->value());
    }

    public function test_reply_rejects_untyped_recurrence_metadata(): void
    {
        $event = Event::build()
            ->uid('series')
            ->property('RECURRENCE-ID', new RawValue('not-a-date'))
            ->addAttendee('alice@acme.test')
            ->get();

        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('untyped RECURRENCE-ID');
        ITip::reply($event, 'alice@acme.test', PartStat::Accepted);
    }

    public function test_reply_rejects_raw_or_duplicate_singleton_metadata(): void
    {
        $rawUid = Event::build()
            ->property('UID', new RawValue('raw-uid'))
            ->addAttendee('alice@acme.test')
            ->get();
        try {
            ITip::reply($rawUid, 'alice@acme.test', PartStat::Accepted);
            $this->fail('Expected an untyped UID to be rejected.');
        } catch (SchedulingException $exception) {
            $this->assertStringContainsString('untyped UID', $exception->getMessage());
        }

        $duplicateRecurrenceId = Event::build()
            ->uid('series')
            ->recurrenceId(DateTimeValue::utc(new DateTimeImmutable('2026-07-01', new DateTimeZone('UTC'))))
            ->property('RECURRENCE-ID', DateTimeValue::utc(new DateTimeImmutable('2026-07-02', new DateTimeZone('UTC'))))
            ->addAttendee('alice@acme.test')
            ->get();

        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('duplicate, multi-valued, or untyped RECURRENCE-ID');
        ITip::reply($duplicateRecurrenceId, 'alice@acme.test', PartStat::Accepted);
    }

    public function test_reply_skips_a_malformed_attendee_before_a_valid_match(): void
    {
        $event = Event::build()
            ->uid('series')
            ->attendeeProperty(new Property('ATTENDEE', [
                new RawValue('not-a-calendar-address'),
                CalAddress::fromEmail('unrelated@acme.test'),
            ]))
            ->addAttendee('alice@acme.test', name: 'Alice')
            ->get();

        $reply = ITip::reply($event, 'alice@acme.test', PartStat::Accepted)->events()[0];

        $this->assertCount(1, $reply->attendees());
        $this->assertSame('Alice', $reply->attendees()[0]->commonName());
        $this->assertSame(PartStat::Accepted, $reply->attendees()[0]->participationStatus());
    }

    public function test_reply_rejects_a_multi_valued_attendee_when_the_match_is_not_first(): void
    {
        $event = Event::build()
            ->uid('series')
            ->attendeeProperty(new Property(
                'ATTENDEE',
                [
                    CalAddress::fromEmail('other@acme.test'),
                    CalAddress::fromEmail('alice@acme.test'),
                ],
                new ParameterBag(new RawParameter('X-ATTENDEE-META', 'must-not-be-dropped')),
            ))
            ->get();

        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('multi-valued or untyped matching ATTENDEE');
        ITip::reply($event, 'alice@acme.test', PartStat::Accepted);
    }

    public function test_reply_rejects_duplicate_matching_attendees(): void
    {
        $event = Event::build()
            ->uid('series')
            ->addAttendee('alice@acme.test', name: 'First')
            ->addAttendee('alice@acme.test', name: 'Second')
            ->get();

        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('duplicate matching ATTENDEE');
        ITip::reply($event, 'alice@acme.test', PartStat::Accepted);
    }

    /** @return iterable<string, array{PartStat}> */
    public static function invalidEventReplyPartStats(): iterable
    {
        yield 'VTODO COMPLETED' => [PartStat::Completed];
        yield 'VTODO IN-PROCESS' => [PartStat::InProcess];
    }

    #[DataProvider('invalidEventReplyPartStats')]
    public function test_reply_rejects_partstat_values_that_are_not_valid_for_vevent(PartStat $partStat): void
    {
        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('not a valid VEVENT');

        ITip::reply($this->invitation(), 'alice@acme.test', $partStat);
    }

    public function test_reply_rejects_a_negative_source_sequence(): void
    {
        $event = $this->invitation()->toBuilder()
            ->sequenceProperty(new Property('SEQUENCE', new IntegerValue(-1)))
            ->get();

        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('negative SEQUENCE');

        ITip::reply($event, 'alice@acme.test', PartStat::Accepted);
    }

    public function test_request_and_cancel_reject_ambiguous_or_invalid_source_sequences(): void
    {
        $duplicate = $this->invitation()->toBuilder()
            ->sequence(2)
            ->property('SEQUENCE', new IntegerValue(3))
            ->get();
        try {
            ITip::request($duplicate);
            $this->fail('Expected REQUEST to reject duplicate source SEQUENCE properties.');
        } catch (SchedulingException $exception) {
            $this->assertStringContainsString('duplicate', $exception->getMessage());
        }

        $negative = $this->invitation()->toBuilder()
            ->sequenceProperty(new Property('SEQUENCE', new IntegerValue(-1)))
            ->get();
        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('negative SEQUENCE');
        ITip::cancel($negative);
    }
}
