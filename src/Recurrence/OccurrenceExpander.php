<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Recurrence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Parameter\Range;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Property\PropertyBag;
use Erenav\ICalendar\TimeZone\TimeZoneResolver;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use Erenav\ICalendar\ValueType\Period;

/** Calendar-level expansion with revisions, detached overrides and RFC RANGE handling. */
final class OccurrenceExpander
{
    public function __construct(
        private readonly RecurrenceExpander $expander = new RlanvinRecurrenceExpander,
        private readonly EventRevisionComparator $revisions = new EventRevisionComparator,
        private readonly ?TimeZoneResolver $timeZones = null,
    ) {}

    /**
     * Effective starts in the inclusive window [$from, $to], ordered by start.
     *
     * @return list<Occurrence>
     */
    public function between(Calendar $calendar, DateTimeInterface $from, DateTimeInterface $to): array
    {
        if ($this->timeZones === null) {
            $resolver = TimeZoneResolver::fromCalendar($calendar);
            $expander = $this->expander instanceof RlanvinRecurrenceExpander
                ? $this->expander->withTimeZoneResolver($resolver)
                : $this->expander;

            return (new self($expander, $this->revisions, $resolver))->between($calendar, $from, $to);
        }

        $from = DateTimeImmutable::createFromInterface($from);
        $to = DateTimeImmutable::createFromInterface($to);
        $groups = [];
        $standalone = [];
        foreach ($calendar->events() as $event) {
            ($event->uid() === null) ? $standalone[] = $event : $groups[$event->uid()][] = $event;
        }

        $result = [];
        foreach ($groups as $events) {
            array_push($result, ...$this->expandGroup($events, $from, $to));
        }
        foreach ($standalone as $event) {
            if ($event->properties->all('RECURRENCE-ID') !== []) {
                throw new UnsupportedRecurrenceException(
                    'Cannot resolve a RECURRENCE-ID component without a UID and recurrence master.',
                );
            }
            if ($event->isCancelled()) {
                continue;
            }
            $startValue = $this->optionalSingleDateTime($event, 'DTSTART');
            if ($startValue !== null) {
                $this->assertMasterTemporalProperties($event, $startValue);
            }
            $periods = $this->periodsBySlot($event);
            foreach ($this->expander->between($event, $from, $to) as $start) {
                $period = $startValue !== null
                    ? ($periods[$this->slotKey($this->inMasterForm($start, $startValue))] ?? null)
                    : null;
                $effectiveEvent = $startValue !== null
                    ? $this->withCoherentCustomDuration($event, $start, $startValue)
                    : $event;
                $result[] = $period !== null
                    ? $this->periodOccurrence($event, $startValue, $period, $start)
                    : new Occurrence(
                        $start,
                        $start,
                        $effectiveEvent,
                        false,
                        $this->occurrenceEnd($start, $effectiveEvent, $startValue),
                    );
            }
        }
        usort($result, static fn (Occurrence $a, Occurrence $b): int => $a->start <=> $b->start);

        return $result;
    }

