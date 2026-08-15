<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Recurrence;

use DateTimeInterface;
use Erenav\ICalendar\Component\Event;

/**
 * Expands an event's recurrence into concrete occurrence starts. The default
 * implementation supports RRULE + DATE/DATE-TIME/PERIOD RDATE - EXDATE. The
 * interface remains start-only; calendar-level expansion attaches the PERIOD
 * duration/end to its effective Occurrence. This interface is the seam for
 * engines with other capabilities.
 */
interface RecurrenceExpander
{
    /**
     * Occurrence starts within the inclusive window [$from, $to]. DATE and
     * floating values may use neutral wall-clock containers rather than instants.
     *
     * @return list<\DateTimeImmutable>
     */
    public function between(Event $event, DateTimeInterface $from, DateTimeInterface $to): array;
}
