<?php

declare(strict_types=1);

namespace Erenav\ICalendar\TimeZone;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Observance;
use Erenav\ICalendar\Component\TimeZone;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\Recurrence\RRuleAdapter;
use Erenav\ICalendar\Recurrence\UnsupportedRecurrenceException;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use Erenav\ICalendar\ValueType\TextValue;
use Erenav\ICalendar\ValueType\UtcOffset;
use Erenav\ICalendar\ValueType\Value;
use Exception;
use RRule\RRule;

/**
 * Resolves TZID values using either PHP's IANA database or a VTIMEZONE embedded
 * in one calendar. Custom definitions are deliberately calendar-scoped: a TZID
 * has no global meaning outside the VCALENDAR that defines it.
 */
final class TimeZoneResolver
{
    /** @var array<string, TimeZone> */
    private array $definitions = [];

    /** @var array<string, true> */
    private array $ambiguous = [];

    /** @var array<string, true> */
    private array $invalidClaims = [];

    /**
     * @var array<string, array{through: int, transitions: list<array{instant: int, from: int, to: int}>}>
     */
    private array $cache = [];

    public function __construct(TimeZone ...$timeZones)
    {
        foreach ($timeZones as $timeZone) {
            try {
                $tzid = $this->singleTzid($timeZone);
            } catch (UnsupportedRecurrenceException) {
                // Malformed definitions remain preserved by the component
                // model. They become an error only if an event actually needs
                // an identifier they claim during expansion.
                foreach ($this->claimedTzids($timeZone) as $claimedTzid) {
                    $this->invalidClaims[$claimedTzid] = true;
                }

                continue;
            }
            if (isset($this->definitions[$tzid])) {
                $this->ambiguous[$tzid] = true;

                continue;
            }
            $this->definitions[$tzid] = $timeZone;
        }
    }

    public static function fromCalendar(Calendar $calendar): self
    {
        return new self(...$calendar->timeZones());
    }

    public function canResolve(string $tzid): bool
    {
        if ($this->usesEmbedded($tzid)) {
            try {
                $this->definition($tzid);
                $this->transitions($tzid, (int) gmdate('Y') + 2);

                return true;
            } catch (UnsupportedRecurrenceException) {
                return false;
            }
        }

        return $this->iana($tzid) !== null;
    }

    /** Whether this calendar supplied the authoritative definition for TZID. */
    public function usesEmbedded(string $tzid): bool
    {
        return isset($this->definitions[$tzid]) || isset($this->invalidClaims[$tzid]);
    }

    /** Apply an RFC duration using this calendar's authoritative zone rules. */
    public function addDuration(DateTimeValue $start, Duration $duration): DateTimeValue
    {
        if ($start->tzid === null) {
            return $start->adding($duration);
        }

        if (! $this->usesEmbedded($start->tzid)) {
            if ($this->iana($start->tzid) !== null) {
                return $start->adding($duration);
            }

            throw new UnsupportedRecurrenceException(sprintf(
                'Calendar has no resolvable VTIMEZONE for TZID "%s".',
                $start->tzid,
            ));
        }

        $tzid = $start->tzid;
        $sign = $duration->negative ? -1 : 1;
        $days = ($duration->weeks * 7 + $duration->days) * $sign;
        $seconds = ($duration->hours * 3600 + $duration->minutes * 60 + $duration->seconds) * $sign;
        $resolved = $this->resolveWall($tzid, $this->neutralFromLiteral($start->toString()));

        if ($days !== 0) {
            $wall = $this->neutralFromLiteral($start->toString())
                ->modify(sprintf('%+d days', $days));
            $resolved = $this->resolveWall($tzid, $wall);
        }

        if ($seconds === 0) {
            return $resolved;
        }

        $instant = $resolved->dateTime->setTimestamp($resolved->dateTime->getTimestamp() + $seconds);
        $wall = $this->wallAtInstant($instant, $tzid);
        $first = $this->resolveWall($tzid, $wall);

        return $first->dateTime->getTimestamp() === $instant->getTimestamp()
            ? DateTimeValue::resolvedZoned($instant, $wall, $tzid)
            : DateTimeValue::utc($instant);
    }