    /**
     * @param  list<Event>  $events
     * @return list<Occurrence>
     */
    private function expandGroup(array $events, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $master = null;
        $overrides = [];
        foreach ($events as $event) {
            // Inspect the property itself. A leniently preserved malformed value must
            // never be mistaken for the recurrence master merely because its typed
            // accessor returns null.
            $rid = $this->optionalRecurrenceId($event);
            if ($rid === null) {
                $master = $master === null ? $event : $this->revisions->preferred($master, $event);

                continue;
            }
            $this->assertResolvableZonedInstant($rid);
            $key = $this->slotKey($rid);
            $overrides[$key] = isset($overrides[$key]) ? $this->revisions->preferred($overrides[$key], $event) : $event;
        }

        foreach ($overrides as $override) {
            $this->assertOverrideTemporalProperties($override, $master?->start());
        }

        if ($master === null) {
            foreach ($overrides as $override) {
                if ($override->recurrenceRange() === Range::ThisAndFuture) {
                    throw new UnsupportedRecurrenceException('Cannot apply RANGE=THISANDFUTURE without a recurrence master.');
                }
            }

            return array_values(array_filter(array_map(fn (Event $event): ?Occurrence => $this->detached($event, $from, $to), $overrides)));
        }

        // A cancelled revision of the master cancels the series. Detached revisions
        // in the same UID group do not resurrect it without a newer active master.
        if ($master->isCancelled()) {
            return [];
        }

        $masterStart = $this->optionalSingleDateTime($master, 'DTSTART');
        if ($masterStart !== null) {
            $this->assertMasterTemporalProperties($master, $masterStart);
            foreach ($overrides as $override) {
                $this->assertCompatibleForm(
                    $masterStart,
                    $this->recurrenceId($override),
                    'RECURRENCE-ID must use a recurrence form compatible with the recurrence master DTSTART.',
                );
            }
        }

        $ranges = array_values(array_filter($overrides, static fn (Event $event): bool => $event->recurrenceRange() === Range::ThisAndFuture));
        if ($ranges !== [] && $masterStart === null) {
            throw new UnsupportedRecurrenceException('Cannot apply RANGE=THISANDFUTURE when the recurrence master has no typed DTSTART.');
        }
        if ($masterStart !== null) {
            usort($ranges, fn (Event $a, Event $b): int => $this->compareSlots(
                $this->recurrenceId($a),
                $this->recurrenceId($b),
                $masterStart,
            ));
            foreach ($ranges as $range) {
                $this->assertRangeSlotExists($master, $masterStart, $range);
            }
        }

        // Include slots which a known move can bring into the requested
        // effective-start window. The final filter below keeps the public window
        // exact; the guard covers offset discontinuities between a range onset and
        // later recurrence slots.
        $deltas = [0];
        foreach ($overrides as $override) {
            $start = $override->start();
            if ($start === null) {
                continue;
            }
            $rid = $this->recurrenceId($override);
            if ($masterStart !== null && $override->recurrenceRange() === Range::ThisAndFuture) {
                $slot = $this->slotInMasterBacking($rid, $masterStart);
                $shifted = $this->shiftedRangeStart($slot, $masterStart, $override);
                $deltas[] = $shifted['instant']->getTimestamp() - $slot->getTimestamp();
            } else {
                $deltas[] = $this->instant($start)->getTimestamp() - $this->instant($rid)->getTimestamp();
            }
        }
        $wallClockGuard = count($deltas) > 1 ? 172800 : 0;
        $baseFrom = $from->modify(sprintf('%+d seconds', -max($deltas) - $wallClockGuard));
        $baseTo = $to->modify(sprintf('%+d seconds', -min($deltas) + $wallClockGuard));

        $result = [];
        $matched = [];
        $periods = $this->periodsBySlot($master);
        foreach ($this->expander->between($master, $baseFrom, $baseTo) as $baseStart) {
            $slotValue = $this->inMasterForm($baseStart, $masterStart);
            $key = $this->slotKey($slotValue);
            $period = $periods[$key] ?? null;
            $matched[$key] = true;
            $single = $overrides[$key] ?? null;
            $range = $masterStart !== null ? $this->rangeFor($ranges, $slotValue, $masterStart) : null;
            if ($single !== null && $single->recurrenceRange() === null) {
                $occurrence = $range !== null
                    ? $this->singleOverRangeOccurrence($master, $masterStart, $ranges, $range, $single, $baseStart, $period)
                    : $this->singleOverMasterOccurrence(
                        $master,
                        $masterStart ?? throw new \LogicException('A generated master slot requires DTSTART.'),
                        $single,
                        $baseStart,
                        $period,
                    );
            } else {
                $occurrence = $range !== null
                    ? $this->rangeOccurrence($master, $masterStart, $ranges, $range, $baseStart, $period)
                    : ($period !== null
                        ? $this->periodOccurrence(
                            $master,
                            $masterStart ?? throw new \LogicException('A PERIOD RDATE requires DTSTART.'),
                            $period,
                            $baseStart,
                        )
                        : new Occurrence(
                            $baseStart,
                            $baseStart,
                            $master,
                            false,
                            $this->occurrenceEnd($baseStart, $master, $masterStart),
                        ));
            }
            if ($occurrence !== null && $occurrence->start >= $from && $occurrence->start <= $to) {
                $result[] = $occurrence;
            }
        }

        // A single-instance override whose RECURRENCE-ID is not a generated slot is
        // surfaced as an orphan. A range override is never treated that way: its
        // propagation semantics require a real series slot and were validated above.
        foreach ($overrides as $key => $override) {
            if (! isset($matched[$key]) && $override->recurrenceRange() === null) {
                $occurrence = $this->detached($override, $from, $to);
                if ($occurrence !== null) {
                    $result[] = $occurrence;
                }
            }
        }

        return $result;
    }

    /** @param list<Event> $ranges */
    private function rangeFor(array $ranges, DateTimeValue $slot, DateTimeValue $masterStart): ?Event
    {
        $effective = null;
        foreach ($ranges as $range) {
            if ($this->compareSlots($this->recurrenceId($range), $slot, $masterStart) <= 0) {
                $effective = $range;
            }
        }

        return $effective;
    }

    /** @param list<Event> $ranges */
    private function rangeOccurrence(
        Event $master,
        DateTimeValue $masterStart,
        array $ranges,
        Event $range,
        DateTimeImmutable $slot,
        ?Period $period = null,
    ): ?Occurrence {
        if ($range->isCancelled()) {
            return null;
        }

        return $this->materializedRangeOccurrence($master, $masterStart, $ranges, $range, $slot, $period);
    }

    /** @param list<Event> $ranges */
    private function materializedRangeOccurrence(
        Event $master,
        DateTimeValue $masterStart,
        array $ranges,
        Event $range,
        DateTimeImmutable $slot,
        ?Period $period = null,
        bool $ignoreSelectedCancellation = false,
    ): Occurrence {
        $shiftedStart = $this->shiftedRangeStart($slot, $masterStart, $range);
        $effectiveStart = $shiftedStart['instant'];
        $template = $master;
        $durationSource = $master;
        $periodTiming = $period;
        foreach ($ranges as $candidate) {
            if ($this->compareSlots($this->recurrenceId($candidate), $this->recurrenceId($range), $masterStart) > 0) {
                break;
            }
            if ($candidate->hasProperty('DURATION') || $candidate->hasProperty('DTEND')) {
                $durationSource = $candidate;
                $periodTiming = null;
            }
            // Non-temporal changes from an earlier range remain effective until
            // replaced. A non-cancelled STATUS is ordinary inherited state, while a
            // cancellation ends at the next explicitly supplied range under this
            // package's deterministic sparse-range policy.
            $template = $candidate === $range
                ? $this->mergedEvent(
                    $template,
                    $ignoreSelectedCancellation ? $this->withoutCancellationStatus($candidate) : $candidate,
                )
                : $this->mergedEvent($template, $this->withoutEarlierRangeTiming($candidate));
        }

        $isRangeOnset = $this->sameSlot($this->recurrenceId($range), $this->inMasterForm($slot, $masterStart), $masterStart);
        $effective = $this->withoutSeriesDefinition($template)->toBuilder()
            ->starts($shiftedStart['value']);
        if ($isRangeOnset) {
            // The onset is the original detached component's real slot, so its
            // complete RECURRENCE-ID (including IANA/X parameters) remains valid.
            // Rebuilding it from the typed value would silently discard metadata.
            $effective->recurrenceIdProperty(
                $range->properties->first('RECURRENCE-ID')
                    ?? throw new \LogicException('Expected a range RECURRENCE-ID property.'),
            );
        } else {
            // A later materialized instance is a single effective occurrence, not
            // another THISANDFUTURE directive. Do not propagate RANGE or unknown
            // slot-specific parameters onto the synthetic RECURRENCE-ID.
            $effective->recurrenceId($this->like($slot, $masterStart));
        }

        if ($periodTiming?->duration !== null) {
            $effective->lasting($periodTiming->duration);
        } elseif ($periodTiming?->end !== null) {
            $effectiveEnd = $this->endWithSameDerivedDuration(
                $effectiveStart,
                $periodTiming->start,
                $periodTiming->end,
            );
            $effective->ends($this->likeEnd($effectiveEnd, $periodTiming->end));
        } else {
            $duration = $this->optionalSingleDuration($durationSource);
            if ($duration !== null) {
                $effective->lasting($duration);
            } else {
                $sourceEnd = $this->optionalSingleDateTime($durationSource, 'DTEND');
                $sourceStart = $this->optionalSingleDateTime($durationSource, 'DTSTART')
                    ?? ($durationSource->hasProperty('RECURRENCE-ID') ? $this->recurrenceId($durationSource) : null);
                if ($sourceEnd !== null && $sourceStart !== null) {
                    $effectiveEnd = $this->endWithSameDerivedDuration($effectiveStart, $sourceStart, $sourceEnd);
                    $effective->ends($this->likeEnd($effectiveEnd, $sourceEnd));
                }
            }
        }
        $event = $this->withCoherentCustomDuration($effective->get(), $effectiveStart, $shiftedStart['value']);

        return new Occurrence(
            $effectiveStart,
            $slot,
            $event,
            true,
            $this->occurrenceEnd($effectiveStart, $event, $event->start()),
        );
    }

