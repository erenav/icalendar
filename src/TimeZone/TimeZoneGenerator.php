<?php

declare(strict_types=1);

namespace Erenav\ICalendar\TimeZone;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Erenav\ICalendar\Component\ComponentList;
use Erenav\ICalendar\Component\Observance;
use Erenav\ICalendar\Component\TimeZone;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Property\PropertyBag;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\Recurrence\Weekday;
use Erenav\ICalendar\Recurrence\WeekdayRule;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\TextValue;
use Erenav\ICalendar\ValueType\UtcOffset;

/**
 * Builds a VTIMEZONE from the PHP/IANA transition database.
 *
 * Transitions are inspected over an explicit horizon (1970–2100 by default).
 * Each stable transition era gets its own observance; superseded eras receive a
 * UTC UNTIL, one-off transitions remain one-off, and only a stable suffix seen
 * for at least five consecutive years through the horizon is emitted without
 * UNTIL. This avoids projecting one representative modern rule over known rule
 * changes while keeping ordinary zones compact.
 */
final class TimeZoneGenerator
{
    private const WEEKDAYS = [
        1 => Weekday::Monday, 2 => Weekday::Tuesday, 3 => Weekday::Wednesday,
        4 => Weekday::Thursday, 5 => Weekday::Friday, 6 => Weekday::Saturday, 7 => Weekday::Sunday,
    ];

    private DateTimeImmutable $from;

    private DateTimeImmutable $through;

    public function __construct(
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $through = null,
    ) {
        $this->from = $from === null
            ? new DateTimeImmutable('1970-01-01T00:00:00Z')
            : DateTimeImmutable::createFromInterface($from)->setTimezone(new DateTimeZone('UTC'));
        $this->through = $through === null
            ? new DateTimeImmutable('2100-12-31T23:59:59Z')
            : DateTimeImmutable::createFromInterface($through)->setTimezone(new DateTimeZone('UTC'));
        if ($this->through <= $this->from) {
            throw new InvalidValueException('VTIMEZONE generation horizon must end after it starts.');
        }
    }

    /** Like {@see forIana()} but returns null for a non-IANA id instead of throwing. */
    public function tryForIana(string $tzid): ?TimeZone
    {
        return $this->isIana($tzid) ? $this->forIana($tzid) : null;
    }

    public function forIana(string $tzid): TimeZone
    {
        if (! $this->isIana($tzid)) {
            throw new InvalidValueException(sprintf('Unknown IANA time zone "%s".', $tzid));
        }

        $zone = new DateTimeZone($tzid);
        if ($zone->getLocation() === false) {
            // PHP represents a few fixed compatibility identifiers (notably
            // GMT) without a transition table even though they are valid
            // entries in ALL_WITH_BC.
            $native = [[
                'ts' => $this->from->getTimestamp(),
                'time' => $this->from->format(DATE_ATOM),
                'offset' => $zone->getOffset($this->from),
                'isdst' => false,
                'abbr' => $this->from->setTimezone($zone)->format('T'),
            ]];
        } else {
            $native = $zone->getTransitions($this->from->getTimestamp(), $this->through->getTimestamp() + 1);
        }
        if ($native === []) {
            throw new InvalidValueException(sprintf('Could not inspect transitions for IANA time zone "%s".', $tzid));
        }

        $transitions = [];
        for ($index = 1, $count = count($native); $index < $count; $index++) {
            $previousOffset = $native[$index - 1]['offset'];
            $transition = $native[$index];
            $local = (new DateTimeImmutable('@'.($transition['ts'] + $previousOffset)))
                ->setTimezone(new DateTimeZone('UTC'));
            $transitions[] = [
                'ts' => $transition['ts'],
                'from' => $previousOffset,
                'to' => $transition['offset'],
                'isdst' => $transition['isdst'],
                'abbr' => $transition['abbr'],
                'local' => $local,
                'signature' => $this->signature($transition['isdst'], $previousOffset, $transition['offset'], $transition['abbr'], $local),
            ];
        }

        $observances = $transitions === []
            ? [$this->fixedObservance($native[0])]
            : $this->observances($transitions);

        return new TimeZone(
            new PropertyBag(new Property('TZID', new TextValue($tzid))),
            new ComponentList(...$observances),
        );
    }

