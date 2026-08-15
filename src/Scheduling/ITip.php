<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use Erenav\ICalendar\Builder\EventBuilder;
use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\Exception\SchedulingException;
use Erenav\ICalendar\Parameter\PartStat;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\ValueType\CalAddress;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\IntegerValue;
use Erenav\ICalendar\ValueType\TextValue;
use Erenav\ICalendar\ValueType\Value;

/**
 * Builds iTIP (RFC 5546) scheduling messages — VCALENDARs with a METHOD and the
 * properties each transaction requires. PUBLISH/REQUEST add a UTC DTSTAMP when
 * the source lacks one; REPLY and the newly revised CANCEL receive a fresh stamp.
 *
 * Covers the four common transactions; for the rest, set the METHOD yourself via
 * the calendar builder and validate with {@see ITipValidator}.
 */
final class ITip
{
    public const PRODID = '-//erenav/icalendar//iTIP//EN';

    /**
     * A non-interactive PUBLISH feed of one or more events.
     *
     * @param  Event|list<Event>  $events
     */
    public static function publish(Event|array $events, ?string $prodId = null): Calendar
    {
        $events = is_array($events) ? $events : [$events];
        if ($events === []) {
            throw new SchedulingException('Cannot build a PUBLISH message without an event.');
        }

        return self::message(Method::Publish, array_map(self::stamped(...), $events), $prodId);
    }

    /** An organizer's REQUEST to invite attendees to an event. */
    public static function request(Event $event, ?string $prodId = null): Calendar
    {
        $event = self::stamped($event);
        $sequence = self::validatedSequence($event, 'REQUEST');
        if ($sequence === null) {
            $event = $event->toBuilder()->sequence(0)->get();
        }

        return self::message(Method::Request, [$event], $prodId);
    }

    /** An organizer's CANCEL of an event (sets STATUS:CANCELLED, bumps SEQUENCE). */
    public static function cancel(Event $event, ?string $prodId = null): Calendar
    {
        $sequence = self::validatedSequence($event, 'CANCEL');
        if ($sequence === 2147483647) {
            throw new SchedulingException('Cannot build a CANCEL whose SEQUENCE would exceed the RFC INTEGER range.');
        }

        $event = $event->toBuilder()
            // CANCEL is a new scheduling revision, so it needs a fresh stamp
            // even when the source component already carried one.
            ->timestamp(self::now())
            ->status(EventStatus::Cancelled)
            ->sequence(($sequence ?? 0) + 1)
            ->get();

        return self::message(Method::Cancel, [$event], $prodId);
    }

