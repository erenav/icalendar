<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Recurrence;

use Erenav\ICalendar\Exception\InvalidValueException;

/**
 * One entry in an RRULE BYDAY part: a weekday with an optional ordinal, e.g.
 * `MO` (every Monday), `2MO` (the 2nd Monday), `-1SU` (the last Sunday).
 */
final readonly class WeekdayRule
{
    public function __construct(
        public Weekday $weekday,
        public ?int $ordinal = null,
    ) {
        if ($ordinal === 0 || ($ordinal !== null && abs($ordinal) > 53)) {
            throw new InvalidValueException('A BYDAY ordinal must be between -53 and 53 and cannot be zero.');
        }
    }

    public static function parse(string $value): self
    {
        if (preg_match('/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/', strtoupper(trim($value)), $m) !== 1) {
            throw new InvalidValueException(sprintf('Malformed BYDAY value "%s".', $value));
        }

        $ordinal = $m[1] !== '' ? (int) $m[1] : null;

        return new self(Weekday::from($m[2]), $ordinal);
    }

    public function toString(): string
    {
        return ($this->ordinal !== null ? (string) $this->ordinal : '').$this->weekday->value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
