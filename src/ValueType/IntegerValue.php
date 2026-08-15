<?php

declare(strict_types=1);

namespace Erenav\ICalendar\ValueType;

use Erenav\ICalendar\Exception\InvalidValueException;

/**
 * An RFC 5545 INTEGER value (§3.3.8), e.g. the PRIORITY or PERCENT-COMPLETE
 * property value.
 */
final readonly class IntegerValue implements Value
{
    public function __construct(
        public int $value,
    ) {
        if ($value < -2147483648 || $value > 2147483647) {
            throw new InvalidValueException('INTEGER must be within the RFC-defined signed 32-bit range.');
        }
    }

    public static function parse(string $value): self
    {
        $value = trim($value);
        if (preg_match('/^[+-]?\d+$/', $value) !== 1) {
            throw new InvalidValueException(sprintf('Malformed INTEGER value "%s".', $value));
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = $negative ? '2147483648' : '2147483647';
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new InvalidValueException(sprintf('INTEGER value "%s" is outside the signed 32-bit range.', $value));
        }

        return new self((int) $value);
    }

    public function toString(): string
    {
        return (string) $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
