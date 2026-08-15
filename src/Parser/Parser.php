<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Parser;

use DateTimeImmutable;
use DateTimeZone;
use Erenav\ICalendar\Component\Alarm;
use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Component;
use Erenav\ICalendar\Component\ComponentList;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Component\GenericComponent;
use Erenav\ICalendar\Component\Observance;
use Erenav\ICalendar\Component\TimeZone;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\Exception\ParseException;
use Erenav\ICalendar\Parameter\CuType;
use Erenav\ICalendar\Parameter\FreeBusyType;
use Erenav\ICalendar\Parameter\ParameterBag;
use Erenav\ICalendar\Parameter\ParameterValue;
use Erenav\ICalendar\Parameter\PartStat;
use Erenav\ICalendar\Parameter\Range;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Parameter\Related;
use Erenav\ICalendar\Parameter\RelationType;
use Erenav\ICalendar\Parameter\Role;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Property\PropertyBag;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\ValueType\BinaryValue;
use Erenav\ICalendar\ValueType\BooleanValue;
use Erenav\ICalendar\ValueType\CalAddress;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use Erenav\ICalendar\ValueType\GeoValue;
use Erenav\ICalendar\ValueType\IntegerValue;
use Erenav\ICalendar\ValueType\Period;
use Erenav\ICalendar\ValueType\RawValue;
use Erenav\ICalendar\ValueType\TextValue;
use Erenav\ICalendar\ValueType\UriValue;
use Erenav\ICalendar\ValueType\UtcOffset;
use Erenav\ICalendar\ValueType\Value;
use Exception;

/**
 * Parses RFC 5545 text into the component tree.
 *
 * Pipeline: unfold lines → split each into name/parameters/value → hydrate typed
 * values (merging TZID / VALUE / ENCODING back into the value) → assemble the
 * Composite tree. Lenient by default: untyped property values are generally
 * preserved as {@see RawValue} (and unknown components as
 * {@see GenericComponent}) for canonical semantic re-export. It does not promise
 * byte-for-byte input fidelity. {@see self::strict()} rejects malformed input.
 */
final class Parser
{
    /** A property's default value type when no VALUE parameter is present. */
    private const PROPERTY_TYPE = [
        'DTSTART' => 'DATE-TIME', 'DTEND' => 'DATE-TIME', 'DTSTAMP' => 'DATE-TIME',
        'DUE' => 'DATE-TIME', 'CREATED' => 'DATE-TIME', 'LAST-MODIFIED' => 'DATE-TIME',
        'RECURRENCE-ID' => 'DATE-TIME', 'EXDATE' => 'DATE-TIME', 'RDATE' => 'DATE-TIME',
        'COMPLETED' => 'DATE-TIME', 'ACKNOWLEDGED' => 'DATE-TIME',
        'DURATION' => 'DURATION', 'TRIGGER' => 'DURATION', 'REFRESH-INTERVAL' => 'DURATION',
        'PRIORITY' => 'INTEGER', 'SEQUENCE' => 'INTEGER', 'PERCENT-COMPLETE' => 'INTEGER', 'REPEAT' => 'INTEGER',
        'ORGANIZER' => 'CAL-ADDRESS', 'ATTENDEE' => 'CAL-ADDRESS',
        'URL' => 'URI', 'SOURCE' => 'URI', 'IMAGE' => 'URI', 'CONFERENCE' => 'URI', 'TZURL' => 'URI', 'ATTACH' => 'URI',
        'TZOFFSETFROM' => 'UTC-OFFSET', 'TZOFFSETTO' => 'UTC-OFFSET',
        'GEO' => 'GEO',
        'SUMMARY' => 'TEXT', 'DESCRIPTION' => 'TEXT', 'LOCATION' => 'TEXT', 'COMMENT' => 'TEXT',
        'CONTACT' => 'TEXT', 'CATEGORIES' => 'TEXT', 'RESOURCES' => 'TEXT', 'STATUS' => 'TEXT',
        'TRANSP' => 'TEXT', 'CLASS' => 'TEXT', 'ACTION' => 'TEXT', 'UID' => 'TEXT', 'PRODID' => 'TEXT',
        'VERSION' => 'TEXT', 'CALSCALE' => 'TEXT', 'METHOD' => 'TEXT', 'NAME' => 'TEXT', 'COLOR' => 'TEXT',
        'TZID' => 'TEXT', 'TZNAME' => 'TEXT', 'RELATED-TO' => 'TEXT',
        'RRULE' => 'RECUR',
    ];

