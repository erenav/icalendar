<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Scheduling;

use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Exception\SchedulingException;
use Erenav\ICalendar\Parameter\PartStat;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\IntegerValue;

/**
 * Checks a calendar against the iTIP (RFC 5546) constraints for its METHOD —
 * which properties each transaction's components must carry. Intentionally a
 * pragmatic subset of the full RFC tables, covering the properties that matter
 * in practice.
 */
final class ITipValidator
{
    /**
     * @return list<string> human-readable problems; empty means valid
     */
    public function validate(Calendar $calendar): array
    {
        $methodProperties = $calendar->properties->all('METHOD');
        if ($methodProperties === []) {
            return ['Calendar has no (recognised) METHOD property.'];
        }

        $errors = [];
        if (count($methodProperties) !== 1 || count($methodProperties[0]->values) !== 1) {
            $errors[] = 'Calendar must contain exactly one single-valued METHOD property.';
        }

        $method = $calendar->schedulingMethod();
        if ($method === null) {
            $errors[] = 'Calendar METHOD property is not recognised.';

            return $errors;
        }

        $events = $calendar->events();
        if ($events === []) {
            $errors[] = "Calendar METHOD {$method->value} requires at least one VEVENT.";
        }

        foreach ($events as $index => $event) {
            array_push($errors, ...$this->validateEvent($method, $event, $index));
        }

        return $errors;
    }

    public function isValid(Calendar $calendar): bool
    {
        return $this->validate($calendar) === [];
    }

    /**
     * @throws SchedulingException if the calendar violates its METHOD's constraints
     */
    public function assertValid(Calendar $calendar): void
    {
        $errors = $this->validate($calendar);
        if ($errors !== []) {
            throw new SchedulingException('Invalid iTIP message: '.implode(' ', $errors));
        }
    }

    /**
     * @return list<string>
     */
    private function validateEvent(Method $method, Event $event, int $index): array
    {
        $errors = [];
        $label = "VEVENT #{$index}";
        $require = function (string $property) use ($event, $label, &$errors): void {
            $properties = $event->properties->all($property);
            if ($properties === []) {
                $errors[] = "{$label}: missing required {$property}.";

                return;
            }
            if (count($properties) !== 1 || count($properties[0]->values) !== 1) {
                $errors[] = "{$label}: required {$property} must occur exactly once with one value.";
            }
        };
        $attendees = $event->attendees();
        $hasMultiValuedAttendee = false;
        foreach ($attendees as $attendee) {
            if (count($attendee->property->values) !== 1) {
                $hasMultiValuedAttendee = true;

                break;
            }
        }

        $require('UID');
        $this->validateDtstamp($event, $label, $errors);
        $this->validateSequence(
            $event,
            $label,
            in_array($method, [Method::Cancel, Method::Add, Method::DeclineCounter], true),
            $errors,
        );

        switch ($method) {
            case Method::Publish:
                $require('DTSTART');
                break;

            case Method::Request:
                $require('DTSTART');
                $require('ORGANIZER');
                if ($attendees === []) {
                    $errors[] = "{$label}: {$method->value} requires at least one ATTENDEE.";
                } elseif ($hasMultiValuedAttendee) {
                    $errors[] = "{$label}: each ATTENDEE must carry exactly one calendar address.";
                }
                break;

            case Method::Reply:
                $require('ORGANIZER');
                if (count($attendees) !== 1 || $hasMultiValuedAttendee) {
                    $errors[] = "{$label}: REPLY requires exactly one ATTENDEE.";
                } elseif ($attendees[0]->participationStatus() === null) {
                    $errors[] = "{$label}: REPLY requires its ATTENDEE to carry PARTSTAT.";
                } elseif (in_array($attendees[0]->participationStatus(), [PartStat::Completed, PartStat::InProcess], true)) {
                    $errors[] = "{$label}: REPLY ATTENDEE carries a PARTSTAT that is not valid for VEVENT.";
                }
                if (count($attendees) === 1 && $attendees[0]->property->parameter('RSVP') !== null) {
                    $errors[] = "{$label}: REPLY ATTENDEE must not carry RSVP.";
                }
                break;

            case Method::Cancel:
                $require('ORGANIZER');
                $this->validateCancelStatus($event, $label, $errors);
                break;

            case Method::Refresh:
                if (count($attendees) !== 1 || $hasMultiValuedAttendee) {
                    $errors[] = "{$label}: REFRESH requires exactly one ATTENDEE identifying the requester.";
                }
                break;

            case Method::Add:
                $require('DTSTART');
                $require('ORGANIZER');
                $require('RECURRENCE-ID');
                break;

            case Method::Counter:
                $require('DTSTART');
                $require('ORGANIZER');
                if (count($attendees) !== 1 || $hasMultiValuedAttendee) {
                    $errors[] = "{$label}: COUNTER requires exactly one ATTENDEE.";
                }
                break;

            case Method::DeclineCounter:
                $require('ORGANIZER');
                if (count($attendees) !== 1 || $hasMultiValuedAttendee) {
                    $errors[] = "{$label}: DECLINECOUNTER requires exactly one ATTENDEE.";
                }
                break;
        }

        return $errors;
    }

    /** @param list<string> $errors */
    private function validateDtstamp(Event $event, string $label, array &$errors): void
    {
        $properties = $event->properties->all('DTSTAMP');
        if ($properties === []) {
            $errors[] = "{$label}: missing required DTSTAMP.";

            return;
        }
        if (count($properties) !== 1 || count($properties[0]->values) !== 1) {
            $errors[] = "{$label}: required DTSTAMP must occur exactly once with one value.";

            return;
        }

        $property = $properties[0];
        $value = $property->value();
        $valueParameter = $property->parameter('VALUE');
        $hasValidExplicitValueType = $valueParameter === null
            || ($valueParameter instanceof RawParameter
                && count($valueParameter->values) === 1
                && strtoupper($valueParameter->value()) === 'DATE-TIME');
        if (! $value instanceof DateTimeValue
            || $value->isDateOnly
            || ! $value->isUtc
            || $property->parameter('TZID') !== null
            || ! $hasValidExplicitValueType) {
            $errors[] = "{$label}: DTSTAMP must be a UTC DATE-TIME value without TZID.";
        }
    }

    /** @param list<string> $errors */
    private function validateSequence(Event $event, string $label, bool $required, array &$errors): void
    {
        $properties = $event->properties->all('SEQUENCE');
        if ($properties === []) {
            if ($required) {
                $errors[] = "{$label}: missing required SEQUENCE.";
            }

            return;
        }
        if (count($properties) !== 1
            || count($properties[0]->values) !== 1
            || ! $properties[0]->value() instanceof IntegerValue
            || $properties[0]->value()->value < 0) {
            $errors[] = "{$label}: SEQUENCE must occur exactly once with one non-negative INTEGER value.";
        }
    }

    /** @param list<string> $errors */
    private function validateCancelStatus(Event $event, string $label, array &$errors): void
    {
        $properties = $event->properties->all('STATUS');
        if ($properties === []) {
            // STATUS is omitted when only selected attendees are being removed.
            return;
        }
        if (count($properties) !== 1
            || count($properties[0]->values) !== 1
            || $event->status() !== EventStatus::Cancelled) {
            $errors[] = "{$label}: CANCEL STATUS, when present, must occur exactly once with value CANCELLED.";
        }
    }
}
