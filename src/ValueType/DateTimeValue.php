<?php

declare(strict_types=1);

namespace Erenav\ICalendar\ValueType;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\TimeZone\TimeZoneResolver;

/**
 * An RFC 5545 DATE or DATE-TIME value (§3.3.4 / §3.3.5).
 *
 * Wraps {@see DateTimeImmutable} because iCalendar needs to carry semantics the
 * native type cannot: the VALUE=DATE vs DATE-TIME distinction, and which of the
 * three DATE-TIME forms applies — floating (no zone), UTC (`Z` suffix), or a
 * named TZID. The TZID is emitted by the property layer as a parameter; this
 * type produces only the value literal.
 */
final readonly class DateTimeValue implements Value
{
    private function __construct(
        public DateTimeImmutable $dateTime,
        public bool $isDateOnly,
        public bool $isUtc,
        public ?string $tzid,
        private ?string $wallClockLiteral = null,
        private bool $instantResolved = true,
    ) {
        if ($isDateOnly && ($isUtc || $tzid !== null)) {
            throw new InvalidValueException('A DATE value cannot be UTC or carry a TZID.');
        }
        if ($isUtc && $tzid !== null) {
            throw new InvalidValueException('A DATE-TIME cannot be both UTC and carry a TZID.');
        }
    }

    /** A date-only value (VALUE=DATE). */
    public static function date(DateTimeInterface $dateTime): self
    {
        return new self(self::wallClock($dateTime, true), true, false, null);
    }

    /** A UTC date-time (the `Z` form). The instant is converted to UTC. */
    public static function utc(DateTimeInterface $dateTime): self
    {
        $utc = self::immutable($dateTime)->setTimezone(new DateTimeZone('UTC'));
        // RFC 5545 DATE-TIME has whole-second precision. Keep the public native
        // backing value identical to the instant that toString() can export.
        $utc = $utc->setTime(
            (int) $utc->format('H'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
        );

        return new self($utc, false, true, null);
    }

    /** A floating date-time (no timezone reference). */
    public static function floating(DateTimeInterface $dateTime): self
    {
        // DATE and floating DATE-TIME values have no absolute timezone. Keep a
        // UTC backing value solely as a stable wall-clock container so callers'
        // process/default timezones cannot change comparisons or arithmetic.
        return new self(self::wallClock($dateTime), false, false, null);
    }

    /** A date-time anchored to a named time zone (TZID parameter). */
    public static function zoned(DateTimeInterface $dateTime, string $tzid): self
    {
        if ($tzid === '') {
            throw new InvalidValueException('TZID cannot be empty.');
        }

        $wallClock = self::wallClock($dateTime);

        try {
            $zone = new DateTimeZone($tzid);
        } catch (\DateInvalidTimeZoneException) {
            // A VTIMEZONE-defined identifier need not exist in PHP's timezone
            // database. Preserve its wall clock and identifier losslessly; its
            // offset cannot be resolved without interpreting that component.
            return new self($wallClock, false, false, $tzid, null, false);
        }

        $resolved = self::resolveWallClock($wallClock, $zone);
        $literal = $wallClock->format('Ymd\THis');

        return new self(
            $resolved,
            false,
            false,
            $tzid,
            $resolved->format('Ymd\THis') === $literal ? null : $literal,
        );
    }

    /**
     * Build a TZID value whose wall fields were resolved by an embedded
     * VTIMEZONE rather than PHP's IANA database.
     *
     * @internal Calendar-scoped timezone resolution should normally create it.
     */
    public static function resolvedZoned(
        DateTimeInterface $instant,
        DateTimeInterface $wallClock,
        string $tzid,
    ): self {
        if ($tzid === '') {
            throw new InvalidValueException('TZID cannot be empty.');
        }

        $resolved = self::immutable($instant)->setTimezone(new DateTimeZone('UTC'));
        $resolved = $resolved->setTime(
            (int) $resolved->format('H'),
            (int) $resolved->format('i'),
            (int) $resolved->format('s'),
        );

        return new self(
            $resolved,
            false,
            false,
            $tzid,
            self::wallClock($wallClock)->format('Ymd\THis'),
            true,
        );
    }

    /**
     * Infer the appropriate form from a date-time's own timezone. Pass
     * $dateOnly to force a DATE value. Offset-only zones (e.g. "+05:00"), which
     * cannot be valid TZID names, are normalised to the UTC instant.
     */
    public static function fromDateTime(DateTimeInterface $dateTime, bool $dateOnly = false): self
    {
        if ($dateOnly) {
            return self::date($dateTime);
        }

        $name = $dateTime->getTimezone()->getName();

        if ($name === 'UTC' || $name === 'Z' || $name === '+00:00') {
            return self::utc($dateTime);
        }

        if ($name === 'GMT' || preg_match('#^[A-Za-z][A-Za-z0-9_+\-]*(/[A-Za-z0-9_+\-]+)+$#', $name) === 1) {
            return self::zoned($dateTime, $name);
        }

        return self::utc($dateTime);
    }

    /** The value literal, without any TZID parameter (which the property emits). */
    public function toString(): string
    {
        if ($this->isDateOnly) {
            return $this->dateTime->format('Ymd');
        }

        if ($this->isUtc) {
            return $this->dateTime->format('Ymd\THis\Z');
        }

        return $this->wallClockLiteral ?? $this->dateTime->format('Ymd\THis');
    }

    public function needsTzidParameter(): bool
    {
        return $this->tzid !== null;
    }

    public function isFloating(): bool
    {
        return ! $this->isDateOnly && ! $this->isUtc && $this->tzid === null;
    }

    /** Whether this value has an absolute instant, including embedded-TZID resolution. */
    public function hasResolvedInstant(): bool
    {
        return $this->isUtc || ($this->tzid !== null && $this->instantResolved);
    }

    /**
     * Apply an RFC 5545 duration in this value's own date/time form.
     *
     * Day/week components use wall-clock arithmetic before the smaller exact
     * time components. For an embedded/custom TZID, use
     * {@see TimeZoneResolver::addDuration()} so its VTIMEZONE can participate.
     */
    public function adding(Duration $duration): self
    {
        if ($duration->isZero()) {
            return $this;
        }

        if ($this->tzid === null) {
            $shifted = $this->dateTime->add($duration->toDateInterval());

            return match (true) {
                $this->isDateOnly => self::date($shifted),
                $this->isUtc => self::utc($shifted),
                default => self::floating($shifted),
            };
        }

        try {
            $zone = new DateTimeZone($this->tzid);
        } catch (\DateInvalidTimeZoneException) {
            if ($this->instantResolved) {
                throw new InvalidValueException(sprintf(
                    'Applying a duration to resolved custom TZID "%s" requires its TimeZoneResolver.',
                    $this->tzid,
                ));
            }

            // Without a VTIMEZONE only the nominal wall result is knowable.
            // Keep it explicitly unresolved instead of inventing an instant.
            $wall = self::wallClockFromLiteral($this->toString())
                ->add($duration->toDateInterval());

            return self::zoned($wall, $this->tzid);
        }

        $sign = $duration->negative ? -1 : 1;
        $days = ($duration->weeks * 7 + $duration->days) * $sign;
        $seconds = ($duration->hours * 3600 + $duration->minutes * 60 + $duration->seconds) * $sign;
        $resolved = $this;

        if ($days !== 0) {
            $wall = self::wallClockFromLiteral($this->toString())
                ->modify(sprintf('%+d days', $days));
            $resolved = self::zoned($wall, $this->tzid);
        }

        if ($seconds === 0) {
            return $resolved;
        }

        $instant = $resolved->dateTime->setTimestamp($resolved->dateTime->getTimestamp() + $seconds);
        $candidate = self::zoned($instant->setTimezone($zone), $this->tzid);

        // A local TZID literal always denotes the first fold occurrence. Keep
        // an elapsed duration's second-fold result exact by exporting it in UTC.
        return $candidate->dateTime->getTimestamp() === $instant->getTimestamp()
            ? $candidate
            : self::utc($instant);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function equals(self $other): bool
    {
        return $this->isDateOnly === $other->isDateOnly
            && $this->isUtc === $other->isUtc
            && $this->tzid === $other->tzid
            && $this->toString() === $other->toString();
    }

    private static function immutable(DateTimeInterface $dateTime): DateTimeImmutable
    {
        return $dateTime instanceof DateTimeImmutable
            ? $dateTime
            : DateTimeImmutable::createFromInterface($dateTime);
    }

    /**
     * Copy displayed calendar fields without carrying the source's timezone.
     *
     * DATE and floating values describe fields, not an instant. The same is
     * true of the input to zoned(): the supplied fields are interpreted in the
     * explicitly supplied TZID rather than converted from a hidden source zone.
     */
    private static function wallClock(DateTimeInterface $dateTime, bool $dateOnly = false): DateTimeImmutable
    {
        $literal = $dateTime->format($dateOnly ? 'Ymd' : 'Ymd\THis');
        $format = $dateOnly ? '!Ymd' : '!Ymd\THis';
        $wallClock = DateTimeImmutable::createFromFormat($format, $literal, new DateTimeZone('UTC'));

        if ($wallClock === false) {
            throw new InvalidValueException('Could not represent the supplied calendar date/time.');
        }

        return $wallClock;
    }

    private static function wallClockFromLiteral(string $literal): DateTimeImmutable
    {
        $wallClock = DateTimeImmutable::createFromFormat('!Ymd\THis', $literal, new DateTimeZone('UTC'));
        if ($wallClock === false || $wallClock->format('Ymd\THis') !== $literal) {
            throw new InvalidValueException('Could not represent the supplied zoned wall-clock value.');
        }

        return $wallClock;
    }

    /**
     * Resolve local fields according to RFC 5545 section 3.3.5.
     *
     * Ambiguous local times select the first occurrence. Nonexistent times in
     * a forward offset transition use the UTC offset in force before the gap.
     */
    private static function resolveWallClock(DateTimeImmutable $wallClock, DateTimeZone $zone): DateTimeImmutable
    {
        $wallTimestamp = $wallClock->getTimestamp();
        $transitions = $zone->getTransitions($wallTimestamp - 172800, $wallTimestamp + 172800);

        // Fixed-offset DateTimeZone instances do not expose transitions.
        if (! $transitions) {
            $resolved = DateTimeImmutable::createFromFormat('!Ymd\THis', $wallClock->format('Ymd\THis'), $zone);
            if ($resolved === false) {
                throw new InvalidValueException('Could not resolve the supplied zoned date/time.');
            }

            return $resolved;
        }

        /** @var array<int, true> $offsets */
        $offsets = [];
        foreach ($transitions as $transition) {
            $offsets[$transition['offset']] = true;
        }

        /** @var list<int> $candidateTimestamps */
        $candidateTimestamps = [];
        foreach (array_keys($offsets) as $offset) {
            $candidateTimestamp = $wallTimestamp - $offset;
            $candidate = (new DateTimeImmutable('@'.$candidateTimestamp))->setTimezone($zone);
            if ($candidate->format('Ymd\THis') === $wallClock->format('Ymd\THis')) {
                $candidateTimestamps[] = $candidateTimestamp;
            }
        }

        if ($candidateTimestamps !== []) {
            // The lower UTC timestamp is the first occurrence of an ambiguous
            // local time, as required by RFC 5545.
            sort($candidateTimestamps, SORT_NUMERIC);

            return (new DateTimeImmutable('@'.$candidateTimestamps[0]))->setTimezone($zone);
        }

        $previousOffset = $transitions[0]['offset'];
        foreach (array_slice($transitions, 1) as $transition) {
            $newOffset = $transition['offset'];
            $transitionTimestamp = $transition['ts'];
            $gapStart = $transitionTimestamp + $previousOffset;
            $gapEnd = $transitionTimestamp + $newOffset;

            if ($newOffset > $previousOffset && $wallTimestamp >= $gapStart && $wallTimestamp < $gapEnd) {
                $instant = $wallTimestamp - $previousOffset;

                return (new DateTimeImmutable('@'.$instant))->setTimezone($zone);
            }

            $previousOffset = $newOffset;
        }

        throw new InvalidValueException(sprintf(
            'Could not resolve local time %s in timezone %s.',
            $wallClock->format('Y-m-d H:i:s'),
            $zone->getName(),
        ));
    }
}