    /**
     * A sparse single override changes only the properties it supplies. Its
     * effective base is the active range state for that slot, including the
     * shifted start, inherited fields, and duration. Explicit single-instance
     * temporal properties still take precedence.
     *
     * @param  list<Event>  $ranges
     */
    private function singleOverRangeOccurrence(
        Event $master,
        DateTimeValue $masterStart,
        array $ranges,
        Event $range,
        Event $single,
        DateTimeImmutable $slot,
        ?Period $period = null,
    ): ?Occurrence {
        // A single active override has precedence even inside a cancelled range.
        // Suppress only the selected cancellation state while retaining any prior
        // non-cancelled STATUS and all other range-effective properties.
        if ($single->isCancelled()) {
            return null;
        }
        $inherited = $this->materializedRangeOccurrence(
            $master,
            $masterStart,
            $ranges,
            $range,
            $slot,
            $period,
            ignoreSelectedCancellation: true,
        );

        return $this->overlaySingleOccurrence($inherited, $masterStart, $single, $slot);
    }

    private function singleOverMasterOccurrence(
        Event $master,
        DateTimeValue $masterStart,
        Event $single,
        DateTimeImmutable $slot,
        ?Period $period = null,
    ): ?Occurrence {
        if ($single->isCancelled()) {
            return null;
        }

        if ($period !== null) {
            $inherited = $this->periodOccurrence($master, $masterStart, $period, $slot);
        } else {
            $effectiveStartValue = $this->like($slot, $masterStart);
            $builder = $this->withoutSeriesDefinition($master)->toBuilder()->starts($effectiveStartValue);
            $duration = $this->optionalSingleDuration($master);
            if ($duration !== null) {
                $builder->lasting($duration);
            } else {
                $end = $this->optionalSingleDateTime($master, 'DTEND');
                if ($end !== null) {
                    $effectiveEnd = $this->endWithSameDerivedDuration($effectiveStartValue->dateTime, $masterStart, $end);
                    $builder->ends($this->likeEnd($effectiveEnd, $end));
                }
            }

            $event = $this->withCoherentCustomDuration($builder->get(), $this->instant($effectiveStartValue), $effectiveStartValue);
            $inherited = new Occurrence(
                $this->instant($effectiveStartValue),
                $slot,
                $event,
                false,
                $this->occurrenceEnd($this->instant($effectiveStartValue), $event, $effectiveStartValue),
            );
        }

        return $this->overlaySingleOccurrence($inherited, $masterStart, $single, $slot);
    }

    private function overlaySingleOccurrence(
        Occurrence $inherited,
        DateTimeValue $masterStart,
        Event $single,
        DateTimeImmutable $slot,
    ): Occurrence {
        $event = $this->withoutSeriesDefinition($this->mergedEvent($inherited->event, $single));
        $builder = $event->toBuilder();

        $explicitStart = $this->optionalSingleDateTime($single, 'DTSTART');
        $inheritedStartValue = $inherited->event->start();
        $effectiveStart = $explicitStart !== null ? $this->instant($explicitStart) : $inherited->start;
        $builder->starts($explicitStart ?? $inheritedStartValue ?? $this->like($effectiveStart, $masterStart));

        $explicitDuration = $this->optionalSingleDuration($single);
        $explicitEnd = $this->optionalSingleDateTime($single, 'DTEND');
        if ($explicitDuration !== null) {
            $builder->lasting($explicitDuration);
        } elseif ($explicitEnd !== null) {
            $effectiveStartValue = $explicitStart ?? $inherited->event->start();
            if ($effectiveStartValue === null) {
                throw new UnsupportedRecurrenceException('Cannot resolve a single-instance DTEND without an effective DTSTART.');
            }
            $this->assertCompatibleForm($effectiveStartValue, $explicitEnd, 'DTEND must use a date/time form compatible with the effective DTSTART.');
            $this->assertEndAfterStart($effectiveStartValue, $explicitEnd);
            $builder->ends($explicitEnd);
        } elseif ($explicitStart !== null) {
            // Moving only this instance keeps the duration which was effective
            // under the range, rather than retaining an absolute inherited DTEND.
            $inheritedDuration = $inherited->event->duration();
            $inheritedEnd = $this->optionalSingleDateTime($inherited->event, 'DTEND');
            $inheritedStart = $this->optionalSingleDateTime($inherited->event, 'DTSTART');
            if ($inheritedDuration !== null) {
                $builder->lasting($inheritedDuration);
            } elseif ($inheritedEnd !== null && $inheritedStart !== null) {
                $effectiveEnd = $this->endWithSameDerivedDuration($effectiveStart, $inheritedStart, $inheritedEnd);
                $builder->ends($this->likeEnd($effectiveEnd, $inheritedEnd));
            }
        }

        $effectiveEvent = $builder->get();
        $effectiveEvent = $this->withCoherentCustomDuration(
            $effectiveEvent,
            $effectiveStart,
            $effectiveEvent->start() ?? $this->like($effectiveStart, $masterStart),
        );

        return new Occurrence(
            $effectiveStart,
            $slot,
            $effectiveEvent,
            true,
            $this->occurrenceEnd($effectiveStart, $effectiveEvent, $effectiveEvent->start()),
        );
    }

