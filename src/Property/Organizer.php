<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Property;

use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\ValueType\CalAddress;
use Erenav\ICalendar\ValueType\UriValue;

/**
 * A typed read view over an ORGANIZER property (RFC 5545 §3.8.4.3). The
 * underlying {@see Property} stays available via {@see self::$property}.
 */
final readonly class Organizer
{
    public function __construct(
        public Property $property,
    ) {}

    public function address(): CalAddress
    {
        $value = $this->property->value();

        return $value instanceof CalAddress ? $value : CalAddress::fromUri($value->toString());
    }

    public function email(): ?string
    {
        return $this->address()->email();
    }

    public function commonName(): ?string
    {
        return $this->rawParameter('CN');
    }

    public function sentBy(): ?string
    {
        return $this->rawParameter('SENT-BY');
    }

    /** Typed companion to the backward-compatible string accessor. */
    public function sentByAddress(): ?CalAddress
    {
        $value = $this->sentBy();

        if ($value === null) {
            return null;
        }

        try {
            return CalAddress::fromUri($value);
        } catch (InvalidValueException) {
            return null;
        }
    }

    public function directory(): ?string
    {
        return $this->rawParameter('DIR');
    }

    public function directoryUri(): ?UriValue
    {
        $value = $this->directory();

        if ($value === null) {
            return null;
        }

        try {
            return new UriValue($value);
        } catch (InvalidValueException) {
            return null;
        }
    }

    public function language(): ?string
    {
        return $this->rawParameter('LANGUAGE');
    }

    private function rawParameter(string $name): ?string
    {
        $parameter = $this->property->parameter($name);

        return $parameter instanceof RawParameter && count($parameter->values) === 1
            ? $parameter->value()
            : null;
    }
}
