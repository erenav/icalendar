<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Recurrence;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\TimeZone\TimeZoneResolver;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Period;
use RRule\RRule;
use RRule\RSet;

/**
 * Default {@see RecurrenceExpander}, backed by `rlanvin/php-rrule`.
 *
 * Builds an RSet from the event's DTSTART, RRULE, DATE/DATE-TIME/PERIOD RDATE
 * and EXDATE, then asks it for occurrence starts in the window. Calendar-level
 * expansion attaches each PERIOD's own duration/end to the effective event.
 * Resolvable IANA or embedded-VTIMEZONE recurrences retain ordinary wall-clock
 * behavior across DST; see the README for explicit gap limitations.
 */
final class RlanvinRecurrenceExpander implements RecurrenceExpander
{
    public function __construct(
        private readonly ?TimeZoneResolver $timeZones = null,
    ) {}

    public function withTimeZoneResolver(TimeZoneResolver $resolver): self
    {
        return new self($resolver);
    }

    public function between(Event $event, DateTimeInterface $from, DateTimeInterface $to): array
    {
        if ($event->properties->all('RECURRENCE-ID') !== []) {
            foreach (['RRULE', 'RDATE', 'EXDATE'] as $name) {
                if ($event->properties->all($name) !== []) {
                    throw new UnsupportedRecurrenceException(sprintf(
                        'Cannot safely expand a detached RECURRENCE-ID component carrying %s.',
                        $name,
                    ));
                }
            }
        }

        $start = $this->optionalStart($event);
        $this->assertRecurrencePropertyShapesSupported($event);
        if ($start === null) {
            foreach (['RRULE', 'RDATE', 'EXDATE'] as $name) {
                if ($event->properties->all($name) !== []) {
                    throw new UnsupportedRecurrenceException(sprintf(
                        'Cannot safely expand %s without a typed DTSTART.',
                        $name,
                    ));
                }
            }

            return [];
        }
        $this->assertResolvableZonedInstant($start, 'DTSTART');

        $this->assertRecurrenceFormsSupported($event, $start);

        $customTzid = $this->customTzid($start);
        $dtstart = $customTzid !== null ? $this->neutralLiteral($start) : $start->dateTime;
        $fromInstant = DateTimeImmutable::createFromInterface($from);
        $toInstant = DateTimeImmutable::createFromInterface($to);
        $searchFrom = $customTzid !== null
            ? (new DateTimeImmutable('@'.($fromInstant->getTimestamp() - 86400)))->setTimezone(new DateTimeZone('UTC'))
            : $fromInstant;
        $searchTo = $customTzid !== null
            ? (new DateTimeImmutable('@'.($toInstant->getTimestamp() + 86400)))->setTimezone(new DateTimeZone('UTC'))
            : $toInstant;

        $rrule = $event->recurrenceRule();
        if ($rrule !== null && $rrule->unknownParts !== []) {
            throw new UnsupportedRecurrenceException('Cannot safely expand an RRULE containing unsupported parts: '.implode(', ', array_map(static fn (RecurrencePart $part): string => $part->name, $rrule->unknownParts)));
        }
        if ($rrule?->until !== null) {
            $this->assertUntilMatchesStart($rrule->until, $start);
        }
        if ($rrule !== null && in_array(60, $rrule->bySecond, true)) {
            throw new UnsupportedRecurrenceException('BYSECOND=60 requires leap-second semantics that PHP and the default recurrence engine cannot represent safely.');
        }
        if ($rrule !== null && $start->isDateOnly
            && (in_array($rrule->frequency, [Frequency::Secondly, Frequency::Minutely, Frequency::Hourly], true)
                || $rrule->bySecond !== [] || $rrule->byMinute !== [] || $rrule->byHour !== [])) {
            throw new UnsupportedRecurrenceException('A DATE-valued DTSTART cannot be safely expanded with sub-daily frequency or BYSECOND/BYMINUTE/BYHOUR.');
        }
        $recurrenceDates = $event->recurrenceDates();
        $recurrencePeriods = $event->recurrenceDatePeriods();
        $exceptionDates = $event->exceptionDates();

        // Non-recurring event: a single occurrence at DTSTART, if it's in range.
        if ($rrule === null && $recurrenceDates === [] && $recurrencePeriods === [] && $exceptionDates === []) {
            $singleStart = $customTzid !== null
                ? ($this->timeZones ?? throw new UnsupportedRecurrenceException('Custom TZID expansion requires a calendar timezone resolver.'))->instant($start)
                : $dtstart;

            return ($singleStart >= $fromInstant && $singleStart <= $toInstant) ? [$singleStart] : [];
        }

        if ($rrule !== null) {
            $this->assertZonedStartSupportsArithmetic($start);
            $this->assertStartIsSynchronized($rrule, $dtstart, $customTzid === null);
            $this->assertNoGeneratedNonexistentLocalTimes($rrule, $start, $toInstant);
        }

        $set = new RSet;

        if ($rrule !== null) {
            $set->addRRule(new RRule(RRuleAdapter::options($rrule, $dtstart, $customTzid === null)));
        } else {
            // RDATE-only: DTSTART is itself an occurrence per RFC 5545 §3.8.5.2.
            $set->addDate($dtstart);
        }

        $explicitCustomWalls = [];
        foreach ($recurrenceDates as $date) {
            $backing = $this->recurrenceBacking($date, $customTzid);
            $set->addDate($backing);
            $explicitCustomWalls[$backing->format('Ymd\THis')] = true;
        }
        foreach ($recurrencePeriods as $period) {
            $backing = $this->recurrenceBacking($period->start, $customTzid);
            $set->addDate($backing);
            $explicitCustomWalls[$backing->format('Ymd\THis')] = true;
        }
        foreach ($exceptionDates as $date) {
            $set->addExDate($this->recurrenceBacking($date, $customTzid));
        }

        $occurrences = [];
        foreach ($set->getOccurrencesBetween($searchFrom, $searchTo) as $occurrence) {
            if ($occurrence instanceof DateTimeInterface) {
                $candidate = DateTimeImmutable::createFromInterface($occurrence);
                if ($customTzid !== null) {
                    $key = $candidate->format('Ymd\THis');
                    $candidate = ($this->timeZones ?? throw new UnsupportedRecurrenceException('Custom TZID expansion requires a calendar timezone resolver.'))
                        ->resolveWall($customTzid, $candidate)
                        ->dateTime;
                    if ($rrule?->until !== null
                        && ! isset($explicitCustomWalls[$key])
                        && $candidate > $rrule->until->dateTime) {
                        continue;
                    }
                    if ($candidate < $fromInstant || $candidate > $toInstant) {
                        continue;
                    }
                }
                $occurrences[] = $candidate;
            }
        }

        return $occurrences;
    }

