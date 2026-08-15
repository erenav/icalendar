<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Property;

use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\Parameter\CuType;
use Erenav\ICalendar\Parameter\PartStat;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Parameter\Role;
use Erenav\ICalendar\ValueType\CalAddress;
use Erenav\ICalendar\ValueType\UriValue;

/**
 * A typed read view over an ATTENDEE property (RFC 5545 §3.8.4.1): the calendar
 * address plus its common parameters. The underlying {@see Property} stays
 * available via {@see self::$property} for anything not surfaced here.
 */
final readonly class Attendee
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

    public function role(): ?Role
    {
        $role = $this->property->parameter('ROLE');

        return match (true) {
            $role instanceof Role => $role,
            $role instanceof RawParameter && count($role->values) === 1 => Role::tryFrom(strtoupper($role->value())),
            default => null,
        };
    }

    public function participationStatus(): ?PartStat
    {
        $status = $this->property->parameter('PARTSTAT');

        return match (true) {
            $status instanceof PartStat => $status,
            $status instanceof RawParameter && count($status->values) === 1 => PartStat::tryFrom(strtoupper($status->value())),
            default => null,
        };
    }

    public function userType(): ?CuType
    {
        $type = $this->property->parameter('CUTYPE');

        return match (true) {
            $type instanceof CuType => $type,
            $type instanceof RawParameter && count($type->values) === 1 => CuType::tryFrom(strtoupper($type->value())),
            default => null,
        };
    }

    public function rsvp(): ?bool
    {
        $rsvp = $this->rawParameter('RSVP');

        return match ($rsvp === null ? null : strtoupper($rsvp)) {
            'TRUE' => true,
            'FALSE' => false,
            default => null,
        };
    }

    /** @return list<CalAddress> */
    public function delegatedTo(): array
    {
        return $this->addressList('DELEGATED-TO');
    }

    /** @return list<CalAddress> */
    public function delegatedFrom(): array
    {
        return $this->addressList('DELEGATED-FROM');
    }

    /** @return list<CalAddress> */
    public function members(): array
    {
        return $this->addressList('MEMBER');
    }

    public function sentBy(): ?CalAddress
    {
        return $this->addressParameter('SENT-BY');
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

    /** @return list<CalAddress> */
    private function addressList(string $name): array
    {
        $parameter = $this->property->parameter($name);
        if (! $parameter instanceof RawParameter) {
            return [];
        }

        $addresses = [];
        foreach ($parameter->values as $value) {
            try {
                $addresses[] = CalAddress::fromUri($value);
            } catch (InvalidValueException) {
                // Lenient parsing preserves malformed external values in the
                // RawParameter; typed access exposes only valid addresses.
            }
        }

        return $addresses;
    }

    private function addressParameter(string $name): ?CalAddress
    {
        $value = $this->rawParameter($name);

        if ($value === null) {
            return null;
        }

        try {
            return CalAddress::fromUri($value);
        } catch (InvalidValueException) {
            return null;
        }
    }

    private function rawParameter(string $name): ?string
    {
        $parameter = $this->property->parameter($name);

        return $parameter instanceof RawParameter && count($parameter->values) === 1
            ? $parameter->value()
            : null;
    }
}