    private function mergedEvent(Event $master, Event $override): Event
    {
        $replacementNames = [];
        foreach ($override->properties as $property) {
            $replacementNames[$property->name] = true;
        }
        $properties = array_values(array_filter($master->properties->all(), static fn ($property): bool => ! isset($replacementNames[$property->name])));
        array_push($properties, ...$override->properties->all());

        // Sparse range/single overlays inherit child components when none are
        // supplied. Supplying children replaces the effective child set.
        $children = $override->children->isEmpty() ? $master->children : $override->children;

        return new Event(new PropertyBag(...$properties), $children);
    }

    private function withoutEarlierRangeTiming(Event $event): Event
    {
        $stateNames = ['DTSTART' => true, 'DTEND' => true, 'DURATION' => true, 'RECURRENCE-ID' => true];
        $properties = array_values(array_filter(
            $event->properties->all(),
            static fn ($property): bool => ! isset($stateNames[$property->name])
                && ! ($property->name === 'STATUS' && $event->isCancelled()),
        ));

        return new Event(new PropertyBag(...$properties), $event->children);
    }

    private function withoutCancellationStatus(Event $event): Event
    {
        if (! $event->isCancelled()) {
            return $event;
        }

        return new Event($event->properties->without('STATUS'), $event->children);
    }

    private function withoutSeriesDefinition(Event $event): Event
    {
        $seriesNames = ['RRULE' => true, 'RDATE' => true, 'EXDATE' => true];
        $properties = array_values(array_filter(
            $event->properties->all(),
            static fn ($property): bool => ! isset($seriesNames[$property->name]),
        ));

        return new Event(new PropertyBag(...$properties), $event->children);
    }

    private function detached(Event $override, DateTimeImmutable $from, DateTimeImmutable $to): ?Occurrence
    {
        $recurrenceId = $this->recurrenceId($override);
        $this->assertSparseSingleEndAfterEffectiveStart($override, $recurrenceId);
        $rid = $this->instant($recurrenceId);
        $startValue = $override->start();
        $start = $startValue !== null ? $this->instant($startValue) : $rid;
        if ($override->isCancelled() || $start < $from || $start > $to) {
            return null;
        }

        $effective = $startValue !== null
            ? $override
            : $override->toBuilder()->starts($recurrenceId)->get();
        $effective = $this->withCoherentCustomDuration(
            $effective,
            $start,
            $effective->start() ?? $recurrenceId,
        );

        return new Occurrence(
            $start,
            $rid,
            $effective,
            true,
            $this->occurrenceEnd($start, $effective, $effective->start()),
        );
    }

    /**
     * @return array<string, Period>
     */
    private function periodsBySlot(Event $event): array
    {
        $periods = [];
        foreach ($event->recurrenceDatePeriods() as $period) {
            if ($period->end !== null
                && $this->instant($period->end)->getTimestamp() <= $this->instant($period->start)->getTimestamp()) {
                throw new UnsupportedRecurrenceException(
                    'An explicit PERIOD end must resolve to an instant after its start.',
                );
            }
            $key = $this->slotKey($period->start);
            if (isset($periods[$key]) && $periods[$key]->toString() !== $period->toString()) {
                throw new UnsupportedRecurrenceException(
                    'Conflicting PERIOD-valued RDATEs cannot define different ends for the same recurrence slot.',
                );
            }
            $periods[$key] = $period;
        }

        return $periods;
    }

    private function periodOccurrence(
        Event $master,
        DateTimeValue $masterStart,
        Period $period,
        DateTimeImmutable $slot,
    ): Occurrence {
        $start = $this->like($slot, $period->start);
        $builder = $this->withoutSeriesDefinition($master)->toBuilder()->starts($start);
        if ($period->duration !== null) {
            $builder->lasting($period->duration);
        } else {
            $builder->ends($period->end ?? throw new \LogicException('Expected an explicit PERIOD end.'));
        }
        $event = $this->withCoherentCustomDuration($builder->get(), $this->instant($start), $start);

        return new Occurrence(
            $this->instant($start),
            $slot,
            $event,
            false,
            $this->occurrenceEnd($this->instant($start), $event, $start),
        );
    }

    private function occurrenceEnd(
        DateTimeImmutable $start,
        Event $event,
        ?DateTimeValue $sourceStart,
    ): ?DateTimeImmutable {
        if ($sourceStart === null) {
            return null;
        }

        $duration = $this->optionalSingleDuration($event);
        if ($duration !== null) {
            return $this->applyDuration($start, $sourceStart, $duration);
        }

        $end = $this->optionalSingleDateTime($event, 'DTEND');
        if ($end !== null) {
            return $this->endWithSameDerivedDuration($start, $sourceStart, $end);
        }

        return $sourceStart->isDateOnly ? $start->modify('+1 day') : $start;
    }

