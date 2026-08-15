<?php

declare(strict_types=1);

namespace Erenav\ICalendar\ValueType;

use Erenav\ICalendar\Exception\InvalidValueException;

/**
 * An RFC 5545 UTC-OFFSET value (§3.3.14), e.g. `+0500`, `-0730`, `+053045`.
 *
 * Used by the TZOFFSETFROM / TZOFFSETTO properties of VTIMEZONE. Stored as a
 * signed second count; the seconds component is only emitted when non-zero.
 */
final readonly class UtcOffset implements Value
{
    private function __construct(
        public int $totalSeconds,
    ) {
        if (abs($totalSeconds) > 86400) {
            throw new InvalidValueException('A UTC offset cannot exceed 23:59:60.');
        }
    }

    public static function fromSeconds(int $seconds): self
    {
        return new self($seconds);
    }

    public static function of(int $hours, int $minutes, int $seconds = 0, bool $negative = false): self
    {
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59 || $seconds < 0 || $seconds > 60) {
            throw new InvalidValueException('UTC offset components must be within RFC ranges; use the $negative flag for sign.');
        }

        $total = $hours * 3600 + $minutes * 60 + $seconds;

        return new self($negative ? -$total : $total);
    }

    public static function parse(string $value): self
    {
        if (! preg_match('/^(?<sign>[+-])(?<h>\d{2})(?<m>\d{2})(?<s>\d{2})?$/', $value, $m)) {
            throw new InvalidValueException(sprintf('Malformed UTC offset "%s".', $value));
        }

        $total = ((int) $m['h']) * 3600 + ((int) $m['m']) * 60 + (int) ($m['s'] ?? 0);
        $negative = $m['sign'] === '-';

        if ((int) $m['h'] > 23 || (int) $m['m'] > 59 || (int) ($m['s'] ?? 0) > 60) {
            throw new InvalidValueException(sprintf('UTC offset "%s" contains a component outside its RFC range.', $value));
        }

        if ($negative && $total === 0) {
            throw new InvalidValueException('A negative-zero UTC offset ("-0000") is not allowed.');
        }

        return new self($negative ? -$total : $total);
    }

    public function toString(): string
    {
        $total = abs($this->totalSeconds);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $seconds = $total % 60;

        if ($total === 86400) {
            return ($this->totalSeconds < 0 ? '-' : '+').'235960';
        }

        $out = sprintf('%s%02d%02d', $this->totalSeconds < 0 ? '-' : '+', $hours, $minutes);
        if ($seconds !== 0) {
            $out .= sprintf('%02d', $seconds);
        }

        return $out;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function equals(self $other): bool
    {
        return $this->totalSeconds === $other->totalSeconds;
    }
}