    /**
     * Properties whose value is a comma-separated list. Every other property is
     * single-valued, so its value is never split on commas (which keeps commas
     * inside URIs, or unescaped commas from sloppy producers, intact).
     */
    private const MULTI_VALUE = ['CATEGORIES', 'RESOURCES', 'EXDATE', 'RDATE', 'FREEBUSY'];

    private LineUnfolder $unfolder;

    public function __construct(
        private readonly bool $strict = false,
    ) {
        $this->unfolder = new LineUnfolder;
    }

    public static function lenient(): self
    {
        return new self(false);
    }

    public static function strict(): self
    {
        return new self(true);
    }

    /** Parse text into its root component (typically a {@see Calendar}). */
    public function parse(string $text): Component
    {
        /** @var list<ComponentFrame> $stack */
        $stack = [];
        $root = null;

        foreach ($this->unfolder->unfold($text) as $line) {
            if ($line === '') {
                continue;
            }

            [$name, $parameters, $rawValue] = $this->splitContentLine($line);
            $upper = strtoupper($name);

            if ($upper === 'BEGIN') {
                $stack[] = new ComponentFrame(strtoupper(trim($rawValue)));

                continue;
            }

            if ($upper === 'END') {
                if ($stack === []) {
                    if ($this->strict) {
                        throw new ParseException('Unexpected END with no matching BEGIN.');
                    }

                    continue;
                }

                $endName = strtoupper(trim($rawValue));
                $openName = $stack[array_key_last($stack)]->name;
                if ($this->strict && $endName !== $openName) {
                    throw new ParseException(sprintf('END:%s does not match open BEGIN:%s.', $endName, $openName));
                }

                $component = $this->buildComponent(array_pop($stack));
                if ($stack === []) {
                    $root = $component;
                } else {
                    $stack[array_key_last($stack)]->children[] = $component;
                }

                continue;
            }

            if ($stack === []) {
                if ($this->strict) {
                    throw new ParseException(sprintf('Property "%s" found outside any component.', $name));
                }

                continue;
            }

            $stack[array_key_last($stack)]->properties[] = $this->hydrateProperty($name, $parameters, $rawValue);
        }

        if ($root === null) {
            if ($this->strict || $stack === []) {
                throw new ParseException('No complete component found in input.');
            }

            // Lenient: an unterminated top-level component is still returned.
            $root = $this->buildComponent($stack[0]);
        }

        return $root;
    }

    /** Parse and require the root to be a VCALENDAR. */
    public function parseCalendar(string $text): Calendar
    {
        $root = $this->parse($text);
        if (! $root instanceof Calendar) {
            throw new ParseException(sprintf('Expected a VCALENDAR root, got %s.', $root->wireName()));
        }

        return $root;
    }

    private function buildComponent(ComponentFrame $frame): Component
    {
        $properties = new PropertyBag(...$frame->properties);
        $children = new ComponentList(...$frame->children);

        return match ($frame->name) {
            'VCALENDAR' => new Calendar($properties, $children),
            'VEVENT' => new Event($properties, $children),
            'VALARM' => new Alarm($properties, $children),
            'VTIMEZONE' => new TimeZone($properties, $children),
            'STANDARD' => new Observance(false, $properties, $children),
            'DAYLIGHT' => new Observance(true, $properties, $children),
            default => new GenericComponent($frame->name, $properties, $children),
        };
    }

