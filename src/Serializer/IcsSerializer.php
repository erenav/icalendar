<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Serializer;

use Erenav\ICalendar\Component\Component;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\Exception\MissingPropertyException;
use Erenav\ICalendar\Parameter\ParameterValue;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\ValueType\BinaryValue;
use Erenav\ICalendar\ValueType\BooleanValue;
use Erenav\ICalendar\ValueType\CalAddress;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use Erenav\ICalendar\ValueType\GeoValue;
use Erenav\ICalendar\ValueType\IntegerValue;
use Erenav\ICalendar\ValueType\Period;
use Erenav\ICalendar\ValueType\TextValue;
use Erenav\ICalendar\ValueType\UriValue;
use Erenav\ICalendar\ValueType\UtcOffset;
use Erenav\ICalendar\ValueType\Value;

/**
 * Renders a component tree to RFC 5545 iCalendar text (CRLF line endings,
 * 75-octet folding). TZID / VALUE / ENCODING parameters are derived from the
 * value itself — the {@see DateTimeValue} (etc.) is the single source of truth —
 * so the builder never has to manage them by hand.
 *
 * Lenient by default; pass `strict: true` to enforce required properties.
 */
final class IcsSerializer implements Serializer
{
    /** Required properties enforced only in strict mode, keyed by wire name. */
    private const REQUIRED = [
        'VCALENDAR' => ['VERSION', 'PRODID'],
        'VEVENT' => ['UID', 'DTSTAMP'],
        'VALARM' => ['ACTION', 'TRIGGER'],
    ];

    /**
     * A property's default value type. A VALUE parameter is emitted only when
     * the actual value's type differs from this (e.g. a DATE-TIME TRIGGER,
     * since TRIGGER defaults to DURATION).
     */
    private const DEFAULT_VALUE_TYPE = [
        'DTSTART' => 'DATE-TIME', 'DTEND' => 'DATE-TIME', 'DTSTAMP' => 'DATE-TIME',
        'DUE' => 'DATE-TIME', 'CREATED' => 'DATE-TIME', 'LAST-MODIFIED' => 'DATE-TIME',
        'RECURRENCE-ID' => 'DATE-TIME', 'EXDATE' => 'DATE-TIME', 'RDATE' => 'DATE-TIME',
        'COMPLETED' => 'DATE-TIME', 'ACKNOWLEDGED' => 'DATE-TIME',
        'DURATION' => 'DURATION', 'TRIGGER' => 'DURATION', 'REFRESH-INTERVAL' => 'DURATION',
        'ATTACH' => 'URI', 'URL' => 'URI', 'SOURCE' => 'URI', 'IMAGE' => 'URI',
        'CONFERENCE' => 'URI', 'TZURL' => 'URI',
    ];

    public function __construct(
        private readonly bool $strict = false,
    ) {}

    public function serialize(Component $component): string
    {
        if ($this->strict) {
            $this->validate($component);
        }

        return $this->serializeComponent($component);
    }

    private function serializeComponent(Component $component): string
    {
        $wireName = $component->wireName();
        $out = 'BEGIN:'.$wireName."\r\n";

        foreach ($component->properties as $property) {
            $out .= $this->fold($this->serializeProperty($property))."\r\n";
        }

        foreach ($component->children as $child) {
            $out .= $this->serializeComponent($child);
        }

        return $out.'END:'.$wireName."\r\n";
    }

    private function serializeProperty(Property $property): string
    {
        $line = $property->name;

        foreach ($this->parametersFor($property) as $name => $value) {
            $line .= ';'.$name.'='.$value;
        }

        $serialized = implode(',', array_map(
            fn (Value $value): string => $this->serializeValue($value),
            $property->values,
        ));

        return $line.':'.$serialized;
    }