    /** Resolve a typed value to its absolute instant. */
    public function instant(DateTimeValue $value): DateTimeImmutable
    {
        if ($value->tzid === null) {
            return $value->dateTime;
        }
        if (! $this->usesEmbedded($value->tzid) && $value->hasResolvedInstant()) {
            return $value->dateTime;
        }

        return $this->resolveWall($value->tzid, $this->neutralFromLiteral($value->toString()))->dateTime;
    }

    /** Resolve local wall fields in a named zone using RFC gap/fold rules. */
    public function resolveWall(string $tzid, DateTimeImmutable $wall): DateTimeValue
    {
        $iana = $this->usesEmbedded($tzid) ? null : $this->iana($tzid);
        if ($iana !== null) {
            return DateTimeValue::zoned($wall, $tzid);
        }

        $this->definition($tzid);
        $wall = $this->neutral($wall);
        $wallTimestamp = $wall->getTimestamp();
        $transitions = $this->transitions($tzid, (int) $wall->format('Y') + 2);
        $offsets = [];
        foreach ($transitions as $transition) {
            $offsets[$transition['from']] = true;
            $offsets[$transition['to']] = true;
        }

        $candidates = [];
        foreach (array_keys($offsets) as $offset) {
            $instant = $wallTimestamp - $offset;
            if ($this->offsetAtTimestamp($tzid, $instant) === $offset) {
                $candidates[] = $instant;
            }
        }
        if ($candidates !== []) {
            sort($candidates, SORT_NUMERIC);

            return DateTimeValue::resolvedZoned(
                new DateTimeImmutable('@'.$candidates[0]),
                $wall,
                $tzid,
            );
        }

        foreach ($transitions as $transition) {
            if ($transition['to'] <= $transition['from']) {
                continue;
            }
            $gapStart = $transition['instant'] + $transition['from'];
            $gapEnd = $transition['instant'] + $transition['to'];
            if ($wallTimestamp >= $gapStart && $wallTimestamp < $gapEnd) {
                return DateTimeValue::resolvedZoned(
                    new DateTimeImmutable('@'.($wallTimestamp - $transition['from'])),
                    $wall,
                    $tzid,
                );
            }
        }

        throw new UnsupportedRecurrenceException(sprintf(
            'Embedded VTIMEZONE "%s" cannot resolve local time %s unambiguously.',
            $tzid,
            $wall->format('Y-m-d H:i:s'),
        ));
    }

    /** Materialize the local wall representation of an absolute instant. */
    public function valueAtInstant(DateTimeImmutable $instant, string $tzid): DateTimeValue
    {
        $iana = $this->usesEmbedded($tzid) ? null : $this->iana($tzid);
        if ($iana !== null) {
            $value = DateTimeValue::zoned($instant->setTimezone($iana), $tzid);
            if ($value->dateTime->getTimestamp() !== $instant->getTimestamp()) {
                throw new UnsupportedRecurrenceException(sprintf(
                    'Instant %s is the second occurrence of an ambiguous local time in TZID "%s".',
                    $instant->format(DATE_ATOM),
                    $tzid,
                ));
            }

            return $value;
        }

        $wall = $this->wallAtInstant($instant, $tzid);
        $first = $this->resolveWall($tzid, $wall);
        if ($first->dateTime->getTimestamp() !== $instant->getTimestamp()) {
            throw new UnsupportedRecurrenceException(sprintf(
                'Instant %s is the second occurrence of an ambiguous local time in embedded TZID "%s".',
                $instant->format(DATE_ATOM),
                $tzid,
            ));
        }

        return DateTimeValue::resolvedZoned($instant, $wall, $tzid);
    }