    /**
     * Split a logical line into [name, params, rawValue].
     *
     * @return array{0: string, 1: list<array{0: string, 1: list<string>}>, 2: string}
     */
    private function splitContentLine(string $line): array
    {
        $length = strlen($line);
        $i = 0;

        $name = '';
        while ($i < $length && $line[$i] !== ';' && $line[$i] !== ':') {
            $name .= $line[$i++];
        }

        $parameters = [];
        while ($i < $length && $line[$i] === ';') {
            $i++; // consume ';'

            $paramName = '';
            while ($i < $length && $line[$i] !== '=' && $line[$i] !== ';' && $line[$i] !== ':') {
                $paramName .= $line[$i++];
            }
            if ($i < $length && $line[$i] === '=') {
                $i++; // consume '='
            }

            $values = [];
            while (true) {
                if ($i < $length && $line[$i] === '"') {
                    $i++; // opening quote
                    $value = '';
                    while ($i < $length && $line[$i] !== '"') {
                        $value .= $line[$i++];
                    }
                    $i++; // closing quote
                } else {
                    $value = '';
                    while ($i < $length && $line[$i] !== ',' && $line[$i] !== ';' && $line[$i] !== ':') {
                        $value .= $line[$i++];
                    }
                }

                $values[] = $this->caretDecode($value);

                if ($i < $length && $line[$i] === ',') {
                    $i++;

                    continue;
                }
                break;
            }

            $parameters[] = [$paramName, $values];
        }

        if ($i < $length && $line[$i] === ':') {
            $i++;
        }

        return [$name, $parameters, substr($line, $i)];
    }

    /**
     * @param  list<array{0: string, 1: list<string>}>  $parameters
     */
    private function hydrateProperty(string $name, array $parameters, string $rawValue): Property
    {
        /** @var array<string, list<string>> $parameterValues */
        $parameterValues = [];
        /** @var list<string> $parameterOrder */
        $parameterOrder = [];
        /** @var array<string, true> $duplicateParameters */
        $duplicateParameters = [];
        foreach ($parameters as [$paramName, $paramValues]) {
            $paramName = strtoupper($paramName);
            if (isset($parameterValues[$paramName])) {
                $duplicateParameters[$paramName] = true;
                array_push($parameterValues[$paramName], ...$paramValues);
            } else {
                $parameterOrder[] = $paramName;
                $parameterValues[$paramName] = $paramValues;
            }
        }
        if ($this->strict && $duplicateParameters !== []) {
            throw new ParseException(sprintf(
                'Property "%s" contains duplicate parameter name(s): %s.',
                $name,
                implode(', ', array_keys($duplicateParameters)),
            ));
        }

        $tzid = null;
        $valueType = null;
        $encoding = null;
        $bagParameters = [];

        foreach ($parameterOrder as $paramName) {
            $paramValues = $parameterValues[$paramName];
            match ($paramName) {
                'TZID' => [$tzid, $bagParameters[]] = [$paramValues[0] ?? null, new RawParameter($paramName, ...$paramValues)],
                'VALUE' => [$valueType, $bagParameters[]] = [strtoupper($paramValues[0] ?? ''), new RawParameter($paramName, ...$paramValues)],
                'ENCODING' => [$encoding, $bagParameters[]] = [strtoupper($paramValues[0] ?? ''), new RawParameter($paramName, ...$paramValues)],
                default => $bagParameters[] = $this->hydrateParameter($paramName, $paramValues),
            };
        }

        $ambiguousControllingParameters = [];
        foreach (['TZID', 'VALUE', 'ENCODING'] as $controllingParameter) {
            if (isset($duplicateParameters[$controllingParameter])
                || count($parameterValues[$controllingParameter] ?? []) > 1) {
                $ambiguousControllingParameters[] = $controllingParameter;
            }
        }
        if ($ambiguousControllingParameters !== [] && $this->strict) {
            throw new ParseException(sprintf(
                'Property "%s" contains multi-valued controlling parameter(s): %s.',
                $name,
                implode(', ', $ambiguousControllingParameters),
            ));
        }

        $type = $this->effectiveType(strtoupper($name), $valueType, $encoding);

        // TZID, VALUE, and ENCODING control how every value on the property is
        // interpreted. Choosing the first of several values would silently
        // reinterpret external data, so lenient mode preserves the value raw.
        if ($ambiguousControllingParameters !== []) {
            return new Property($name, new RawValue($rawValue), new ParameterBag(...$bagParameters));
        }

        if ($tzid !== null && ! in_array($type, ['DATE', 'DATE-TIME', 'PERIOD', 'RAW'], true)) {
            if ($this->strict) {
                throw new ParseException(sprintf(
                    'Property "%s" cannot carry TZID with its %s value type.',
                    $name,
                    $type,
                ));
            }

            return new Property($name, new RawValue($rawValue), new ParameterBag(...$bagParameters));
        }

        if ($encoding !== null && $type !== 'RAW') {
            $knownEncoding = in_array($encoding, ['8BIT', 'BASE64'], true);
            $contradictoryEncoding = $knownEncoding
                && (($encoding === 'BASE64') !== ($type === 'BINARY'));
            if ($contradictoryEncoding && $this->strict) {
                throw new ParseException(sprintf(
                    'Property "%s" has ENCODING=%s incompatible with its %s value type.',
                    $name,
                    $encoding,
                    $type,
                ));
            }

            // An unsupported encoding cannot be decoded safely. A known but
            // contradictory one is malformed. Preserve either value raw in
            // lenient mode so subsequent serialization cannot mislabel it.
            if (! $knownEncoding || $contradictoryEncoding) {
                return new Property($name, new RawValue($rawValue), new ParameterBag(...$bagParameters));
            }
        }

        try {
            $values = $this->hydrateValues($type, strtoupper($name), $rawValue, $tzid);
        } catch (Exception $exception) {
            // Malformed external values are reported by value parsers through
            // Exception subclasses. Errors (including TypeError) deliberately
            // remain visible because they indicate a programming defect rather
            // than input that lenient mode should preserve as raw data.
            if ($this->strict) {
                throw new ParseException(
                    sprintf('Could not parse value of property "%s": %s', $name, $exception->getMessage()),
                    previous: $exception,
                );
            }
            $values = [new RawValue($rawValue)];
        }

        return new Property($name, $values, new ParameterBag(...$bagParameters));
    }