    /**
     * The full parameter set for a property: explicit parameters overlaid with
     * those derived from the value (TZID, VALUE, ENCODING).
     *
     * @return array<string, string>
     */
    private function parametersFor(Property $property): array
    {
        $parameters = [];

        foreach ($property->parameters as $parameter) {
            $parameters[$this->parameterName($parameter)] = $this->parameterValue($parameter);
        }

        $this->assertExplicitControllingParametersMatch($property);

        foreach ($this->derivedParameters($property) as $name => $value) {
            $parameters[$name] = $value;
        }

        return $parameters;
    }

    /** @return array<string, string> */
    private function derivedParameters(Property $property): array
    {
        $derived = null;
        $signature = null;

        foreach ($property->values as $value) {
            $candidate = $this->derivedParametersForValue($property, $value);
            $candidateSignature = $this->controllingParameterSignature($value);
            if ($signature !== null && $candidateSignature !== $signature) {
                throw new InvalidValueException(sprintf(
                    'Property "%s" contains values that require incompatible VALUE, TZID, or ENCODING parameters.',
                    $property->name,
                ));
            }
            $derived ??= $candidate;
            $signature = $candidateSignature;
        }

        return $derived ?? [];
    }

    /**
     * Do not let an explicit parameter reinterpret a typed value on the wire.
     * Raw and otherwise unknown values are deliberately exempt: their explicit
     * parameters are the only interpretation metadata available for semantic
     * re-export.
     */
    private function assertExplicitControllingParametersMatch(Property $property): void
    {
        $value = $property->value();
        $valueType = $this->valueTypeToken($value);
        if ($valueType === null) {
            return;
        }

        $this->assertExplicitParameter(
            $property,
            'VALUE',
            $valueType,
            caseInsensitive: true,
        );

        $tzid = match (true) {
            $value instanceof DateTimeValue => $value->tzid,
            $value instanceof Period => $value->start->tzid,
            default => null,
        };
        $this->assertExplicitParameter($property, 'TZID', $tzid);

        $this->assertExplicitParameter(
            $property,
            'ENCODING',
            $value instanceof BinaryValue ? 'BASE64' : '8BIT',
            caseInsensitive: true,
        );
    }

    private function assertExplicitParameter(
        Property $property,
        string $name,
        ?string $expected,
        bool $caseInsensitive = false,
    ): void {
        $parameter = $property->parameter($name);
        if ($parameter === null) {
            return;
        }

        $values = $parameter instanceof RawParameter
            ? $parameter->values
            : [$parameter->token()];
        $actual = count($values) === 1 ? $values[0] : null;
        $matches = $expected !== null
            && $actual !== null
            && ($caseInsensitive ? strcasecmp($actual, $expected) === 0 : $actual === $expected);

        if (! $matches) {
            throw new InvalidValueException(sprintf(
                'Property "%s" has an explicit %s parameter that contradicts its typed value.',
                $property->name,
                $name,
            ));
        }
    }

    /**
     * Parameters intrinsically required to interpret one typed value. VALUE is
     * included even when the property's RFC default lets the serializer omit
     * it, so unlike types cannot be hidden by sharing an omitted default.
     *
     * @return array<string, string>
     */
    private function controllingParameterSignature(Value $value): array
    {
        $signature = [];

        if ($value instanceof DateTimeValue && $value->tzid !== null) {
            $signature['TZID'] = $value->tzid;
        }
        if ($value instanceof Period && $value->start->tzid !== null) {
            $signature['TZID'] = $value->start->tzid;
        }
        if ($value instanceof BinaryValue) {
            $signature['ENCODING'] = 'BASE64';
        }
        if (($token = $this->valueTypeToken($value)) !== null) {
            $signature['VALUE'] = $token;
        }

        return $signature;
    }