    private function withCoherentCustomDuration(
        Event $event,
        DateTimeImmutable $start,
        DateTimeValue $form,
    ): Event {
        $event = $this->withResolvedTemporalValues($event);
        $form = $event->start() ?? $form;
        $duration = $event->duration();
        if ($duration === null || $form->tzid === null || ! $this->isCustomTzid($form->tzid)) {
            return $event;
        }

        $end = $this->customDurationEndValue($start, $form, $duration);

        return $event->toBuilder()->ends($end)->get();
    }

    /** Resolve custom DTSTART/DTEND/RECURRENCE-ID backing while retaining property parameters. */
    private function withResolvedTemporalValues(Event $event): Event
    {
        $names = ['DTSTART' => true, 'DTEND' => true, 'RECURRENCE-ID' => true];
        $changed = false;
        $properties = [];
        foreach ($event->properties as $property) {
            if (! isset($names[$property->name])) {
                $properties[] = $property;

                continue;
            }
            $values = [];
            foreach ($property->values as $value) {
                if ($value instanceof DateTimeValue
                    && $value->tzid !== null
                    && $this->isCustomTzid($value->tzid)) {
                    $value = ($this->timeZones ?? throw new UnsupportedRecurrenceException(sprintf(
                        'Cannot resolve custom TZID "%s" without its VTIMEZONE.',
                        $value->tzid,
                    )))->resolveWall($value->tzid, $this->neutralLiteral($value));
                    $changed = true;
                }
                $values[] = $value;
            }
            $properties[] = new Property($property->name, $values, $property->parameters);
        }

        return $changed ? new Event(new PropertyBag(...$properties), $event->children) : $event;
    }

    /** Apply RFC duration components in context, including embedded VTIMEZONE DST. */
    private function applyDuration(
        DateTimeImmutable $start,
        DateTimeValue $form,
        Duration $duration,
    ): DateTimeImmutable {
        if ($form->tzid === null) {
            return $start->add($duration->toDateInterval());
        }

        if (! $this->isCustomTzid($form->tzid)) {
            $occurrenceStart = $form->dateTime->getTimestamp() === $start->getTimestamp()
                ? $form
                : $this->like($start, $form);

            return $occurrenceStart->adding($duration)->dateTime;
        }

        return $this->customDurationEndValue($start, $form, $duration)->dateTime;
    }

    private function customDurationEndValue(
        DateTimeImmutable $start,
        DateTimeValue $form,
        Duration $duration,
    ): DateTimeValue {
        $tzid = $form->tzid
            ?? throw new \LogicException('Expected a custom-zoned duration start.');

        $resolver = $this->timeZones
            ?? throw new UnsupportedRecurrenceException(sprintf('Cannot apply a duration in custom TZID "%s" without its VTIMEZONE.', $tzid));
        $occurrenceStart = $resolver->instant($form)->getTimestamp() === $start->getTimestamp()
            ? $form
            : $resolver->valueAtInstant($start, $tzid);

        return $resolver->addDuration($occurrenceStart, $duration);
    }

    private function inMasterForm(DateTimeImmutable $date, ?DateTimeValue $masterStart): DateTimeValue
    {
        return $this->like($date, $masterStart);
    }

    /** Convert an instant/wall value into a coherent DateTimeValue of $form. */
    private function like(DateTimeImmutable $date, ?DateTimeValue $form): DateTimeValue
    {
        if ($form?->isDateOnly) {
            return DateTimeValue::date($this->neutralWall($date));
        }
        if ($form?->isUtc) {
            return DateTimeValue::utc($date);
        }
        if ($form?->tzid !== null) {
            if ($this->timeZones?->usesEmbedded($form->tzid)) {
                return $this->timeZones->valueAtInstant($date, $form->tzid);
            }

            try {
                $zone = new DateTimeZone($form->tzid);
            } catch (\DateInvalidTimeZoneException) {
                return ($this->timeZones ?? throw new UnsupportedRecurrenceException(sprintf(
                    'Cannot materialize recurrence arithmetic for custom TZID "%s" without its VTIMEZONE.',
                    $form->tzid,
                )))->valueAtInstant($date, $form->tzid);
            }

            $value = DateTimeValue::zoned($date->setTimezone($zone), $form->tzid);
            if ($value->dateTime->getTimestamp() !== $date->getTimestamp()) {
                throw new UnsupportedRecurrenceException(sprintf(
                    'Cannot materialize instant %s in TZID "%s" without changing it at an ambiguous local-time fold.',
                    $date->format(DATE_ATOM),
                    $form->tzid,
                ));
            }

            return $value;
        }

        return DateTimeValue::floating($this->neutralWall($date));
    }

    /** Materialize an end while retaining an otherwise-unaddressable fold instant in UTC. */
    private function likeEnd(DateTimeImmutable $date, DateTimeValue $form): DateTimeValue
    {
        if ($form->tzid === null) {
            return $this->like($date, $form);
        }

        if ($this->isCustomTzid($form->tzid)) {
            $resolver = $this->timeZones
                ?? throw new UnsupportedRecurrenceException(sprintf(
                    'Cannot materialize an end in custom TZID "%s" without its VTIMEZONE.',
                    $form->tzid,
                ));
            $wall = $resolver->wallAtInstant($date, $form->tzid);
            $first = $resolver->resolveWall($form->tzid, $wall);

            return $first->dateTime->getTimestamp() === $date->getTimestamp()
                ? $first
                : DateTimeValue::utc($date);
        }

        $zone = new DateTimeZone($form->tzid);
        $candidate = DateTimeValue::zoned($date->setTimezone($zone), $form->tzid);

        return $candidate->dateTime->getTimestamp() === $date->getTimestamp()
            ? $candidate
            : DateTimeValue::utc($date);
    }

    /**
     * DATE and floating DATE-TIME identify slots by their literal wall value;
     * zoned and UTC DATE-TIME identify the corresponding instant.
     */
    private function slotKey(DateTimeValue $date): string
    {
        return match (true) {
            $date->isDateOnly => 'D:'.$date->toString(),
            $date->isFloating() => 'F:'.$date->toString(),
            default => 'I:'.$this->instant($date)->getTimestamp(),
        };
    }

