<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Component;

use DateTimeInterface;
use Erenav\ICalendar\Builder\EventBuilder;
use Erenav\ICalendar\Parameter\Range;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Property\Attendee;
use Erenav\ICalendar\Property\Classification;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\Property\Organizer;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Property\Transparency;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\Recurrence\RecurrenceExpander;
use Erenav\ICalendar\Recurrence\RlanvinRecurrenceExpander;
use Erenav\ICalendar\TimeZone\TimeZoneResolver;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use Erenav\ICalendar\ValueType\GeoValue;
use Erenav\ICalendar\ValueType\Period;
use Erenav\ICalendar\ValueType\TextValue;

/**
 * A VEVENT. Typed getters read from the underlying property bag; properties not
 * surfaced here remain accessible (and round-trippable) via {@see self::property()}
 * and {@see self::$properties}.
 */
final readonly class Event extends Component
{
    public const WIRE_NAME = 'VEVENT';

    public function wireName(): string
    {
        return self::WIRE_NAME;
    }

    public static function build(): EventBuilder
    {
        return new EventBuilder;
    }

    /** A mutable builder pre-populated from this event, for immutable edits. */
    public function toBuilder(): EventBuilder
    {
        return EventBuilder::fromEvent($this);
    }

    public function uid(): ?string
    {
        return $this->stringOf('UID');
    }

    public function summary(): ?string
    {
        return $this->stringOf('SUMMARY');
    }

    public function description(): ?string
    {
        return $this->stringOf('DESCRIPTION');
    }

    public function location(): ?string
    {
        return $this->stringOf('LOCATION');
    }

    public function url(): ?string
    {
        return $this->stringOf('URL');
    }

    public function timestamp(): ?DateTimeValue
    {
        return $this->dateTimeOf('DTSTAMP');
    }

    public function start(): ?DateTimeValue
    {
        return $this->dateTimeOf('DTSTART');
    }

    public function created(): ?DateTimeValue
    {
        return $this->dateTimeOf('CREATED');
    }

    public function lastModified(): ?DateTimeValue
    {
        return $this->dateTimeOf('LAST-MODIFIED');
    }

    public function duration(): ?Duration
    {
        $value = $this->valueOf('DURATION');

        return $value instanceof Duration ? $value : null;
    }

    /**
     * The end instant: DTEND if present, otherwise DTSTART + DURATION resolved in
     * the start's own form. A second-occurrence DST-fold end falls back to UTC
     * because its local TZID wall form cannot identify that instant unambiguously.
     * Null when neither is determinable.
     */
    public function end(?TimeZoneResolver $timeZones = null): ?DateTimeValue
    {
        $dtend = $this->dateTimeOf('DTEND');
        if ($dtend !== null) {
            return $dtend;
        }

        $start = $this->start();
        $duration = $this->duration();
        if ($start === null || $duration === null) {
            return null;
        }

        return $timeZones !== null && $start->tzid !== null
            ? $timeZones->addDuration($start, $duration)
            : $start->adding($duration);
    }

    /**
     * The RFC 5545 effective end boundary.
     *
     * Unlike {@see self::end()}, this also applies VEVENT's implicit duration:
     * a DATE starts a one-day event and a DATE-TIME with no DTEND/DURATION has
     * zero duration. Keeping this separate preserves the historical nullable
     * behavior of {@see self::end()}.
     */
    public function effectiveEnd(?TimeZoneResolver $timeZones = null): ?DateTimeValue
    {
        $explicit = $this->end($timeZones);
        if ($explicit !== null) {
            return $explicit;
        }

        $start = $this->start();
        if ($start === null) {
            return null;
        }
        if (! $start->isDateOnly) {
            return $start;
        }

        return DateTimeValue::date($start->dateTime->modify('+1 day'));
    }

    public function status(): ?EventStatus
    {
        $value = $this->valueOf('STATUS');

        return match (true) {
            $value instanceof EventStatus => $value,
            $value instanceof TextValue => EventStatus::tryFrom(strtoupper($value->text)),
            default => null,
        };
    }

    public function isCancelled(): bool
    {
        return $this->status() === EventStatus::Cancelled;
    }

    /** The original series slot identified by a detached recurrence component. */
    public function recurrenceId(): ?DateTimeValue
    {
        return $this->dateTimeOf('RECURRENCE-ID');
    }

    /** RANGE belongs to RECURRENCE-ID; null denotes a single-instance override. */
    public function recurrenceRange(): ?Range
    {
        $range = $this->properties->first('RECURRENCE-ID')?->parameter('RANGE');

        return match (true) {
            $range instanceof Range => $range,
            $range instanceof RawParameter && count($range->values) === 1 => Range::tryFrom(strtoupper($range->value())),
            default => null,
        };
    }

    public function transparency(): ?Transparency
    {
        $value = $this->valueOf('TRANSP');

        return match (true) {
            $value instanceof Transparency => $value,
            $value instanceof TextValue => Transparency::tryFrom(strtoupper($value->text)),
            default => null,
        };
    }

    public function classification(): ?Classification
    {
        $value = $this->valueOf('CLASS');

        return match (true) {
            $value instanceof Classification => $value,
            $value instanceof TextValue => Classification::tryFrom(strtoupper($value->text)),
            default => null,
        };
    }

    public function geo(): ?GeoValue
    {
        $value = $this->valueOf('GEO');

        return $value instanceof GeoValue ? $value : null;
    }

    public function priority(): ?int
    {
        return $this->intOf('PRIORITY');
    }

    public function sequence(): ?int
    {
        return $this->intOf('SEQUENCE');
    }

    public function color(): ?string
    {
        return $this->stringOf('COLOR');
    }

    /** @return list<string> */
    public function categories(): array
    {
        $categories = [];
        foreach ($this->properties->all('CATEGORIES') as $property) {
            foreach ($property->values as $value) {
                $categories[] = $value->toString();
            }
        }

        return $categories;
    }

    public function organizer(): ?Organizer
    {
        $property = $this->properties->first('ORGANIZER');

        return $property !== null ? new Organizer($property) : null;
    }

    /** @return list<Attendee> */
    public function attendees(): array
    {
        return array_map(
            static fn (Property $property): Attendee => new Attendee($property),
            $this->properties->all('ATTENDEE'),
        );
    }

    /** @return list<Alarm> */
    public function alarms(): array
    {
        return $this->children->ofType(Alarm::class);
    }

    public function recurrenceRule(): ?Recurrence
    {
        $value = $this->valueOf('RRULE');

        return $value instanceof Recurrence ? $value : null;
    }

    /** @return list<DateTimeValue> */
    public function exceptionDates(): array
    {
        return $this->collectDateTimes('EXDATE');
    }

    /** @return list<DateTimeValue> */
    public function recurrenceDates(): array
    {
        return $this->collectDateTimes('RDATE');
    }

    /**
     * PERIOD-valued RDATEs, kept separate because each carries its own end/duration.
     *
     * @return list<Period>
     */
    public function recurrenceDatePeriods(): array
    {
        $periods = [];
        foreach ($this->properties->all('RDATE') as $property) {
            foreach ($property->values as $value) {
                if ($value instanceof Period) {
                    $periods[] = $value;
                }
            }
        }

        return $periods;
    }

    public function isRecurring(): bool
    {
        return $this->recurrenceRule() !== null || $this->recurrenceDates() !== [] || $this->recurrenceDatePeriods() !== [];
    }

    /**
     * Expand this event's occurrences that start within [$from, $to] (inclusive).
     * Honours RRULE, DATE/DATE-TIME/PERIOD RDATE and EXDATE; a non-recurring
     * event yields its single start if it falls in range. This start-only API
     * cannot expose a PERIOD's end; use Calendar::occurrencesBetween() when the
     * effective occurrence end is required.
     *
     * UTC/zoned results are instants. DATE/floating results are neutral
     * DateTimeImmutable containers for their calendar fields.
     *
     * @return list<\DateTimeImmutable>
     */
    public function occurrencesBetween(
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?RecurrenceExpander $expander = null,
    ): array {
        return ($expander ?? new RlanvinRecurrenceExpander)->between($this, $from, $to);
    }

    /** @return list<DateTimeValue> */
    private function collectDateTimes(string $name): array
    {
        $dates = [];
        foreach ($this->properties->all($name) as $property) {
            foreach ($property->values as $value) {
                if ($value instanceof DateTimeValue) {
                    $dates[] = $value;
                }
            }
        }

        return $dates;
    }
}