    /** @return array<string, string> */
    private function derivedParametersForValue(Property $property, Value $value): array
    {
        $derived = [];

        if ($value instanceof DateTimeValue && $value->tzid !== null) {
            $derived['TZID'] = $this->encodeParameterValue($value->tzid);
        }

        if ($value instanceof Period && $value->start->tzid !== null) {
            $derived['TZID'] = $this->encodeParameterValue($value->start->tzid);
        }

        if ($value instanceof BinaryValue) {
            $derived['ENCODING'] = 'BASE64';
        }

        $token = $this->valueTypeToken($value);
        if ($token !== null && (
            $property->name === 'REFRESH-INTERVAL'
            || $token !== (self::DEFAULT_VALUE_TYPE[$property->name] ?? $token)
        )) {
            $derived['VALUE'] = $token;
        }

        return $derived;
    }

    private function valueTypeToken(Value $value): ?string
    {
        return match (true) {
            $value instanceof DateTimeValue => $value->isDateOnly ? 'DATE' : 'DATE-TIME',
            $value instanceof Duration => 'DURATION',
            $value instanceof Period => 'PERIOD',
            $value instanceof BinaryValue => 'BINARY',
            $value instanceof BooleanValue => 'BOOLEAN',
            $value instanceof CalAddress => 'CAL-ADDRESS',
            $value instanceof GeoValue => 'FLOAT',
            $value instanceof IntegerValue => 'INTEGER',
            $value instanceof Recurrence => 'RECUR',
            $value instanceof TextValue, $value instanceof \BackedEnum => 'TEXT',
            $value instanceof UriValue => 'URI',
            $value instanceof UtcOffset => 'UTC-OFFSET',
            default => null,
        };
    }

    private function serializeValue(Value $value): string
    {
        if ($value instanceof TextValue) {
            return $this->escapeText($value->text);
        }

        $serialized = $value->toString();
        if (strpbrk($serialized, "\r\n") !== false) {
            throw new InvalidValueException(sprintf(
                'A %s value cannot contain a carriage return or line feed.',
                $value::class,
            ));
        }

        return $serialized;
    }

    private function parameterName(ParameterValue|RawParameter $parameter): string
    {
        return $parameter instanceof RawParameter
            ? $parameter->name
            : $parameter->parameterName();
    }

    private function parameterValue(ParameterValue|RawParameter $parameter): string
    {
        if ($parameter instanceof RawParameter) {
            return implode(',', array_map(
                fn (string $value): string => $this->encodeParameterValue($value),
                $parameter->values,
            ));
        }

        return $this->encodeParameterValue($parameter->token());
    }

    private function escapeText(string $text): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $text,
        );
    }

    /** Quote/encode a parameter value per RFC 5545 §3.2 and RFC 6868. */
    private function encodeParameterValue(string $value): string
    {
        // Encode existing carets first. The carets introduced for normalized
        // line breaks and quotes are RFC 6868 escape markers, not literals.
        $value = str_replace('^', '^^', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '^n', $value);
        $value = str_replace('"', "^'", $value);

        if ($value === '' || preg_match('/[";:,\s]/', $value) === 1) {
            return '"'.$value.'"';
        }

        return $value;
    }

    /** Fold a content line to <= 75 octets, never splitting a UTF-8 sequence. */
    private function fold(string $line): string
    {
        $folded = '';
        $lineOctets = 0;
        $length = strlen($line);

        for ($i = 0; $i < $length;) {
            $byte = ord($line[$i]);
            $charLength = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };

            if ($lineOctets + $charLength > 75) {
                $folded .= "\r\n ";
                $lineOctets = 1; // the leading space of the continuation line
            }

            $folded .= substr($line, $i, $charLength);
            $lineOctets += $charLength;
            $i += $charLength;
        }

        return $folded;
    }

    private function validate(Component $component): void
    {
        foreach (self::REQUIRED[$component->wireName()] ?? [] as $name) {
            if (! $component->hasProperty($name)) {
                throw new MissingPropertyException(
                    sprintf('%s is missing required property %s.', $component->wireName(), $name),
                );
            }
        }

        foreach ($component->children as $child) {
            $this->validate($child);
        }
    }
}