    private function compareSlots(DateTimeValue $left, DateTimeValue $right, DateTimeValue $masterStart): int
    {
        if ($masterStart->isDateOnly || $masterStart->isFloating()) {
            return strcmp($left->toString(), $right->toString());
        }

        return $this->instant($left)->getTimestamp() <=> $this->instant($right)->getTimestamp();
    }

    private function sameSlot(DateTimeValue $left, DateTimeValue $right, DateTimeValue $masterStart): bool
    {
        return $this->compareSlots($left, $right, $masterStart) === 0;
    }

    private function optionalRecurrenceId(Event $event): ?DateTimeValue
    {
        $properties = $event->properties->all('RECURRENCE-ID');
        if ($properties === []) {
            return null;
        }
        if (count($properties) !== 1 || count($properties[0]->values) !== 1 || ! $properties[0]->value() instanceof DateTimeValue) {
            throw new UnsupportedRecurrenceException('Cannot safely resolve duplicate, multi-valued, or untyped RECURRENCE-ID properties.');
        }

        $rangeParameter = $properties[0]->parameter('RANGE');
        if ($rangeParameter !== null) {
            $validRaw = $rangeParameter instanceof RawParameter
                && count($rangeParameter->values) === 1
                && strtoupper($rangeParameter->value()) === Range::ThisAndFuture->value;
            if (! $rangeParameter instanceof Range && ! $validRaw) {
                throw new UnsupportedRecurrenceException('Cannot safely resolve an unsupported RECURRENCE-ID RANGE parameter.');
            }
        }

        /** @var DateTimeValue */
        return $properties[0]->value();
    }

    private function recurrenceId(Event $event): DateTimeValue
    {
        return $this->optionalRecurrenceId($event)
            ?? throw new \LogicException('Expected a detached recurrence event.');
    }

    private function assertOverrideTemporalProperties(Event $event, ?DateTimeValue $masterStart): void
    {
        foreach (['RRULE', 'RDATE', 'EXDATE'] as $name) {
            if ($event->properties->all($name) !== []) {
                throw new UnsupportedRecurrenceException(sprintf(
                    'Cannot safely apply detached recurrence data: RECURRENCE-ID with %s is unsupported.',
                    $name,
                ));
            }
        }

        $rid = $this->recurrenceId($event);
        $start = $this->optionalSingleDateTime($event, 'DTSTART');
        $end = $this->optionalSingleDateTime($event, 'DTEND');
        $duration = $this->optionalSingleDuration($event);

        if ($end !== null && $duration !== null) {
            throw new UnsupportedRecurrenceException('A detached VEVENT cannot safely contain both DTEND and DURATION.');
        }
        if ($duration !== null && ($duration->negative || $duration->isZero())) {
            throw new UnsupportedRecurrenceException('A detached VEVENT DURATION must be positive for safe expansion.');
        }
        if ($duration !== null) {
            $this->assertDurationCompatibleWithStart($duration, $start ?? $rid);
        }
        foreach (array_filter([$rid, $start, $end]) as $value) {
            $this->assertResolvableZonedInstant($value);
        }
        if ($event->recurrenceRange() === Range::ThisAndFuture) {
            foreach (array_filter([$rid, $start, $end]) as $value) {
                $this->assertZonedValueSupportsRangeArithmetic($value);
            }
        }

        if ($masterStart !== null) {
            $this->assertCompatibleForm($masterStart, $rid, 'RECURRENCE-ID must use a recurrence form compatible with the recurrence master DTSTART.');
            if ($start !== null) {
                $this->assertCompatibleForm($masterStart, $start, 'A detached DTSTART must use a recurrence form compatible with the recurrence master DTSTART.');
            }
        }
        $effectiveStart = $start ?? $rid;
        if ($end !== null) {
            $this->assertCompatibleForm($effectiveStart, $end, 'DTEND must use a date/time form compatible with the detached DTSTART.');
            // A sparse single override inside a range inherits that range's
            // effective DTSTART, which may be before or after RECURRENCE-ID.
            // Validate its DTEND once that effective start is known.
            if ($start !== null || $event->recurrenceRange() === Range::ThisAndFuture) {
                $this->assertEndAfterStart($effectiveStart, $end);
            }
        }
    }

    private function assertSparseSingleEndAfterEffectiveStart(Event $event, DateTimeValue $effectiveStart): void
    {
        if ($event->recurrenceRange() !== null || $event->start() !== null) {
            return;
        }

        $end = $this->optionalSingleDateTime($event, 'DTEND');
        if ($end !== null) {
            $this->assertEndAfterStart($effectiveStart, $end);
        }
    }

    private function assertMasterTemporalProperties(Event $master, DateTimeValue $start): void
    {
        $end = $this->optionalSingleDateTime($master, 'DTEND');
        $duration = $this->optionalSingleDuration($master);
        $this->assertResolvableZonedInstant($start);
        if ($end !== null) {
            $this->assertResolvableZonedInstant($end);
        }
        if ($end !== null && $duration !== null) {
            throw new UnsupportedRecurrenceException('A recurrence master cannot safely contain both DTEND and DURATION.');
        }
        if ($duration !== null && ($duration->negative || $duration->isZero())) {
            throw new UnsupportedRecurrenceException('A recurrence master DURATION must be positive for safe expansion.');
        }
        if ($duration !== null) {
            $this->assertDurationCompatibleWithStart($duration, $start);
        }
        if ($end !== null) {
            $this->assertCompatibleForm($start, $end, 'DTEND must use a date/time form compatible with DTSTART.');
            $this->assertEndAfterStart($start, $end);
        }
    }

