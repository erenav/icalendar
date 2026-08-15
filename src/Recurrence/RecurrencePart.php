<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Recurrence;

use Erenav\ICalendar\Exception\InvalidValueException;

/** An unmodelled IANA or experimental RRULE part, preserved in input order. */
final readonly class RecurrencePart
{
    /** @var array<string, true> */
    private const STANDARD_NAMES = [
        'FREQ' => true,
        'UNTIL' => true,
        'COUNT' => true,
        'INTERVAL' => true,
        'BYSECOND' => true,
        'BYMINUTE' => true,
        'BYHOUR' => true,
        'BYDAY' => true,
        'BYMONTHDAY' => true,
        'BYYEARDAY' => true,
        'BYWEEKNO' => true,
        'BYMONTH' => true,
        'BYSETPOS' => true,
        'WKST' => true,
    ];

    public string $name;

    public function __construct(string $name, public string $value)
    {
        $name = strtoupper(trim($name));
        if (preg_match('/^[A-Z0-9-]+$/', $name) !== 1 || $value === '') {
            throw new InvalidValueException('An unknown RRULE part needs a valid name and non-empty value.');
        }
        if (preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1 || self::hasStructuralDelimiter($value)) {
            throw new InvalidValueException('An unknown RRULE part value cannot contain control bytes, an unescaped semicolon, or a dangling escape.');
        }
        if (isset(self::STANDARD_NAMES[$name])) {
            throw new InvalidValueException(sprintf('RRULE part "%s" is standard and must use its strongly typed field.', $name));
        }
        $this->name = $name;
    }

    public function toString(): string
    {
        return $this->name.'='.$this->value;
    }

    private static function hasStructuralDelimiter(string $value): bool
    {
        $escaped = false;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $character = $value[$i];
            if ($character === ';' && ! $escaped) {
                return true;
            }
            $escaped = $character === '\\' ? ! $escaped : false;
        }

        return $escaped;
    }
}
