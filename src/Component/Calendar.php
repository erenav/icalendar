<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Component;

use DateTimeInterface;
use Erenav\ICalendar\Builder\CalendarBuilder;
use Erenav\ICalendar\Recurrence\Occurrence;
use Erenav\ICalendar\Recurrence\OccurrenceExpander;
use Erenav\ICalendar\Scheduling\Method;
use Erenav\ICalendar\TimeZone\TimeZoneGenerator;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Period;
use Erenav\ICalendar\ValueType\TextValue;

/**
 * A VCALENDAR — the top of the tree. Holds calendar-level properties (PRODID,
 * VERSION, …) and the calendar components (events, time zones, etc.) as children.
 */
final readonly class Calendar extends Component
{
    public const WIRE_NAME = 'VCALENDAR';

    public function wireName(): string
    {
        return self::WIRE_NAME;
    }

    public static function build(): CalendarBuilder
    {
        return new CalendarBuilder;
    }

    /** A mutable builder pre-populated from this calendar, for immutable edits. */
    public function toBuilder(): CalendarBuilder
    {
        return CalendarBuilder::fromCalendar($this);
    }

    public function productId(): ?string
    {
        return $this->stringOf('PRODID');
    }

    public function version(): ?string
    {
        return $this->stringOf('VERSION');
    }

    public function calendarScale(): ?string
    {
        return $this->stringOf('CALSCALE');
    }

    public function name(): ?string
    {
        return $this->stringOf('NAME');
    }

    public function method(): ?string
    {
        return $this->stringOf('METHOD');
    }

    /** The METHOD as a typed iTIP {@see Method}, when recognised. */
    public function schedulingMethod(): ?Method
    {
        $value = $this->valueOf('METHOD');

        return match (true) {
            $value instanceof Method => $value,
            $value instanceof TextValue => Method::tryFrom(strtoupper($value->text)),
            default => null,
        };
    }

    /** @return list<Event> */
    public function events(): array
    {
        return $this->children->ofType(Event::class);
    }

    /** @return list<Component> */
    public function components(): array
    {
        return $this->children->all();
    }

    /** @return list<TimeZone> */
    public function timeZones(): array
    {
        return $this->children->ofType(TimeZone::class);
    }

    /**
     * Return a copy with a generated VTIMEZONE prepended for each referenced
     * IANA zone not already defined. The default generator models known rule
     * eras over 1970–2100; pass a configured generator for another horizon.
     */
    public function withTimeZones(?TimeZoneGenerator $generator = null): self
    {
        $generator ??= new TimeZoneGenerator;

        $existing = [];
        foreach ($this->timeZones() as $timeZone) {
            if ($timeZone->tzid() !== null) {
                $existing[$timeZone->tzid()] = true;
            }
        }

        $usedTzids = [];
        foreach ($this->children as $child) {
            $this->collectTzids($child, $usedTzids);
        }

        $generated = [];
        foreach (array_keys($usedTzids) as $tzid) {
            if (isset($existing[$tzid])) {
                continue;
            }
            $timeZone = $generator->tryForIana($tzid);
            if ($timeZone !== null) {
                $generated[] = $timeZone;
            }
        }

        if ($generated === []) {
            return $this;
        }

        return new self($this->properties, new ComponentList(...$generated, ...$this->children->all()));
    }

    /** @param array<string, true> $tzids */
    private function collectTzids(Component $component, array &$tzids): void
    {
        foreach ($component->properties as $property) {
            foreach ($property->values as $value) {
                if ($value instanceof DateTimeValue && $value->tzid !== null) {
                    $tzids[$value->tzid] = true;
                }
                if ($value instanceof Period && $value->start->tzid !== null) {
                    $tzids[$value->start->tzid] = true;
                }
            }
        }
        foreach ($component->children as $child) {
            $this->collectTzids($child, $tzids);
        }
    }

    /**
     * Resolved occurrences across the whole calendar within [$from, $to], with
     * RECURRENCE-ID overrides and cancellations applied, ordered by start. Each
     * {@see Occurrence} carries the effective event for that instance.
     *
     * @return list<Occurrence>
     */
    public function occurrencesBetween(
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?OccurrenceExpander $expander = null,
    ): array {
        return ($expander ?? new OccurrenceExpander)->between($this, $from, $to);
    }
}