    /**
     * @param  list<string>  $values
     */
    private function hydrateParameter(string $name, array $values): ParameterValue|RawParameter
    {
        $first = strtoupper($values[0] ?? '');
        $enum = match ($name) {
            'ROLE' => Role::tryFrom($first),
            'PARTSTAT' => PartStat::tryFrom($first),
            'CUTYPE' => CuType::tryFrom($first),
            'FBTYPE' => FreeBusyType::tryFrom($first),
            'RELTYPE' => RelationType::tryFrom($first),
            'RELATED' => Related::tryFrom($first),
            'RANGE' => Range::tryFrom($first),
            default => null,
        };

        if ($enum !== null && count($values) === 1) {
            return $enum;
        }

        return new RawParameter($name, ...$values);
    }

    private function effectiveType(string $name, ?string $valueType, ?string $encoding): string
    {
        if ($valueType !== null) {
            return match ($valueType) {
                'DATE' => 'DATE',
                'DATE-TIME' => 'DATE-TIME',
                'DURATION' => 'DURATION',
                'PERIOD' => 'PERIOD',
                'BINARY' => 'BINARY',
                'URI' => 'URI',
                'TEXT' => 'TEXT',
                'INTEGER' => 'INTEGER',
                'BOOLEAN' => 'BOOLEAN',
                'CAL-ADDRESS' => 'CAL-ADDRESS',
                // GEO is the package's typed representation of the RFC pair
                // of FLOAT values; a general scalar FLOAT type is not modeled.
                'FLOAT' => $name === 'GEO' ? 'GEO' : 'RAW',
                'UTC-OFFSET' => 'UTC-OFFSET',
                'RECUR' => 'RECUR',
                default => 'RAW',
            };
        }

        if ($encoding === 'BASE64') {
            return 'BINARY';
        }

        return self::PROPERTY_TYPE[$name] ?? 'RAW';
    }

    /**
     * @return list<Value>
     */
    private function hydrateValues(string $type, string $name, string $rawValue, ?string $tzid): array
    {
        if ($type === 'RAW') {
            return [new RawValue($rawValue)];
        }

        // RECUR values contain semicolons and commas internally — never split them.
        if ($type === 'RECUR') {
            return [Recurrence::parse($rawValue, $this->strict)];
        }

        $parts = in_array($name, self::MULTI_VALUE, true)
            ? $this->splitOnUnescapedCommas($rawValue)
            : [$rawValue];

        return array_map(
            fn (string $part): Value => $this->hydrateScalar($type, $part, $tzid),
            $parts,
        );
    }

