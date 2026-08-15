<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Recurrence;

use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Serializer\IcsSerializer;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\IntegerValue;

/** Deterministic import ordering for duplicate VEVENTs using RFC revision metadata. */
final class EventRevisionComparator
{
    /** Positive means $left is the newer/preferred revision. */
    public function compare(Event $left, Event $right): int
    {
        $leftSequence = $this->sequence($left);
        $rightSequence = $this->sequence($right);
        $result = ($leftSequence ?? 0) <=> ($rightSequence ?? 0);
        if ($result !== 0) {
            return $result;
        }

        $leftStamp = $this->utcTimestamp($left, 'DTSTAMP');
        $rightStamp = $this->utcTimestamp($right, 'DTSTAMP');
        $result = ($leftStamp ?? PHP_INT_MIN) <=> ($rightStamp ?? PHP_INT_MIN);
        if ($result !== 0) {
            return $result;
        }

        $leftModified = $this->utcTimestamp($left, 'LAST-MODIFIED');
        $rightModified = $this->utcTimestamp($right, 'LAST-MODIFIED');
        $result = ($leftModified ?? PHP_INT_MIN) <=> ($rightModified ?? PHP_INT_MIN);
        if ($result !== 0) {
            return $result;
        }

        return strcmp((new IcsSerializer)->serialize($left), (new IcsSerializer)->serialize($right));
    }

    public function preferred(Event $left, Event $right): Event
    {
        return $this->compare($left, $right) >= 0 ? $left : $right;
    }

    private function sequence(Event $event): ?int
    {
        $properties = $event->properties->all('SEQUENCE');
        if ($properties === []) {
            return null;
        }
        if (count($properties) !== 1
            || count($properties[0]->values) !== 1
            || ! $properties[0]->value() instanceof IntegerValue
            || $properties[0]->value()->value < 0) {
            throw new UnsupportedRecurrenceException(
                'Cannot select duplicate VEVENT revisions with duplicate, multi-valued, untyped, or negative SEQUENCE metadata.',
            );
        }

        /** @var IntegerValue $value */
        $value = $properties[0]->value();

        return $value->value;
    }

    private function utcTimestamp(Event $event, string $name): ?int
    {
        $properties = $event->properties->all($name);
        if ($properties === []) {
            return null;
        }
        if (count($properties) !== 1
            || count($properties[0]->values) !== 1
            || ! $properties[0]->value() instanceof DateTimeValue
            || ! $properties[0]->value()->isUtc
            || $properties[0]->value()->isDateOnly) {
            throw new UnsupportedRecurrenceException(sprintf(
                'Cannot select duplicate VEVENT revisions unless %s is one UTC DATE-TIME value.',
                $name,
            ));
        }

        /** @var DateTimeValue $value */
        $value = $properties[0]->value();

        return $value->dateTime->getTimestamp();
    }
}