    private function isIana(string $tzid): bool
    {
        return in_array($tzid, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    /**
     * @param  list<array{ts: int, from: int, to: int, isdst: bool, abbr: string, local: DateTimeImmutable, signature: string}>  $transitions
     * @return list<Observance>
     */
    private function observances(array $transitions): array
    {
        $byKind = ['standard' => [], 'daylight' => []];
        foreach ($transitions as $transition) {
            $byKind[$transition['isdst'] ? 'daylight' : 'standard'][] = $transition;
        }

        $segmentsByKind = [
            'standard' => $this->transitionSegments($byKind['standard']),
            'daylight' => $this->transitionSegments($byKind['daylight']),
        ];
        $stableCycle = $byKind['standard'] !== [] && $byKind['daylight'] !== [];
        foreach ($segmentsByKind as $segments) {
            if ($segments === []) {
                $stableCycle = false;

                continue;
            }
            $suffix = $segments[array_key_last($segments)];
            if (count($suffix) < 5
                || (int) $suffix[array_key_last($suffix)]['local']->format('Y') < (int) $this->through->format('Y') - 1) {
                $stableCycle = false;
            }
        }

        $observances = [];
        $irregular = [];
        foreach ($segmentsByKind as $kind => $segments) {
            foreach ($segments as $index => $segment) {
                if (count($segment) === 1) {
                    $transition = $segment[0];
                    $key = implode(':', [
                        $kind,
                        $transition['from'],
                        $transition['to'],
                        $transition['abbr'],
                    ]);
                    $irregular[$key][] = $transition;

                    continue;
                }
                $isStableSuffix = $stableCycle && $index === array_key_last($segments);
                $observances[] = $this->observance(
                    $kind === 'daylight',
                    $segment,
                    $isStableSuffix,
                );
            }
        }

        foreach ($irregular as $segment) {
            $observances[] = $this->irregularObservance($segment[0]['isdst'], $segment);
        }

        usort($observances, static function (Observance $left, Observance $right): int {
            return ($left->start()?->toString() ?? '') <=> ($right->start()?->toString() ?? '');
        });

        return $observances;
    }

    /**
     * @param  non-empty-list<array{ts: int, from: int, to: int, isdst: bool, abbr: string, local: DateTimeImmutable, signature: string}>  $transitions
     */
    private function irregularObservance(bool $daylight, array $transitions): Observance
    {
        usort($transitions, static fn (array $left, array $right): int => $left['ts'] <=> $right['ts']);
        $first = $transitions[0];
        $properties = [
            new Property('DTSTART', DateTimeValue::floating($first['local'])),
            new Property('TZOFFSETFROM', UtcOffset::fromSeconds($first['from'])),
            new Property('TZOFFSETTO', UtcOffset::fromSeconds($first['to'])),
        ];
        $additional = array_slice($transitions, 1);
        if ($additional !== []) {
            $properties[] = new Property('RDATE', array_map(
                static fn (array $transition): DateTimeValue => DateTimeValue::floating($transition['local']),
                $additional,
            ));
        }
        if ($first['abbr'] !== '') {
            $properties[] = new Property('TZNAME', new TextValue($first['abbr']));
        }

        return new Observance($daylight, new PropertyBag(...$properties));
    }

    /**
     * @param  array{local: DateTimeImmutable, signature: string}  $previous
     * @param  array{local: DateTimeImmutable, signature: string}  $next
     */
    private function continues(array $previous, array $next): bool
    {
        return $previous['signature'] === $next['signature']
            && (int) $next['local']->format('Y') === (int) $previous['local']->format('Y') + 1;
    }

    /**
     * @param  list<array{ts: int, from: int, to: int, isdst: bool, abbr: string, local: DateTimeImmutable, signature: string}>  $transitions
     * @return list<non-empty-list<array{ts: int, from: int, to: int, isdst: bool, abbr: string, local: DateTimeImmutable, signature: string}>>
     */
    private function transitionSegments(array $transitions): array
    {
        $segments = [];
        foreach ($transitions as $transition) {
            $lastIndex = array_key_last($segments);
            if ($lastIndex === null
                || ! $this->continues($segments[$lastIndex][array_key_last($segments[$lastIndex])], $transition)) {
                $segments[] = [$transition];
            } else {
                $segments[$lastIndex][] = $transition;
            }
        }

        return $segments;
    }

    /**
     * @param  non-empty-list<array{ts: int, from: int, to: int, isdst: bool, abbr: string, local: DateTimeImmutable, signature: string}>  $segment
     */
    private function observance(bool $daylight, array $segment, bool $unbounded): Observance
    {
        $first = $segment[0];
        $properties = [
            new Property('DTSTART', DateTimeValue::floating($first['local'])),
            new Property('TZOFFSETFROM', UtcOffset::fromSeconds($first['from'])),
            new Property('TZOFFSETTO', UtcOffset::fromSeconds($first['to'])),
        ];

        if (count($segment) > 1) {
            $rule = $this->deriveRule($first['local']);
            if (! $unbounded) {
                $last = $segment[array_key_last($segment)];
                $rule = $rule->until(DateTimeValue::utc(new DateTimeImmutable('@'.$last['ts'])));
            }
            $properties[] = new Property('RRULE', $rule);
        }
        if ($first['abbr'] !== '') {
            $properties[] = new Property('TZNAME', new TextValue($first['abbr']));
        }

        return new Observance($daylight, new PropertyBag(...$properties));
    }

    /** @param array{offset: int, abbr: string} $baseline */
    private function fixedObservance(array $baseline): Observance
    {
        $offset = UtcOffset::fromSeconds($baseline['offset']);

        return new Observance(false, new PropertyBag(
            new Property('DTSTART', DateTimeValue::floating($this->from)),
            new Property('TZOFFSETFROM', $offset),
            new Property('TZOFFSETTO', $offset),
            new Property('TZNAME', new TextValue($baseline['abbr'])),
        ));
    }

    private function deriveRule(DateTimeImmutable $local): Recurrence
    {
        $month = (int) $local->format('n');
        $dayOfMonth = (int) $local->format('j');
        $daysInMonth = (int) $local->format('t');
        $weekday = self::WEEKDAYS[(int) $local->format('N')];
        $ordinal = $dayOfMonth + 7 > $daysInMonth ? -1 : intdiv($dayOfMonth - 1, 7) + 1;

        return Recurrence::yearly()->inMonths($month)->on(new WeekdayRule($weekday, $ordinal));
    }

    private function signature(
        bool $daylight,
        int $from,
        int $to,
        string $abbreviation,
        DateTimeImmutable $local,
    ): string {
        $day = (int) $local->format('j');
        $ordinal = $day + 7 > (int) $local->format('t') ? -1 : intdiv($day - 1, 7) + 1;

        return implode(':', [
            $daylight ? 'D' : 'S',
            $from,
            $to,
            $abbreviation,
            $local->format('n'),
            $ordinal,
            $local->format('N'),
            $local->format('His'),
        ]);
    }
}
