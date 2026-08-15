<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Recurrence;

use DateTimeImmutable;
use Erenav\ICalendar\Component\Event;

/**
 * A single resolved occurrence produced by calendar-level expansion.
 *
 * Unlike per-event expansion (which yields bare instants), this carries the
 * effective {@see Event} for the instance — the recurrence master, a detached
 * override, or a coherent event materialized from a range override.
 */
final readonly class Occurrence
{
    public function __construct(
        /** Effective start; DATE/floating values use a neutral wall-field container. */
        public DateTimeImmutable $start,
        /** Original series slot, using the same instant/wall-container convention. */
        public DateTimeImmutable $recurrenceId,
        /** The effective event: master, detached override, or materialized range result. */
        public Event $event,
        /** True when a RECURRENCE-ID override applied to this instance. */
        public bool $isOverride,
        /** RFC-effective end; DATE/floating values use the same neutral convention as start. */
        public ?DateTimeImmutable $end = null,
    ) {}
}
