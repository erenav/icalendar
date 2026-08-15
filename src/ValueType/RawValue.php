<?php

declare(strict_types=1);

namespace Erenav\ICalendar\ValueType;

/**
 * An unmodelled property value retained after line unfolding and before any
 * value-type interpretation. The serializer emits the logical raw value without
 * value-level re-escaping; content-line folding and surrounding syntax may be
 * canonicalized.
 */
final readonly class RawValue implements Value
{
    public function __construct(
        public string $raw,
    ) {}

    public function toString(): string
    {
        return $this->raw;
    }

    public function __toString(): string
    {
        return $this->raw;
    }

    public function equals(self $other): bool
    {
        return $this->raw === $other->raw;
    }
}
