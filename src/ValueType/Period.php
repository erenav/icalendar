<?php

declare(strict_types=1);

namespace Erenav\ICalendar\ValueType;

use Erenav\ICalendar\Exception\InvalidValueException;

/**
 * An RFC 5545 PERIOD value (§3.3.9): either an explicit start/end pair, or a
 * start plus a duration. Exactly one of $end / $duration is set.
 */
final readonly class Period implements Value
{
    private function __construct(
        public DateTimeValue $start,
        public ?DateTimeValue $end,
        public ?Duration $duration,
    ) {
        if ($start->isDateOnly) {
            throw new InvalidValueException('A PERIOD start must be a DATE-TIME, not a DATE.');
        }
        if (($end === null) === ($duration === null)) {
            throw new InvalidValueException('A PERIOD must have exactly one of an end or a duration.');
        }
        if ($end !== null && $end->isDateOnly) {
            throw new InvalidValueException('A PERIOD end must be a DATE-TIME, not a DATE.');
        }
        if ($end !== null && $end->tzid !== $start->tzid) {
            throw new InvalidValueException('A PERIOD start and end cannot carry different timezone identifiers.');
        }
        if ($end !== null && ($end->isUtc !== $start->isUtc || $end->isFloating() !== $start->isFloating())) {
            throw new InvalidValueException('A PERIOD start and end must use compatible UTC, floating, or zoned DATE-TIME forms.');
        }
        if ($end !== null && $end->dateTime <= $start->dateTime) {
            throw new InvalidValueException('A PERIOD end must be later than its start.');
        }
        if ($duration !== null && $duration->toSeconds() <= 0) {
            throw new InvalidValueException('A PERIOD duration must be positive.');
        }
    }

    /** An explicit period: start "/" end. */
    public static function between(DateTimeValue $start, DateTimeValue $end): self
    {
        return new self($start, $end, null);
    }

    /** A start-and-duration period: start "/" duration. */
    public static function lasting(DateTimeValue $start, Duration $duration): self
    {
        return new self($start, null, $duration);
    }

    public function isExplicit(): bool
    {
        return $this->end !== null;
    }

    public function toString(): string
    {
        if ($this->end !== null) {
            return $this->start->toString().'/'.$this->end->toString();
        }
        if ($this->duration !== null) {
            return $this->start->toString().'/'.$this->duration->toString();
        }

        // Unreachable: the constructor guarantees exactly one of end/duration.
        throw new InvalidValueException('A PERIOD must have an end or a duration.');
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