    private function optionalSingleDateTime(Event $event, string $name): ?DateTimeValue
    {
        $properties = $event->properties->all($name);
        if ($properties === []) {
            return null;
        }
        if (count($properties) !== 1 || count($properties[0]->values) !== 1 || ! $properties[0]->value() instanceof DateTimeValue) {
            throw new UnsupportedRecurrenceException(sprintf('Cannot safely expand duplicate, multi-valued, or untyped %s properties.', $name));
        }

        /** @var DateTimeValue */
        return $properties[0]->value();
    }

    private function optionalSingleDuration(Event $event): ?Duration
    {
        $properties = $event->properties->all('DURATION');
        if ($properties === []) {
            return null;
        }
        if (count($properties) !== 1 || count($properties[0]->values) !== 1 || ! $properties[0]->value() instanceof Duration) {
            throw new UnsupportedRecurrenceException('Cannot safely expand duplicate, multi-valued, or untyped DURATION properties.');
        }

        /** @var Duration */
        return $properties[0]->value();
    }

    private function assertCompatibleForm(DateTimeValue $reference, DateTimeValue $candidate, string $message): void
    {
        if ($reference->isDateOnly !== $candidate->isDateOnly
            || (! $reference->isDateOnly && $reference->isFloating() !== $candidate->isFloating())) {
            throw new UnsupportedRecurrenceException($message);
        }
    }

    private function assertEndAfterStart(DateTimeValue $start, DateTimeValue $end): void
    {
        $comparison = ($start->isDateOnly || $start->isFloating())
            ? strcmp($end->toString(), $start->toString())
            : $this->instant($end)->getTimestamp() <=> $this->instant($start)->getTimestamp();
        if ($comparison <= 0) {
            throw new UnsupportedRecurrenceException('DTEND must occur after DTSTART for safe recurrence expansion.');
        }
    }

    private function assertDurationCompatibleWithStart(Duration $duration, DateTimeValue $start): void
    {
        if ($start->isDateOnly && ($duration->hours !== 0 || $duration->minutes !== 0 || $duration->seconds !== 0)) {
            throw new UnsupportedRecurrenceException(
                'A DATE-valued VEVENT requires a day- or week-based DURATION for safe recurrence expansion.',
            );
        }
    }

    private function assertResolvableZonedInstant(DateTimeValue $value): void
    {
        if ($value->tzid === null) {
            return;
        }
        if ($this->timeZones?->usesEmbedded($value->tzid)) {
            $this->timeZones->instant($value);

            return;
        }

        try {
            $zone = new DateTimeZone($value->tzid);
        } catch (\DateInvalidTimeZoneException) {
            if ($this->timeZones?->canResolve($value->tzid)) {
                $this->timeZones->instant($value);

                return;
            }

            throw new UnsupportedRecurrenceException(sprintf('Cannot safely resolve recurrence timing for custom TZID "%s" without its VTIMEZONE.', $value->tzid));
        }

        if ($value->dateTime->getTimezone()->getName() !== $zone->getName()) {
            throw new UnsupportedRecurrenceException(sprintf(
                'Cannot safely resolve recurrence timing without coherent instant backing for TZID "%s".',
                $value->tzid,
            ));
        }
    }

    private function assertZonedValueSupportsRangeArithmetic(DateTimeValue $value): void
    {
        if ($value->tzid === null) {
            return;
        }
        if ($this->timeZones?->usesEmbedded($value->tzid)) {
            $instant = $this->timeZones->instant($value);
            if ($this->timeZones->wallAtInstant($instant, $value->tzid)->format('Ymd\THis') !== $value->toString()) {
                throw new UnsupportedRecurrenceException(sprintf(
                    'Cannot safely apply range arithmetic to a nonexistent local wall time in embedded TZID "%s".',
                    $value->tzid,
                ));
            }

            return;
        }

        try {
            $zone = new DateTimeZone($value->tzid);
        } catch (\DateInvalidTimeZoneException) {
            $resolver = $this->timeZones
                ?? throw new UnsupportedRecurrenceException(sprintf('Cannot apply range arithmetic for custom TZID "%s" without its VTIMEZONE.', $value->tzid));
            $instant = $resolver->instant($value);
            if ($resolver->wallAtInstant($instant, $value->tzid)->format('Ymd\THis') !== $value->toString()) {
                throw new UnsupportedRecurrenceException(sprintf(
                    'Cannot safely apply range arithmetic to a nonexistent local wall time in custom TZID "%s".',
                    $value->tzid,
                ));
            }

            return;
        }

        if ($value->dateTime->getTimezone()->getName() !== $zone->getName()
            || $value->dateTime->format('Ymd\THis') !== $value->toString()) {
            throw new UnsupportedRecurrenceException(sprintf(
                'Cannot safely apply range arithmetic to the unresolved local wall time in TZID "%s".',
                $value->tzid,
            ));
        }
    }

    private function assertRangeSlotExists(Event $master, DateTimeValue $masterStart, Event $range): void
    {
        $rid = $this->recurrenceId($range);
        $target = $this->slotInMasterBacking($rid, $masterStart);
        foreach ($this->expander->between($master, $target, $target) as $slot) {
            if ($this->sameSlot($rid, $this->inMasterForm($slot, $masterStart), $masterStart)) {
                return;
            }
        }

        throw new UnsupportedRecurrenceException('RANGE=THISANDFUTURE RECURRENCE-ID does not identify an occurrence in the recurrence master.');
    }

    /** Put a DATE/floating slot into the master backing zone without changing its wall fields. */
    private function slotInMasterBacking(DateTimeValue $slot, DateTimeValue $masterStart): DateTimeImmutable
    {
        if (! $masterStart->isDateOnly && ! $masterStart->isFloating()) {
            return $this->instant($slot);
        }

        return new DateTimeImmutable(
            $slot->dateTime->format('Y-m-d H:i:s'),
            $masterStart->dateTime->getTimezone(),
        );
    }

