<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Recurrence;

use DateTimeInterface;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Value;

/**
 * An immutable, typed RRULE (RFC 5545 §3.3.10) — the value of a recurrence rule.
 *
 * Construct fluently from a frequency: `Recurrence::weekly()->every(2)->on(Weekday::Monday)`.
 * Each modifier returns a new instance. Expansion into concrete occurrences is a
 * separate concern (see {@see RecurrenceExpander}); this object only models and
 * serialises the rule.
 *
 * @phpstan-type IntList list<int>
 */
final readonly class Recurrence implements Value
{
    /**
     * @param  list<WeekdayRule>  $byDay
     * @param  list<int>  $byMonthDay
     * @param  list<int>  $byMonth
     * @param  list<int>  $byYearDay
     * @param  list<int>  $byWeekNo
     * @param  list<int>  $byHour
     * @param  list<int>  $byMinute
     * @param  list<int>  $bySecond
     * @param  list<int>  $bySetPosition
     * @param  list<RecurrencePart>  $unknownParts
     */
    public function __construct(
        public Frequency $frequency,
        public int $interval = 1,
        public ?int $count = null,
        public ?DateTimeValue $until = null,
        public array $byDay = [],
        public array $byMonthDay = [],
        public array $byMonth = [],
        public array $byYearDay = [],
        public array $byWeekNo = [],
        public array $byHour = [],
        public array $byMinute = [],
        public array $bySecond = [],
        public array $bySetPosition = [],
        public ?Weekday $weekStart = null,
        public array $unknownParts = [],
    ) {
        self::validateInstances($byDay, WeekdayRule::class, 'RRULE BYDAY values must be WeekdayRule instances.');
        self::validateInstances($unknownParts, RecurrencePart::class, 'Unknown RRULE parts must be RecurrencePart instances.');
        if ($interval < 1 || $interval > 2147483647) {
            throw new InvalidValueException('RRULE INTERVAL must be within 1..2147483647.');
        }
        if ($count !== null && ($count < 1 || $count > 2147483647)) {
            throw new InvalidValueException('RRULE COUNT must be within 1..2147483647.');
        }
        if ($count !== null && $until !== null) {
            throw new InvalidValueException('RRULE COUNT and UNTIL are mutually exclusive.');
        }
        if ($until?->tzid !== null) {
            throw new InvalidValueException('RRULE UNTIL cannot carry a TZID; use a UTC or floating DATE-TIME, or a DATE value.');
        }
        self::validateList('BYSECOND', $bySecond, 0, 60);
        self::validateList('BYMINUTE', $byMinute, 0, 59);
        self::validateList('BYHOUR', $byHour, 0, 23);
        self::validateList('BYMONTH', $byMonth, 1, 12);
        self::validateList('BYMONTHDAY', $byMonthDay, -31, 31, true);
        self::validateList('BYYEARDAY', $byYearDay, -366, 366, true);
        self::validateList('BYWEEKNO', $byWeekNo, -53, 53, true);
        self::validateList('BYSETPOS', $bySetPosition, -366, 366, true);
        if ($byWeekNo !== [] && $frequency !== Frequency::Yearly) {
            throw new InvalidValueException('RRULE BYWEEKNO is only valid with YEARLY frequency.');
        }
        if ($byYearDay !== [] && in_array($frequency, [Frequency::Daily, Frequency::Weekly, Frequency::Monthly], true)) {
            throw new InvalidValueException('RRULE BYYEARDAY is not valid with DAILY, WEEKLY, or MONTHLY frequency.');
        }
        if ($byMonthDay !== [] && $frequency === Frequency::Weekly) {
            throw new InvalidValueException('RRULE BYMONTHDAY is not valid with WEEKLY frequency.');
        }
        if ($bySetPosition !== [] && $byDay === [] && $byMonthDay === [] && $byMonth === [] && $byYearDay === [] && $byWeekNo === [] && $byHour === [] && $byMinute === [] && $bySecond === []) {
            throw new InvalidValueException('RRULE BYSETPOS requires another BY rule part.');
        }
        foreach ($byDay as $day) {
            if ($day->ordinal !== null && ! in_array($frequency, [Frequency::Monthly, Frequency::Yearly], true)) {
                throw new InvalidValueException('Numeric BYDAY values are only valid with MONTHLY or YEARLY frequency.');
            }
            if ($day->ordinal !== null && $frequency === Frequency::Yearly && $byWeekNo !== []) {
                throw new InvalidValueException('Numeric BYDAY is not valid with BYWEEKNO in a YEARLY rule.');
            }
        }
    }

    public static function secondly(): self
    {
        return new self(Frequency::Secondly);
    }

    public static function minutely(): self
    {
        return new self(Frequency::Minutely);
    }

    public static function hourly(): self
    {
        return new self(Frequency::Hourly);
    }

    public static function daily(): self
    {
        return new self(Frequency::Daily);
    }

    public static function weekly(): self
    {
        return new self(Frequency::Weekly);
    }

    public static function monthly(): self
    {
        return new self(Frequency::Monthly);
    }

    public static function yearly(): self
    {
        return new self(Frequency::Yearly);
    }

    public function every(int $interval): self
    {
        return $this->copy(['interval' => $interval]);
    }

    /** Limit to a number of occurrences (clears UNTIL). */
    public function times(int $count): self
    {
        return $this->copy(['count' => $count, 'until' => null]);
    }

    /** Limit to occurrences up to and including a date (clears COUNT). */
    public function until(DateTimeInterface|DateTimeValue $until): self
    {
        $value = $until instanceof DateTimeValue ? $until : DateTimeValue::fromDateTime($until);

        return $this->copy(['until' => $value, 'count' => null]);
    }

    public function on(Weekday|WeekdayRule ...$days): self
    {
        $byDay = array_map(
            static fn (Weekday|WeekdayRule $day): WeekdayRule => $day instanceof WeekdayRule ? $day : new WeekdayRule($day),
            $days,
        );

        return $this->copy(['byDay' => array_values($byDay)]);
    }

    public function onMonthDays(int ...$days): self
    {
        return $this->copy(['byMonthDay' => array_values($days)]);
    }

    public function inMonths(int ...$months): self
    {
        return $this->copy(['byMonth' => array_values($months)]);
    }

    public function setPositions(int ...$positions): self
    {
        return $this->copy(['bySetPosition' => array_values($positions)]);
    }

    public function weekStartsOn(Weekday $day): self
    {
        return $this->copy(['weekStart' => $day]);
    }

    public function isInfinite(): bool
    {
        return $this->count === null && $this->until === null;
    }

    public static function parse(string $value, bool $strict = false): self
    {
        $parts = [
            'interval' => 1, 'count' => null, 'until' => null, 'weekStart' => null,
            'byDay' => [], 'byMonthDay' => [], 'byMonth' => [], 'byYearDay' => [],
            'byWeekNo' => [], 'byHour' => [], 'byMinute' => [], 'bySecond' => [], 'bySetPosition' => [],
        ];
        $frequency = null;
        $unknownParts = [];
        $knownNames = ['FREQ' => true, 'INTERVAL' => true, 'COUNT' => true, 'UNTIL' => true, 'WKST' => true, 'BYDAY' => true, 'BYMONTHDAY' => true, 'BYMONTH' => true, 'BYYEARDAY' => true, 'BYWEEKNO' => true, 'BYHOUR' => true, 'BYMINUTE' => true, 'BYSECOND' => true, 'BYSETPOS' => true];
        $seenKnown = [];

        foreach (self::segments($value) as $segment) {
            if ($segment === '') {
                throw new InvalidValueException('RRULE contains an empty segment.');
            }
            $eq = strpos($segment, '=');
            if ($eq === false) {
                throw new InvalidValueException(sprintf('Malformed RRULE segment "%s".', $segment));
            }
            $key = strtoupper(substr($segment, 0, $eq));
            $raw = substr($segment, $eq + 1);
            if (isset($knownNames[$key])) {
                if (isset($seenKnown[$key])) {
                    throw new InvalidValueException(sprintf('RRULE part "%s" occurs more than once.', $key));
                }
                $seenKnown[$key] = true;
            }

            match ($key) {
                'FREQ' => $frequency = Frequency::tryFrom(strtoupper($raw))
                    ?? throw new InvalidValueException(sprintf('Unknown RRULE FREQ "%s".', $raw)),
                'INTERVAL' => $parts['interval'] = self::int($raw),
                'COUNT' => $parts['count'] = self::int($raw),
                'UNTIL' => $parts['until'] = self::parseUntil($raw),
                'WKST' => $parts['weekStart'] = Weekday::tryFrom(strtoupper($raw))
                    ?? throw new InvalidValueException(sprintf('Unknown RRULE WKST "%s".', $raw)),
                'BYDAY' => $parts['byDay'] = array_map([WeekdayRule::class, 'parse'], explode(',', $raw)),
                'BYMONTHDAY' => $parts['byMonthDay'] = self::ints($raw),
                'BYMONTH' => $parts['byMonth'] = self::ints($raw),
                'BYYEARDAY' => $parts['byYearDay'] = self::ints($raw),
                'BYWEEKNO' => $parts['byWeekNo'] = self::ints($raw),
                'BYHOUR' => $parts['byHour'] = self::ints($raw),
                'BYMINUTE' => $parts['byMinute'] = self::ints($raw),
                'BYSECOND' => $parts['bySecond'] = self::ints($raw),
                'BYSETPOS' => $parts['bySetPosition'] = self::ints($raw),
                default => $strict
                    ? throw new InvalidValueException(sprintf('Unknown RRULE part "%s".', $key))
                    : $unknownParts[] = new RecurrencePart($key, $raw),
            };
        }

        if ($frequency === null) {
            throw new InvalidValueException('RRULE is missing the required FREQ part.');
        }

        return new self(
            $frequency,
            $parts['interval'],
            $parts['count'],
            $parts['until'],
            $parts['byDay'],
            $parts['byMonthDay'],
            $parts['byMonth'],
            $parts['byYearDay'],
            $parts['byWeekNo'],
            $parts['byHour'],
            $parts['byMinute'],
            $parts['bySecond'],
            $parts['bySetPosition'],
            $parts['weekStart'],
            $unknownParts,
        );
    }

    public function toString(): string
    {
        $segments = ['FREQ='.$this->frequency->value];

        if ($this->count !== null) {
            $segments[] = 'COUNT='.$this->count;
        }
        if ($this->until !== null) {
            $segments[] = 'UNTIL='.$this->until->toString();
        }
        if ($this->interval !== 1) {
            $segments[] = 'INTERVAL='.$this->interval;
        }
        if ($this->bySecond !== []) {
            $segments[] = 'BYSECOND='.implode(',', $this->bySecond);
        }
        if ($this->byMinute !== []) {
            $segments[] = 'BYMINUTE='.implode(',', $this->byMinute);
        }
        if ($this->byHour !== []) {
            $segments[] = 'BYHOUR='.implode(',', $this->byHour);
        }
        if ($this->byDay !== []) {
            $segments[] = 'BYDAY='.implode(',', array_map(static fn (WeekdayRule $r): string => $r->toString(), $this->byDay));
        }
        if ($this->byMonthDay !== []) {
            $segments[] = 'BYMONTHDAY='.implode(',', $this->byMonthDay);
        }
        if ($this->byYearDay !== []) {
            $segments[] = 'BYYEARDAY='.implode(',', $this->byYearDay);
        }
        if ($this->byWeekNo !== []) {
            $segments[] = 'BYWEEKNO='.implode(',', $this->byWeekNo);
        }
        if ($this->byMonth !== []) {
            $segments[] = 'BYMONTH='.implode(',', $this->byMonth);
        }
        if ($this->bySetPosition !== []) {
            $segments[] = 'BYSETPOS='.implode(',', $this->bySetPosition);
        }
        if ($this->weekStart !== null) {
            $segments[] = 'WKST='.$this->weekStart->value;
        }
        foreach ($this->unknownParts as $part) {
            $segments[] = $part->toString();
        }

        return implode(';', $segments);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @param array{
     *     frequency?: Frequency,
     *     interval?: int,
     *     count?: int|null,
     *     until?: DateTimeValue|null,
     *     byDay?: list<WeekdayRule>,
     *     byMonthDay?: list<int>,
     *     byMonth?: list<int>,
     *     byYearDay?: list<int>,
     *     byWeekNo?: list<int>,
     *     byHour?: list<int>,
     *     byMinute?: list<int>,
     *     bySecond?: list<int>,
     *     bySetPosition?: list<int>,
     *     weekStart?: Weekday|null,
     *     unknownParts?: list<RecurrencePart>
     * } $changes
     */
    private function copy(array $changes): self
    {
        return new self(
            $changes['frequency'] ?? $this->frequency,
            $changes['interval'] ?? $this->interval,
            array_key_exists('count', $changes) ? $changes['count'] : $this->count,
            array_key_exists('until', $changes) ? $changes['until'] : $this->until,
            $changes['byDay'] ?? $this->byDay,
            $changes['byMonthDay'] ?? $this->byMonthDay,
            $changes['byMonth'] ?? $this->byMonth,
            $changes['byYearDay'] ?? $this->byYearDay,
            $changes['byWeekNo'] ?? $this->byWeekNo,
            $changes['byHour'] ?? $this->byHour,
            $changes['byMinute'] ?? $this->byMinute,
            $changes['bySecond'] ?? $this->bySecond,
            $changes['bySetPosition'] ?? $this->bySetPosition,
            array_key_exists('weekStart', $changes) ? $changes['weekStart'] : $this->weekStart,
            $changes['unknownParts'] ?? $this->unknownParts,
        );
    }

    /** @param array<array-key, mixed> $values */
    private static function validateList(string $part, array $values, int $min, int $max, bool $nonZero = false): void
    {
        foreach ($values as $value) {
            if (! is_int($value)) {
                throw new InvalidValueException(sprintf('RRULE %s values must be integers.', $part));
            }
            if ($value < $min || $value > $max || ($nonZero && $value === 0)) {
                throw new InvalidValueException(sprintf('RRULE %s value %d is outside its RFC-defined range.', $part, $value));
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  class-string  $class
     */
    private static function validateInstances(array $values, string $class, string $message): void
    {
        foreach ($values as $value) {
            if (! $value instanceof $class) {
                throw new InvalidValueException($message);
            }
        }
    }

    private static function int(string $value): int
    {
        if (preg_match('/^[+-]?\d+$/', $value) !== 1) {
            throw new InvalidValueException(sprintf('Expected an integer in RRULE, got "%s".', $value));
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = $negative ? '2147483648' : '2147483647';
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new InvalidValueException(sprintf('RRULE integer "%s" is outside the signed 32-bit range.', $value));
        }

        return (int) $value;
    }

    /** @return list<int> */
    private static function ints(string $value): array
    {
        return array_map(self::int(...), explode(',', $value));
    }

    /** @return list<string> */
    private static function segments(string $value): array
    {
        $segments = [];
        $segment = '';
        $escaped = false;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $character = $value[$i];
            if ($character === ';' && ! $escaped) {
                $segments[] = $segment;
                $segment = '';

                continue;
            }

            $segment .= $character;
            $escaped = $character === '\\' ? ! $escaped : false;
        }
        $segments[] = $segment;

        return $segments;
    }

    private static function parseUntil(string $value): DateTimeValue
    {
        if (! str_contains($value, 'T')) {
            $date = \DateTimeImmutable::createFromFormat('!Ymd', $value, new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Ymd') !== $value) {
                throw new InvalidValueException(sprintf('Malformed RRULE UNTIL "%s".', $value));
            }

            return DateTimeValue::date($date);
        }

        $isUtc = str_ends_with($value, 'Z') || str_ends_with($value, 'z');
        $literal = $isUtc ? substr($value, 0, -1) : $value;
        $dateTime = \DateTimeImmutable::createFromFormat('!Ymd\THis', $literal, new \DateTimeZone('UTC'));
        if ($dateTime === false || $dateTime->format('Ymd\THis') !== $literal) {
            throw new InvalidValueException(sprintf('Malformed RRULE UNTIL "%s".', $value));
        }

        return $isUtc ? DateTimeValue::utc($dateTime) : DateTimeValue::floating($dateTime);
    }
}
