<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Component;

use Erenav\ICalendar\Property\PropertyBag;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\UtcOffset;

/**
 * A STANDARD or DAYLIGHT sub-component of a {@see TimeZone} (RFC 5545 §3.6.5):
 * one observance with its UTC offsets, onset (local DTSTART), recurrence and name.
 */
final readonly class Observance extends Component
{
    public function __construct(
        private bool $daylight,
        PropertyBag $properties = new PropertyBag,
        ComponentList $children = new ComponentList,
    ) {
        parent::__construct($properties, $children);
    }

    public function wireName(): string
    {
        return $this->daylight ? 'DAYLIGHT' : 'STANDARD';
    }

    public function isDaylight(): bool
    {
        return $this->daylight;
    }

    /** The local onset time of this observance (floating DATE-TIME). */
    public function start(): ?DateTimeValue
    {
        return $this->dateTimeOf('DTSTART');
    }

    public function offsetFrom(): ?UtcOffset
    {
        $value = $this->valueOf('TZOFFSETFROM');

        return $value instanceof UtcOffset ? $value : null;
    }

    public function offsetTo(): ?UtcOffset
    {
        $value = $this->valueOf('TZOFFSETTO');

        return $value instanceof UtcOffset ? $value : null;
    }

    public function name(): ?string
    {
        return $this->stringOf('TZNAME');
    }

    public function recurrenceRule(): ?Recurrence
    {
        $value = $this->valueOf('RRULE');

        return $value instanceof Recurrence ? $value : null;
    }

    /** @return list<DateTimeValue> Additional local transition onsets. */
    public function recurrenceDates(): array
    {
        $dates = [];
        foreach ($this->properties->all('RDATE') as $property) {
            foreach ($property->values as $value) {
                if ($value instanceof DateTimeValue) {
                    $dates[] = $value;
                }
            }
        }

        return $dates;
    }
}