    private function assertStartIsSynchronized(
        Recurrence $rule,
        DateTimeImmutable $start,
        bool $includeUntil = true,
    ): void {
        $nativeRule = new RRule(RRuleAdapter::options($rule, $start, $includeUntil));
        foreach ($nativeRule->getOccurrencesBetween($start, $start) as $occurrence) {
            if ($occurrence instanceof DateTimeInterface
                && $occurrence->getTimestamp() === $start->getTimestamp()) {
                return;
            }
        }

        throw new UnsupportedRecurrenceException(
            'DTSTART is not synchronized with RRULE; RFC 5545 leaves that recurrence set undefined.',
        );
    }

    private function assertZonedStartSupportsArithmetic(DateTimeValue $start): void
    {
        if ($start->tzid === null) {
            return;
        }
        if ($this->timeZones?->usesEmbedded($start->tzid)) {
            $this->timeZones->instant($start);

            return;
        }

        try {
            $zone = new DateTimeZone($start->tzid);
        } catch (DateInvalidTimeZoneException) {
            if ($this->timeZones?->canResolve($start->tzid)) {
                return;
            }

            throw new UnsupportedRecurrenceException(sprintf('Cannot expand recurrence arithmetic for custom TZID "%s" without its VTIMEZONE.', $start->tzid));
        }

        if ($start->dateTime->getTimezone()->getName() !== $zone->getName()
            || $start->dateTime->format('Ymd\THis') !== $start->toString()) {
            throw new UnsupportedRecurrenceException(sprintf(
                'Cannot safely recur DTSTART;TZID=%s because its native backing value cannot represent that wall time in the IANA zone.',
                $start->tzid,
            ));
        }
    }