    private function hydrateScalar(string $type, string $part, ?string $tzid): Value
    {
        if ($type === 'DATE' && $tzid !== null) {
            throw new ParseException('A DATE value cannot carry a TZID parameter.');
        }

        return match ($type) {
            'TEXT' => new TextValue($this->unescapeText($part)),
            'INTEGER' => IntegerValue::parse($part),
            'BOOLEAN' => BooleanValue::parse($part),
            'DURATION' => Duration::parse($part),
            'UTC-OFFSET' => UtcOffset::parse($part),
            'CAL-ADDRESS' => CalAddress::fromUri($part),
            'URI' => new UriValue($part),
            'BINARY' => BinaryValue::fromBase64($part),
            'GEO' => GeoValue::parse($part),
            'DATE' => $this->parseDate($part),
            'DATE-TIME' => $this->parseDateTime($part, $tzid),
            'PERIOD' => $this->parsePeriod($part, $tzid),
            default => new RawValue($part),
        };
    }

    private function parseDate(string $part): DateTimeValue
    {
        $dateTime = DateTimeImmutable::createFromFormat('!Ymd', $part, new DateTimeZone('UTC'));
        if ($dateTime === false || $dateTime->format('Ymd') !== $part) {
            throw new ParseException(sprintf('Malformed DATE value "%s".', $part));
        }

        return DateTimeValue::date($dateTime);
    }

    private function parseDateTime(string $part, ?string $tzid): DateTimeValue
    {
        if (str_ends_with($part, 'Z') || str_ends_with($part, 'z')) {
            if ($tzid !== null) {
                throw new ParseException('A UTC DATE-TIME value cannot also carry a TZID parameter.');
            }

            return DateTimeValue::utc($this->fromFormat(substr($part, 0, -1), new DateTimeZone('UTC')));
        }

        if ($tzid !== null) {
            // Parse the lexical fields without letting PHP normalise DST gaps;
            // DateTimeValue resolves them with RFC 5545's pre-gap rule. Custom
            // VTIMEZONE ids remain a UTC-backed wall clock with the id retained.
            return DateTimeValue::zoned($this->fromFormat($part, new DateTimeZone('UTC')), $tzid);
        }

        return DateTimeValue::floating($this->fromFormat($part, new DateTimeZone('UTC')));
    }

    private function fromFormat(string $part, DateTimeZone $zone): DateTimeImmutable
    {
        $dateTime = DateTimeImmutable::createFromFormat('!Ymd\THis', $part, $zone);
        if ($dateTime === false || $dateTime->format('Ymd\THis') !== $part) {
            throw new ParseException(sprintf('Malformed DATE-TIME value "%s".', $part));
        }

        return $dateTime;
    }

    private function parsePeriod(string $part, ?string $tzid): Period
    {
        $segments = explode('/', $part, 2);
        if (count($segments) !== 2) {
            throw new ParseException(sprintf('Malformed PERIOD value "%s".', $part));
        }

        $start = $this->parseDateTime($segments[0], $tzid);

        if (preg_match('/^[+-]?P/', $segments[1]) === 1) {
            return Period::lasting($start, Duration::parse($segments[1]));
        }

        return Period::between($start, $this->parseDateTime($segments[1], $tzid));
    }

    /** @return list<string> */
    private function splitOnUnescapedCommas(string $value): array
    {
        $parts = [];
        $current = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $current .= $char.$value[$i + 1];
                $i++;

                continue;
            }
            if ($char === ',') {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    private function unescapeText(string $value): string
    {
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '\\') {
                if ($i + 1 >= $length) {
                    throw new InvalidValueException('A TEXT value cannot end with an incomplete escape sequence.');
                }
                $next = $value[$i + 1];
                $out .= match ($next) {
                    'n', 'N' => "\n",
                    '\\' => '\\',
                    ',' => ',',
                    ';' => ';',
                    default => throw new InvalidValueException(sprintf(
                        'Invalid TEXT escape sequence "\\%s".',
                        $next,
                    )),
                };
                $i++;

                continue;
            }
            $out .= $value[$i];
        }

        return $out;
    }

    private function caretDecode(string $value): string
    {
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '^' && $i + 1 < $length) {
                $next = $value[$i + 1];
                $out .= match ($next) {
                    'n' => "\n",
                    "'" => '"',
                    '^' => '^',
                    default => '^'.$next,
                };
                $i++;

                continue;
            }
            $out .= $value[$i];
        }

        return $out;
    }
}
