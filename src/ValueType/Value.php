<?php

declare(strict_types=1);

namespace Erenav\ICalendar\ValueType;

/**
 * Implemented by every RFC 5545 value-type representation that can be the value
 * of a property (DATE-TIME, DURATION, TEXT, INTEGER, …), including the
 * property-value enums (EventStatus, Transparency, …).
 *
 * `toString()` returns the *logical* value; wire-level escaping (for TEXT) and
 * folding are applied by the serializer, except for {@see RawValue}, whose
 * logical value is emitted without value-level re-escaping.
 *
 * Note: this interface intentionally does not extend Stringable — enums cannot
 * declare __toString, and they need to implement Value.
 */
interface Value
{
    public function toString(): string;
}