    private function assertResolvableZonedInstant(DateTimeValue $value, string $property): void
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
        } catch (DateInvalidTimeZoneException) {
            if ($this->timeZones?->canResolve($value->tzid)) {
                $this->timeZones->instant($value);

                return;
            }

            throw new UnsupportedRecurrenceException(sprintf('Cannot safely expand %s with unresolved custom TZID "%s".', $property, $value->tzid));
        }

        if ($value->dateTime->getTimezone()->getName() !== $zone->getName()) {
            throw new UnsupportedRecurrenceException(sprintf(
                'Cannot safely expand %s because its TZID "%s" has no coherent instant backing.',
                $property,
                $value->tzid,
            ));
        }
    }

    private function optionalStart(Event $event): ?DateTimeValue
    {
        $starts = $event->properties->all('DTSTART');
        if ($starts === []) {
            return null;
        }
        if (count($starts) !== 1 || count($starts[0]->values) !== 1 || ! $starts[0]->value() instanceof DateTimeValue) {
            throw new UnsupportedRecurrenceException('Cannot safely expand duplicate, multi-valued, or untyped DTSTART properties.');
        }

        /** @var DateTimeValue */
        return $starts[0]->value();
    }

    /**
     * php-rrule/PHP normalizes a generated wall time inside a forward DST gap
     * and counts it. RFC 5545 requires that instance to be ignored and not
     * counted. Detect that case before expansion so callers never receive a
     * silently altered recurrence set.
     */
    private function assertNoGeneratedNonexistentLocalTimes(
        Recurrence $rule,
        DateTimeValue $start,
        DateTimeImmutable $through,
    ): void {
        if ($start->tzid === null) {
            return;
        }
        if ($this->timeZones?->usesEmbedded($start->tzid)) {
            if ($through < $this->timeZones->instant($start)) {
                return;
            }
            $this->assertNoCustomGeneratedNonexistentLocalTimes($rule, $start, $through);

            return;
        }
        if ($through < $start->dateTime) {
            return;
        }

        try {
            $zone = new DateTimeZone($start->tzid);
        } catch (DateInvalidTimeZoneException) {
            $this->assertNoCustomGeneratedNonexistentLocalTimes($rule, $start, $through);

            return;
        }

        $scanThrough = $through;
        if ($rule->until !== null && $rule->until->dateTime < $scanThrough) {
            $scanThrough = $rule->until->dateTime;
        }
        if ($scanThrough < $start->dateTime) {
            return;
        }

        // BYSETPOS selects only after the other BY parts have produced every
        // candidate in one FREQ interval. A gap candidate later than the query
        // bound or UNTIL can therefore still change an earlier selected value
        // in that same interval. Inspect through the interval boundary.
        $inspectionThrough = $rule->bySetPosition !== []
            ? $this->frequencyPeriodEnd($scanThrough, $zone, $rule)
            : $scanThrough;
        $transitions = $zone->getTransitions($start->dateTime->getTimestamp(), $inspectionThrough->getTimestamp() + 1);
        if (! $transitions || count($transitions) < 2) {
            return;
        }

        $neutralStart = DateTimeImmutable::createFromFormat(
            '!Ymd\THis',
            $start->toString(),
            new DateTimeZone('UTC'),
        );
        if ($neutralStart === false) {
            throw new UnsupportedRecurrenceException('Cannot inspect zoned recurrence wall-clock arithmetic safely.');
        }
        $parts = $this->ruleParts($rule, $neutralStart);
        unset($parts['UNTIL']);
        $neutralRule = new RRule($parts);
        $candidateParts = $parts;
        // Invalid local times must be removed before BYSETPOS selects from an
        // interval's candidate set. Inspect that underlying set directly.
        unset($candidateParts['COUNT'], $candidateParts['BYSETPOS']);
        $candidateRule = new RRule($candidateParts);

        $previousOffset = $transitions[0]['offset'];
        foreach (array_slice($transitions, 1) as $transition) {
            $newOffset = $transition['offset'];
            if ($newOffset <= $previousOffset) {
                $previousOffset = $newOffset;

                continue;
            }

            $gapStart = (new DateTimeImmutable('@'.($transition['ts'] + $previousOffset)))
                ->setTimezone(new DateTimeZone('UTC'));
            $gapEnd = (new DateTimeImmutable('@'.($transition['ts'] + $newOffset - 1)))
                ->setTimezone(new DateTimeZone('UTC'));
            $gapCandidates = $candidateRule->getOccurrencesBetween($gapStart, $gapEnd);
            if ($gapCandidates !== [] && $rule->bySetPosition !== []) {
                // COUNT may appear complete before the wall-clock gap while its
                // final selected occurrence is still dependent on a later gap
                // candidate in the same FREQ interval. Ask the selected rule for
                // that interval instead of treating count completion as safe.
                if (! $this->selectedPeriodCanAffectThrough(
                    $parts,
                    $rule,
                    $gapStart,
                    $scanThrough,
                    $start->tzid,
                )) {
                    $previousOffset = $newOffset;

                    continue;
                }
            } elseif ($gapCandidates !== [] && $rule->count !== null) {
                $selectedBeforeGap = $neutralRule->getOccurrencesBetween(null, $gapStart->modify('-1 second'));
                if (count($selectedBeforeGap) >= $rule->count) {
                    $previousOffset = $newOffset;

                    continue;
                }
            }
            foreach ($gapCandidates as $nominal) {
                if (! $nominal instanceof DateTimeInterface) {
                    continue;
                }
                $resolved = DateTimeValue::zoned($nominal, $start->tzid);
                // For BYSETPOS, selectedPeriodCanAffectThrough() has already
                // established that this candidate changes an occurrence within
                // the effective query/UNTIL horizon. Its own resolved instant may
                // be later than that selected occurrence, so do not discard it.
                if ($rule->bySetPosition === [] && $rule->until !== null && $resolved->dateTime > $rule->until->dateTime) {
                    continue;
                }

                throw new UnsupportedRecurrenceException(sprintf(
                    'Cannot safely expand an RRULE that generates the nonexistent local time %s in TZID "%s"; RFC 5545 requires gap instances to be ignored without counting them.',
                    $resolved->toString(),
                    $start->tzid,
                ));
            }

            $previousOffset = $newOffset;
        }
    }

    private function assertNoCustomGeneratedNonexistentLocalTimes(
        Recurrence $rule,
        DateTimeValue $start,
        DateTimeImmutable $through,
    ): void {
        $tzid = $start->tzid ?? throw new \LogicException('Expected a custom TZID.');
        $resolver = $this->timeZones
            ?? throw new UnsupportedRecurrenceException(sprintf('Cannot inspect custom TZID "%s" without its VTIMEZONE.', $tzid));
        if (! $resolver->canResolve($tzid)) {
            throw new UnsupportedRecurrenceException(sprintf('Cannot inspect custom TZID "%s" without a usable VTIMEZONE.', $tzid));
        }

        $startInstant = $resolver->instant($start);
        if ($through < $startInstant) {
            return;
        }
        $scanThrough = $through;
        if ($rule->until !== null && $rule->until->dateTime < $scanThrough) {
            $scanThrough = $rule->until->dateTime;
        }

        $neutralStart = $this->neutralLiteral($start);
        $parts = RRuleAdapter::options($rule, $neutralStart, includeUntil: false);
        $neutralRule = new RRule($parts);
        $candidateParts = $parts;
        unset($candidateParts['COUNT'], $candidateParts['BYSETPOS']);
        $candidateRule = new RRule($candidateParts);
        $inspectionThrough = $scanThrough;
        if ($rule->bySetPosition !== []) {
            $scanWall = $resolver->wallAtInstant($scanThrough, $tzid);
            [, $periodEnd] = $this->frequencyPeriodBounds($scanWall, $rule);
            $inspectionThrough = $resolver->resolveWall($tzid, $periodEnd)->dateTime;
        }

        foreach ($resolver->forwardGaps($tzid, $startInstant, $inspectionThrough) as $gap) {
            $gapCandidates = $candidateRule->getOccurrencesBetween($gap['start'], $gap['end']);
            if ($gapCandidates === []) {
                continue;
            }
            if ($rule->bySetPosition !== []) {
                if (! $this->customSelectedPeriodCanAffectThrough(
                    $parts,
                    $rule,
                    $gap['start'],
                    $scanThrough,
                    $tzid,
                    $resolver,
                )) {
                    continue;
                }
            } elseif ($rule->count !== null) {
                $selectedBeforeGap = $neutralRule->getOccurrencesBetween(null, $gap['start']->modify('-1 second'));
                if (count($selectedBeforeGap) >= $rule->count) {
                    continue;
                }
            }

            foreach ($gapCandidates as $nominal) {
                if (! $nominal instanceof DateTimeInterface) {
                    continue;
                }
                $resolved = $resolver->resolveWall($tzid, DateTimeImmutable::createFromInterface($nominal));
                if ($rule->bySetPosition === [] && $rule->until !== null && $resolved->dateTime > $rule->until->dateTime) {
                    continue;
                }

                throw new UnsupportedRecurrenceException(sprintf(
                    'Cannot safely expand an RRULE that generates the nonexistent local time %s in embedded TZID "%s"; RFC 5545 requires gap instances to be ignored without counting them.',
                    $resolved->toString(),
                    $tzid,
                ));
            }
        }
    }

    /** @param array<string, mixed> $neutralRuleParts */
    private function customSelectedPeriodCanAffectThrough(
        array $neutralRuleParts,
        Recurrence $rule,
        DateTimeImmutable $gapWall,
        DateTimeImmutable $through,
        string $tzid,
        TimeZoneResolver $resolver,
    ): bool {
        [$periodStart, $periodEnd] = $this->frequencyPeriodBounds($gapWall, $rule);
        foreach ((new RRule($neutralRuleParts))->getOccurrencesBetween($periodStart, $periodEnd) as $selected) {
            if ($selected instanceof DateTimeInterface) {
                return $resolver->resolveWall($tzid, $periodStart)->dateTime <= $through;
            }
        }

        return false;
    }

    private function frequencyPeriodEnd(
        DateTimeImmutable $through,
        DateTimeZone $zone,
        Recurrence $rule,
    ): DateTimeImmutable {
        $local = $through->setTimezone($zone);
        $wall = new DateTimeImmutable($local->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
        [, $end] = $this->frequencyPeriodBounds($wall, $rule);

        return DateTimeValue::zoned($end, $zone->getName())->dateTime;
    }

    /** @param array<string, mixed> $neutralRuleParts */
    private function selectedPeriodCanAffectThrough(
        array $neutralRuleParts,
        Recurrence $rule,
        DateTimeImmutable $gapWall,
        DateTimeImmutable $through,
        string $tzid,
    ): bool {
        [$periodStart, $periodEnd] = $this->frequencyPeriodBounds($gapWall, $rule);
        $neutralRule = new RRule($neutralRuleParts);
        $hasSelectedOccurrence = false;
        foreach ($neutralRule->getOccurrencesBetween($periodStart, $periodEnd) as $selected) {
            if (! $selected instanceof DateTimeInterface) {
                continue;
            }
            $hasSelectedOccurrence = true;

            break;
        }

        if (! $hasSelectedOccurrence) {
            // BYSETPOS selected nothing in this interval, or COUNT was already
            // exhausted in an earlier interval.
            return false;
        }

        // A gap candidate can be removed before BYSETPOS and promote a different
        // candidate earlier in the same FREQ interval. Looking only at the
        // unfiltered selected value misses that case (for example, -1 selecting
        // a gap 02:00 while RFC filtering promotes a valid 01:00). Conservatively
        // fail closed whenever the active selection interval has begun by the
        // effective query/UNTIL horizon.
        return DateTimeValue::zoned($periodStart, $tzid)->dateTime <= $through;
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    private function frequencyPeriodBounds(DateTimeImmutable $wall, Recurrence $rule): array
    {
        $hour = (int) $wall->format('H');
        $minute = (int) $wall->format('i');
        $second = (int) $wall->format('s');

        return match ($rule->frequency) {
            Frequency::Secondly => [
                $wall->setTime($hour, $minute, $second),
                $wall->setTime($hour, $minute, $second),
            ],
            Frequency::Minutely => [
                $wall->setTime($hour, $minute, 0),
                $wall->setTime($hour, $minute, 59),
            ],
            Frequency::Hourly => [
                $wall->setTime($hour, 0, 0),
                $wall->setTime($hour, 59, 59),
            ],
            Frequency::Daily => [
                $wall->setTime(0, 0, 0),
                $wall->setTime(23, 59, 59),
            ],
            Frequency::Weekly => $this->weeklyPeriodBounds($wall, $rule->weekStart ?? Weekday::Monday),
            Frequency::Monthly => [
                $wall->setDate((int) $wall->format('Y'), (int) $wall->format('m'), 1)->setTime(0, 0, 0),
                $wall->modify('last day of this month')->setTime(23, 59, 59),
            ],
            Frequency::Yearly => [
                $wall->setDate((int) $wall->format('Y'), 1, 1)->setTime(0, 0, 0),
                $wall->setDate((int) $wall->format('Y'), 12, 31)->setTime(23, 59, 59),
            ],
        };
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    private function weeklyPeriodBounds(DateTimeImmutable $wall, Weekday $weekStart): array
    {
        $weekStartNumber = match ($weekStart) {
            Weekday::Monday => 1,
            Weekday::Tuesday => 2,
            Weekday::Wednesday => 3,
            Weekday::Thursday => 4,
            Weekday::Friday => 5,
            Weekday::Saturday => 6,
            Weekday::Sunday => 7,
        };
        $daysSinceStart = ((int) $wall->format('N') - $weekStartNumber + 7) % 7;
        $start = $wall->modify(sprintf('-%d days', $daysSinceStart))->setTime(0, 0, 0);

        return [$start, $start->modify('+6 days')->setTime(23, 59, 59)];
    }

    private function assertUntilMatchesStart(DateTimeValue $until, DateTimeValue $start): void
    {
        if ($until->isDateOnly !== $start->isDateOnly) {
            throw new UnsupportedRecurrenceException('RRULE UNTIL must have the same DATE or DATE-TIME value type as DTSTART.');
        }
        if ($start->isDateOnly) {
            return;
        }

        $validForm = $start->isFloating() ? $until->isFloating() : $until->isUtc;
        if (! $validForm) {
            throw new UnsupportedRecurrenceException('RRULE UNTIL has an RFC-incompatible UTC/floating form for DTSTART.');
        }
    }

    private function assertRecurrencePropertyShapesSupported(Event $event): void
    {
        $rules = $event->properties->all('RRULE');
        if (count($rules) > 1 || ($rules !== [] && (count($rules[0]->values) !== 1 || ! $rules[0]->value() instanceof Recurrence))) {
            throw new UnsupportedRecurrenceException('Cannot safely expand duplicate, multi-valued, or untyped RRULE properties.');
        }

        foreach (['RDATE', 'EXDATE'] as $name) {
            foreach ($event->properties->all($name) as $property) {
                $types = [];
                foreach ($property->values as $value) {
                    if ($value instanceof DateTimeValue) {
                        $types['DATE-TIME'] = true;
                        $this->assertResolvableZonedInstant($value, $name);

                        continue;
                    }
                    if ($name === 'RDATE' && $value instanceof Period) {
                        $types['PERIOD'] = true;
                        $this->assertResolvableZonedInstant($value->start, 'RDATE PERIOD start');
                        if ($value->end !== null) {
                            $this->assertResolvableZonedInstant($value->end, 'RDATE PERIOD end');
                            if ($this->resolvedInstant($value->end)->getTimestamp()
                                <= $this->resolvedInstant($value->start)->getTimestamp()) {
                                throw new UnsupportedRecurrenceException(
                                    'An explicit PERIOD end must resolve to an instant after its start.',
                                );
                            }
                        }

                        continue;
                    }

                    throw new UnsupportedRecurrenceException(sprintf('Cannot safely expand an unsupported or untyped %s value.', $name));
                }
                if (count($types) > 1) {
                    throw new UnsupportedRecurrenceException(sprintf('A single %s property cannot safely mix PERIOD and DATE-TIME values.', $name));
                }
            }
        }
    }

    private function assertRecurrenceFormsSupported(Event $event, DateTimeValue $start): void
    {
        foreach (['RDATE', 'EXDATE'] as $name) {
            foreach ($event->properties->all($name) as $property) {
                foreach ($property->values as $value) {
                    $date = $value instanceof Period ? $value->start : $value;
                    if (! $date instanceof DateTimeValue) {
                        throw new \LogicException('Recurrence property shapes must be validated before their forms.');
                    }
                    if ($date->isDateOnly !== $start->isDateOnly) {
                        throw new UnsupportedRecurrenceException(sprintf('%s must have the same DATE or DATE-TIME value type as DTSTART.', $name));
                    }
                    if (! $start->isDateOnly && $date->isFloating() !== $start->isFloating()) {
                        throw new UnsupportedRecurrenceException(sprintf(
                            '%s and DTSTART cannot mix floating and instant-based DATE-TIME forms.',
                            $name,
                        ));
                    }
                }
            }
        }
    }

    private function customTzid(DateTimeValue $value): ?string
    {
        if ($value->tzid === null) {
            return null;
        }
        if ($this->timeZones?->usesEmbedded($value->tzid)) {
            return $value->tzid;
        }

        try {
            new DateTimeZone($value->tzid);

            return null;
        } catch (DateInvalidTimeZoneException) {
            return $value->tzid;
        }
    }

    private function resolvedInstant(DateTimeValue $value): DateTimeImmutable
    {
        if ($value->tzid !== null && $this->timeZones?->usesEmbedded($value->tzid)) {
            return $this->timeZones->instant($value);
        }

        return $value->dateTime;
    }

    private function neutralLiteral(DateTimeValue $value): DateTimeImmutable
    {
        $wall = DateTimeImmutable::createFromFormat('!Ymd\THis', $value->toString(), new DateTimeZone('UTC'));
        if ($wall === false) {
            throw new UnsupportedRecurrenceException('Could not represent a custom-TZID wall-clock value for recurrence arithmetic.');
        }

        return $wall;
    }

    private function recurrenceBacking(DateTimeValue $value, ?string $customTzid): DateTimeImmutable
    {
        if ($customTzid === null) {
            return $value->dateTime;
        }
        if ($value->tzid === $customTzid) {
            return $this->neutralLiteral($value);
        }

        $resolver = $this->timeZones
            ?? throw new UnsupportedRecurrenceException('Custom TZID expansion requires a calendar timezone resolver.');

        return $resolver->wallAtInstant($resolver->instant($value), $customTzid);
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleParts(Recurrence $rule, DateTimeImmutable $dtstart): array
    {
        return RRuleAdapter::options($rule, $dtstart);
    }
}