    /** A neutral UTC-backed container holding the local fields at an instant. */
    public function wallAtInstant(DateTimeImmutable $instant, string $tzid): DateTimeImmutable
    {
        $iana = $this->usesEmbedded($tzid) ? null : $this->iana($tzid);
        if ($iana !== null) {
            return $this->neutral($instant->setTimezone($iana));
        }

        $offset = $this->offsetAtTimestamp($tzid, $instant->getTimestamp());

        return (new DateTimeImmutable('@'.($instant->getTimestamp() + $offset)))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Forward-offset gaps whose transition instants can affect the interval.
     *
     * @return list<array{start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    public function forwardGaps(
        string $tzid,
        DateTimeImmutable $from,
        DateTimeImmutable $through,
    ): array {
        $this->definition($tzid);
        $transitions = $this->transitions($tzid, (int) $through->format('Y') + 2);
        $gaps = [];
        foreach ($transitions as $transition) {
            if ($transition['to'] <= $transition['from']
                || $transition['instant'] < $from->getTimestamp() - 172800
                || $transition['instant'] > $through->getTimestamp() + 172800) {
                continue;
            }
            $gaps[] = [
                'start' => new DateTimeImmutable('@'.($transition['instant'] + $transition['from'])),
                'end' => new DateTimeImmutable('@'.($transition['instant'] + $transition['to'] - 1)),
            ];
        }

        return $gaps;
    }

    private function offsetAtTimestamp(string $tzid, int $instant): int
    {
        $transitions = $this->transitions(
            $tzid,
            (int) (new DateTimeImmutable('@'.$instant))->format('Y') + 2,
        );
        $offset = $transitions[0]['from'];
        foreach ($transitions as $transition) {
            if ($transition['instant'] > $instant) {
                break;
            }
            $offset = $transition['to'];
        }

        return $offset;
    }

    /**
     * @return list<array{instant: int, from: int, to: int}>
     */
    private function transitions(string $tzid, int $throughYear): array
    {
        if (isset($this->cache[$tzid]) && $this->cache[$tzid]['through'] >= $throughYear) {
            return $this->cache[$tzid]['transitions'];
        }

        $timeZone = $this->definition($tzid);
        $through = new DateTimeImmutable(sprintf('%04d-12-31 23:59:59 UTC', $throughYear));
        $transitions = [];
        $earliestFuture = null;
        foreach ($timeZone->observances() as $observance) {
            [$start, $from, $to, $rule] = $this->observanceDefinition($observance);
            if ($start->dateTime > $through) {
                $future = [
                    'instant' => $start->dateTime->getTimestamp() - $from->totalSeconds,
                    'from' => $from->totalSeconds,
                    'to' => $to->totalSeconds,
                ];
                if ($earliestFuture === null || $future['instant'] < $earliestFuture['instant']) {
                    $earliestFuture = $future;
                }
            }
            /** @var array<string, array{wall: DateTimeImmutable, constrainedByUntil: bool}> $walls */
            $walls = $start->dateTime <= $through
                ? [$start->toString() => ['wall' => $start->dateTime, 'constrainedByUntil' => $rule !== null]]
                : [];
            if ($rule !== null) {
                $options = RRuleAdapter::options($rule, $start->dateTime, includeUntil: false);
                try {
                    $nativeRule = new RRule($options);
                    $synchronizationCandidates = $nativeRule->getOccurrencesBetween($start->dateTime, $start->dateTime);
                    $ruleOccurrences = $nativeRule->getOccurrencesBetween($start->dateTime, $through);
                } catch (Exception $exception) {
                    // Translate failures at the third-party recurrence boundary
                    // while allowing PHP Errors and TypeErrors to expose defects.
                    throw new UnsupportedRecurrenceException(
                        'The VTIMEZONE RRULE could not be expanded safely by the recurrence engine.',
                        previous: $exception,
                    );
                }

                $synchronized = false;
                foreach ($synchronizationCandidates as $occurrence) {
                    if ($occurrence instanceof \DateTimeInterface
                        && $occurrence->getTimestamp() === $start->dateTime->getTimestamp()) {
                        $synchronized = true;

                        break;
                    }
                }
                if (! $synchronized) {
                    throw new UnsupportedRecurrenceException('VTIMEZONE DTSTART is not synchronized with its RRULE.');
                }
                foreach ($ruleOccurrences as $occurrence) {
                    if ($occurrence instanceof \DateTimeInterface) {
                        $wall = DateTimeImmutable::createFromInterface($occurrence);
                        $walls[$wall->format('Ymd\THis')] = ['wall' => $wall, 'constrainedByUntil' => true];
                    }
                }
            }
            foreach ($observance->recurrenceDates() as $date) {
                if ($date->isDateOnly || ! $date->isFloating()) {
                    throw new UnsupportedRecurrenceException('VTIMEZONE RDATE values must be floating DATE-TIME transition onsets.');
                }
                if ($date->dateTime <= $through) {
                    $walls[$date->toString()] = ['wall' => $date->dateTime, 'constrainedByUntil' => false];
                }
            }

            foreach ($walls as ['wall' => $wall, 'constrainedByUntil' => $constrainedByUntil]) {
                $instant = $wall->getTimestamp() - $from->totalSeconds;
                if ($constrainedByUntil && $rule?->until !== null && $instant > $rule->until->dateTime->getTimestamp()) {
                    continue;
                }
                $transitions[] = [
                    'instant' => $instant,
                    'from' => $from->totalSeconds,
                    'to' => $to->totalSeconds,
                ];
            }
        }

        if ($transitions === [] && $earliestFuture !== null) {
            $transitions[] = $earliestFuture;
        }
        if ($transitions === []) {
            throw new UnsupportedRecurrenceException(sprintf('Embedded VTIMEZONE "%s" has no usable observance transitions.', $tzid));
        }
        usort($transitions, static fn (array $left, array $right): int => $left['instant'] <=> $right['instant']);

        $normalized = [];
        foreach ($transitions as $transition) {
            $last = $normalized === [] ? null : $normalized[array_key_last($normalized)];
            if ($last !== null && $last['instant'] === $transition['instant']) {
                if ($last['from'] !== $transition['from'] || $last['to'] !== $transition['to']) {
                    throw new UnsupportedRecurrenceException(sprintf(
                        'Embedded VTIMEZONE "%s" defines conflicting transitions at one instant.',
                        $tzid,
                    ));
                }

                continue;
            }
            $normalized[] = $transition;
        }

        $active = $normalized[0]['from'];
        foreach ($normalized as $transition) {
            if ($transition['from'] !== $active) {
                throw new UnsupportedRecurrenceException(sprintf(
                    'Embedded VTIMEZONE "%s" contains a discontinuous TZOFFSETFROM/TZOFFSETTO sequence.',
                    $tzid,
                ));
            }
            $active = $transition['to'];
        }

        $this->cache[$tzid] = [
            'through' => $throughYear,
            'transitions' => $normalized,
        ];

        return $normalized;
    }

    /**
     * @return array{DateTimeValue, UtcOffset, UtcOffset, ?Recurrence}
     */
    private function observanceDefinition(Observance $observance): array
    {
        $start = $this->singleValue($observance, 'DTSTART', DateTimeValue::class);
        $from = $this->singleValue($observance, 'TZOFFSETFROM', UtcOffset::class);
        $to = $this->singleValue($observance, 'TZOFFSETTO', UtcOffset::class);
        if ($start->isDateOnly || ! $start->isFloating()) {
            throw new UnsupportedRecurrenceException('VTIMEZONE DTSTART must be a floating DATE-TIME transition onset.');
        }

        $rules = $observance->properties->all('RRULE');
        if (count($rules) > 1
            || ($rules !== [] && (count($rules[0]->values) !== 1 || ! $rules[0]->value() instanceof Recurrence))) {
            throw new UnsupportedRecurrenceException('VTIMEZONE observances require at most one typed, single-valued RRULE.');
        }
        $rule = $rules === [] ? null : $rules[0]->value();
        if ($rule instanceof Recurrence) {
            if ($rule->unknownParts !== [] || in_array(60, $rule->bySecond, true)) {
                throw new UnsupportedRecurrenceException('VTIMEZONE recurrence contains unsupported rule semantics.');
            }
            if ($rule->until !== null && ! $rule->until->isUtc) {
                throw new UnsupportedRecurrenceException('A VTIMEZONE RRULE UNTIL must be a UTC DATE-TIME.');
            }
        }

        foreach ($observance->properties->all('RDATE') as $property) {
            foreach ($property->values as $value) {
                if (! $value instanceof DateTimeValue) {
                    throw new UnsupportedRecurrenceException('VTIMEZONE RDATE must contain only typed DATE-TIME values.');
                }
            }
        }

        return [$start, $from, $to, $rule instanceof Recurrence ? $rule : null];
    }

    /**
     * @template T of Value
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function singleValue(Observance $observance, string $name, string $class): Value
    {
        $properties = $observance->properties->all($name);
        if (count($properties) !== 1 || count($properties[0]->values) !== 1 || ! $properties[0]->value() instanceof $class) {
            throw new UnsupportedRecurrenceException(sprintf(
                'VTIMEZONE observance %s must occur exactly once with its typed value.',
                $name,
            ));
        }

        return $properties[0]->value();
    }

    private function definition(string $tzid): TimeZone
    {
        if (isset($this->invalidClaims[$tzid])) {
            throw new UnsupportedRecurrenceException(sprintf('Calendar contains a malformed VTIMEZONE claiming TZID "%s".', $tzid));
        }
        if (isset($this->ambiguous[$tzid])) {
            throw new UnsupportedRecurrenceException(sprintf('Calendar contains multiple VTIMEZONE definitions for TZID "%s".', $tzid));
        }

        return $this->definitions[$tzid]
            ?? throw new UnsupportedRecurrenceException(sprintf('Calendar has no resolvable VTIMEZONE for TZID "%s".', $tzid));
    }

    private function singleTzid(TimeZone $timeZone): string
    {
        $properties = $timeZone->properties->all('TZID');
        if (count($properties) !== 1
            || count($properties[0]->values) !== 1
            || ! $properties[0]->value() instanceof TextValue) {
            throw new UnsupportedRecurrenceException('VTIMEZONE must contain exactly one single-valued TZID.');
        }
        $tzid = $timeZone->tzid();
        if ($tzid === null || $tzid === '') {
            throw new UnsupportedRecurrenceException('VTIMEZONE TZID must be a typed, non-empty text value.');
        }

        return $tzid;
    }

    /** @return list<string> */
    private function claimedTzids(TimeZone $timeZone): array
    {
        $claims = [];
        foreach ($timeZone->properties->all('TZID') as $property) {
            foreach ($property->values as $value) {
                $claim = $value->toString();
                if ($claim !== '') {
                    $claims[$claim] = true;
                }
            }
        }

        return array_keys($claims);
    }

    private function iana(string $tzid): ?DateTimeZone
    {
        try {
            return new DateTimeZone($tzid);
        } catch (DateInvalidTimeZoneException) {
            return null;
        }
    }

    private function neutral(DateTimeImmutable $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    private function neutralFromLiteral(string $literal): DateTimeImmutable
    {
        $wall = DateTimeImmutable::createFromFormat('!Ymd\THis', $literal, new DateTimeZone('UTC'));
        if ($wall === false) {
            throw new UnsupportedRecurrenceException('Could not read the custom-TZID wall-clock value.');
        }

        return $wall;
    }
}
