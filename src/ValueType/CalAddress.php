<?php

declare(strict_types=1);

namespace Erenav\ICalendar\ValueType;

use Erenav\ICalendar\Exception\InvalidValueException;

/**
 * An RFC 5545 CAL-ADDRESS value (§3.3.3): a URI identifying a calendar user,
 * in practice almost always a `mailto:` address. Used as the value of the
 * ORGANIZER and ATTENDEE properties (their CN/ROLE/etc. are parameters, handled
 * by the property layer, not part of this value).
 */
final readonly class CalAddress implements Value
{
    private function __construct(
        public string $uri,
    ) {}

    /** Build from a bare email address, prefixing the `mailto:` scheme. */
    public static function fromEmail(string $email): self
    {
        self::rejectLineBreaks($email);
        $email = trim($email);
        if ($email === '') {
            throw new InvalidValueException('Email address cannot be empty.');
        }

        return new self('mailto:'.$email);
    }

    /** Build from any URI (must include a scheme). */
    public static function fromUri(string $uri): self
    {
        self::rejectLineBreaks($uri);
        $uri = trim($uri);
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:/', $uri) !== 1) {
            throw new InvalidValueException(sprintf('Cal-address "%s" is not a valid URI (missing scheme).', $uri));
        }

        return new self($uri);
    }

    public static function parse(string $value): self
    {
        return self::fromUri($value);
    }

    /** The bare email address if this is a `mailto:` URI, otherwise null. */
    public function email(): ?string
    {
        return preg_match('/^mailto:(.+)$/i', $this->uri, $m) === 1 ? $m[1] : null;
    }

    public function toString(): string
    {
        return $this->uri;
    }

    public function __toString(): string
    {
        return $this->uri;
    }

    public function equals(self $other): bool
    {
        return $this->uri === $other->uri;
    }

    private static function rejectLineBreaks(string $value): void
    {
        if (strpbrk($value, "\r\n") !== false) {
            throw new InvalidValueException('A calendar address cannot contain a carriage return or line feed.');
        }
    }
}