    /** An attendee's REPLY to an invitation with their participation status. */
    public static function reply(Event $event, string $attendee, PartStat $partStat, ?string $prodId = null): Calendar
    {
        if (! self::isEventPartStat($partStat)) {
            throw new SchedulingException(sprintf(
                '%s is not a valid VEVENT ATTENDEE participation status.',
                $partStat->value,
            ));
        }

        $uid = self::copyableSingleton($event, 'UID', TextValue::class, required: true)
            ?? throw new \LogicException('Required UID validation returned no property.');

        $builder = Event::build()->uidProperty($uid)->timestamp(self::now());

        $sourceAttendees = [];
        foreach ($event->attendees() as $candidate) {
            $matches = false;
            foreach ($candidate->property->values as $value) {
                try {
                    $address = $value instanceof CalAddress
                        ? $value
                        : CalAddress::fromUri($value->toString());
                } catch (InvalidValueException) {
                    // Malformed values in an unrelated leniently preserved
                    // ATTENDEE must not prevent a later valid match.
                    continue;
                }
                if (strcasecmp($address->toString(), $attendee) === 0
                    || ($address->email() !== null && strcasecmp($address->email(), str_starts_with(strtolower($attendee), 'mailto:') ? substr($attendee, 7) : $attendee) === 0)) {
                    $matches = true;

                    break;
                }
            }
            if (! $matches) {
                continue;
            }

            if (count($candidate->property->values) !== 1 || ! $candidate->property->value() instanceof CalAddress) {
                throw new SchedulingException('Cannot copy a multi-valued or untyped matching ATTENDEE into a REPLY.');
            }
            $sourceAttendees[] = $candidate;
        }
        if (count($sourceAttendees) > 1) {
            throw new SchedulingException('Cannot build a REPLY from duplicate matching ATTENDEE properties.');
        }
        $sourceAttendee = $sourceAttendees[0] ?? null;
        if ($sourceAttendee !== null) {
            // RSVP asks for a response and is forbidden on the ATTENDEE in a
            // VEVENT REPLY. Preserve every other standard, IANA, and extension
            // parameter while replacing PARTSTAT with the response status.
            $builder->attendeeProperty(
                $sourceAttendee->property
                    ->withParameters($sourceAttendee->property->parameters->without('RSVP'))
                    ->withParameter($partStat),
            );
        } else {
            $builder->addAttendee($attendee, partStat: $partStat);
        }

        if (($start = self::copyableSingleton($event, 'DTSTART', DateTimeValue::class)) !== null) {
            $builder->startProperty($start);
        }
        if (($recurrenceId = self::copyableSingleton($event, 'RECURRENCE-ID', DateTimeValue::class)) !== null) {
            $builder->recurrenceIdProperty($recurrenceId);
        }
        if (($sequence = self::copyableSingleton($event, 'SEQUENCE', IntegerValue::class)) !== null) {
            /** @var IntegerValue $sequenceValue */
            $sequenceValue = $sequence->value();
            if ($sequenceValue->value < 0) {
                throw new SchedulingException('Cannot copy a negative SEQUENCE into a REPLY.');
            }
            $builder->sequenceProperty($sequence);
        }
        self::copyOrganizer($event, $builder);

        return self::message(Method::Reply, [$builder->get()], $prodId);
    }

    /**
     * @param  list<Event>  $events
     */
    private static function message(Method $method, array $events, ?string $prodId): Calendar
    {
        $builder = Calendar::build()->prodId($prodId ?? self::PRODID)->method($method);
        foreach ($events as $event) {
            $builder->add($event);
        }

        return $builder->get();
    }

    private static function stamped(Event $event): Event
    {
        return $event->timestamp() !== null ? $event : $event->toBuilder()->timestamp(self::now())->get();
    }

    /**
     * Read an optional source SEQUENCE without allowing an ambiguous or invalid
     * revision to leak into a generated scheduling message.
     */
    private static function validatedSequence(Event $event, string $method): ?int
    {
        $properties = $event->properties->all('SEQUENCE');
        if ($properties === []) {
            return null;
        }
        if (count($properties) !== 1
            || count($properties[0]->values) !== 1
            || ! $properties[0]->value() instanceof IntegerValue
            || $properties[0]->value()->value < 0) {
            throw new SchedulingException(sprintf(
                'Cannot build a %s from a duplicate, multi-valued, untyped, or negative SEQUENCE.',
                $method,
            ));
        }

        /** @var IntegerValue $value */
        $value = $properties[0]->value();

        return $value->value;
    }

    private static function isEventPartStat(PartStat $partStat): bool
    {
        return $partStat !== PartStat::Completed && $partStat !== PartStat::InProcess;
    }

    private static function copyOrganizer(Event $event, EventBuilder $builder): void
    {
        $organizer = self::copyableSingleton($event, 'ORGANIZER', CalAddress::class);
        if ($organizer === null) {
            return;
        }

        $builder->organizerProperty($organizer);
    }

    /**
     * Return one complete, typed singleton property or fail before making a
     * lossy scheduling message from ambiguous external metadata.
     *
     * @template T of Value
     *
     * @param  class-string<T>  $valueType
     */
    private static function copyableSingleton(
        Event $event,
        string $name,
        string $valueType,
        bool $required = false,
    ): ?Property {
        $properties = $event->properties->all($name);
        if ($properties === []) {
            if ($required) {
                throw new SchedulingException(sprintf('Cannot build a REPLY for an event without a %s.', $name));
            }

            return null;
        }
        if (count($properties) !== 1
            || count($properties[0]->values) !== 1
            || ! $properties[0]->value() instanceof $valueType) {
            throw new SchedulingException(sprintf(
                'Cannot copy duplicate, multi-valued, or untyped %s into a REPLY.',
                $name,
            ));
        }

        return $properties[0];
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