    /**
     * Apply DTSTART movement in the recurrence master's wall-clock coordinate
     * system while retaining an RFC gap literal when the shifted result itself
     * is a nonexistent local time.
     *
     * @return array{instant: DateTimeImmutable, value: DateTimeValue}
     */
    private function shiftedRangeStart(DateTimeImmutable $slot, DateTimeValue $masterStart, Event $range): array
    {
        $newStart = $range->start();
        if ($newStart === null) {
            $value = $this->like($slot, $masterStart);

            return ['instant' => $value->dateTime, 'value' => $value];
        }

        $rid = $this->recurrenceId($range);
        if ($masterStart->tzid !== null && $this->isCustomTzid($masterStart->tzid)) {
            $resolver = $this->timeZones
                ?? throw new UnsupportedRecurrenceException(sprintf('Cannot apply range arithmetic for custom TZID "%s" without its VTIMEZONE.', $masterStart->tzid));
            $ridWall = $rid->tzid === $masterStart->tzid
                ? $this->neutralLiteral($rid)
                : $resolver->wallAtInstant($this->instant($rid), $masterStart->tzid);
            $newWall = $newStart->tzid === $masterStart->tzid
                ? $this->neutralLiteral($newStart)
                : $resolver->wallAtInstant($this->instant($newStart), $masterStart->tzid);
            $slotWall = $resolver->wallAtInstant($slot, $masterStart->tzid);
            $wallDelta = $newWall->getTimestamp() - $ridWall->getTimestamp();
            $shiftedWall = $slotWall->setTimestamp($slotWall->getTimestamp() + $wallDelta);
            $seriesValue = $resolver->resolveWall($masterStart->tzid, $shiftedWall);
            $value = $newStart->tzid === $masterStart->tzid
                ? $seriesValue
                : $this->like($seriesValue->dateTime, $newStart);

            return ['instant' => $this->instant($value), 'value' => $value];
        }

        $seriesZone = $masterStart->dateTime->getTimezone();
        $ridWall = ($masterStart->isDateOnly || $masterStart->isFloating())
            ? $this->neutralWall($rid->dateTime)
            : $this->neutralWall($rid->dateTime->setTimezone($seriesZone));
        $newWall = ($masterStart->isDateOnly || $masterStart->isFloating())
            ? $this->neutralWall($newStart->dateTime)
            : $this->neutralWall($newStart->dateTime->setTimezone($seriesZone));
        $slotWall = $this->neutralWall($slot);
        // RANGE propagates the same time difference, not a reusable calendar
        // interval. DateTimeImmutable::diff() may encode Jan 1 -> Feb 1 as P1M,
        // which would incorrectly move a Feb 1 slot to Mar 1 instead of adding
        // the original 31-day wall-clock delta.
        $wallDelta = $newWall->getTimestamp() - $ridWall->getTimestamp();
        $shiftedWall = $slotWall->setTimestamp($slotWall->getTimestamp() + $wallDelta);
        $seriesValue = match (true) {
            $masterStart->isDateOnly => DateTimeValue::date($shiftedWall),
            $masterStart->isFloating() => DateTimeValue::floating($shiftedWall),
            $masterStart->isUtc => DateTimeValue::utc($shiftedWall),
            default => DateTimeValue::zoned($shiftedWall, $masterStart->tzid ?? throw new \LogicException('Expected a zoned recurrence master.')),
        };
        $rangeStartForm = $newStart;
        $value = $rangeStartForm->tzid !== null && $rangeStartForm->tzid === $masterStart->tzid
            ? DateTimeValue::zoned($shiftedWall, $rangeStartForm->tzid)
            : $this->like($seriesValue->dateTime, $rangeStartForm);

        return ['instant' => $value->dateTime, 'value' => $value];
    }

    /** Propagate the duration implied by a DTSTART/DTEND pair without DST-fold drift. */
    private function endWithSameDerivedDuration(
        DateTimeImmutable $effectiveStart,
        DateTimeValue $sourceStart,
        DateTimeValue $sourceEnd,
    ): DateTimeImmutable {
        if (! $sourceStart->isDateOnly && ! $sourceStart->isFloating()) {
            $seconds = $this->instant($sourceEnd)->getTimestamp() - $this->instant($sourceStart)->getTimestamp();

            return $effectiveStart->setTimestamp($effectiveStart->getTimestamp() + $seconds);
        }

        $sourceStartWall = $this->neutralWall($sourceStart->dateTime);
        $sourceEndWall = $this->neutralWall($sourceEnd->dateTime);
        $wallDuration = $sourceEndWall->getTimestamp() - $sourceStartWall->getTimestamp();
        $effectiveWall = $this->neutralWall($effectiveStart);
        $effectiveWall = $effectiveWall->setTimestamp($effectiveWall->getTimestamp() + $wallDuration);

        return new DateTimeImmutable($effectiveWall->format('Y-m-d H:i:s'), $effectiveStart->getTimezone());
    }

    private function neutralWall(DateTimeImmutable $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    private function instant(DateTimeValue $value): DateTimeImmutable
    {
        if ($value->tzid === null || ! $this->isCustomTzid($value->tzid)) {
            return $value->dateTime;
        }

        return ($this->timeZones ?? throw new UnsupportedRecurrenceException(sprintf(
            'Cannot resolve custom TZID "%s" without its VTIMEZONE.',
            $value->tzid,
        )))->instant($value);
    }

    private function isCustomTzid(string $tzid): bool
    {
        if ($this->timeZones?->usesEmbedded($tzid)) {
            return true;
        }

        try {
            new DateTimeZone($tzid);

            return false;
        } catch (\DateInvalidTimeZoneException) {
            return true;
        }
    }

    private function neutralLiteral(DateTimeValue $value): DateTimeImmutable
    {
        $wall = DateTimeImmutable::createFromFormat('!Ymd\THis', $value->toString(), new DateTimeZone('UTC'));
        if ($wall === false) {
            throw new UnsupportedRecurrenceException('Could not represent a custom-TZID wall-clock value.');
        }

        return $wall;
    }
}
