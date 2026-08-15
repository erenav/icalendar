<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Parameter;

use Erenav\ICalendar\Exception\InvalidValueException;

/**
 * A property parameter we don't model as a typed enum — an experimental `X-`
 * parameter, an unregistered IANA token, or a known parameter carrying an
 * unrecognised value. Logical values are retained for canonical semantic
 * re-export; original quoting, escaping, and byte layout are not retained.
 *
 * Holds the logical (already unescaped/unquoted) values; quoting and escaping
 * on the wire are the serializer's concern.
 */
final readonly class RawParameter
{
    /** @var list<string> */
    public array $values;

    public string $name;

    public function __construct(string $name, string ...$values)
    {
        $name = strtoupper(trim($name));

        if ($name === '') {
            throw new InvalidValueException('A parameter name cannot be empty.');
        }
        if (preg_match('/^[A-Za-z0-9\-]+$/', $name) !== 1) {
            throw new InvalidValueException(sprintf('Invalid parameter name "%s".', $name));
        }
        if ($values === []) {
            throw new InvalidValueException(sprintf('Parameter "%s" must have at least one value.', $name));
        }

        $this->name = $name;
        $this->values = array_values($values);
    }

    /** The first (often only) value. */
    public function value(): string
    {
        return $this->values[0];
    }

    /** True for experimental `X-` parameters. */
    public function isExperimental(): bool
    {
        return str_starts_with($this->name, 'X-');
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name && $this->values === $other->values;
    }
}
