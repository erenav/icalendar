<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Builder;

use DateInterval;
use DateTimeInterface;
use Erenav\ICalendar\Component\Alarm;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Exception\InvalidValueException;
use Erenav\ICalendar\Parameter\CuType;
use Erenav\ICalendar\Parameter\ParameterBag;
use Erenav\ICalendar\Parameter\PartStat;
use Erenav\ICalendar\Parameter\Range;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Parameter\Role;
use Erenav\ICalendar\Property\Classification;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Property\Transparency;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use Erenav\ICalendar\ValueType\GeoValue;
use Erenav\ICalendar\ValueType\IntegerValue;
use Erenav\ICalendar\ValueType\Period;
use Erenav\ICalendar\ValueType\TextValue;
use Erenav\ICalendar\ValueType\UriValue;

/**
 * Fluent builder for a {@see Event}. Date-time setters accept any
 * {@see DateTimeInterface} (so Carbon works) or a {@see DateTimeValue} for full
 * control over the floating/UTC/zoned form.
 */
final class EventBuilder extends Builder
{
    public static function fromEvent(Event $event): self
    {
        $builder = new self;
        $builder->loadFrom($event);

        return $builder;
    }

    public function uid(string $uid): static
    {
        $this->set('UID', new TextValue($uid));

        return $this;
    }

    /** Copy a complete UID property, including IANA and experimental parameters. */
    public function uidProperty(Property $property): static
    {
        return $this->replaceCompleteProperty('UID', $property);
    }

    public function summary(string $summary): static
    {
        $this->set('SUMMARY', new TextValue($summary));

        return $this;
    }

    public function description(string $description): static
    {
        $this->set('DESCRIPTION', new TextValue($description));

        return $this;
    }

    public function location(string $location): static
    {
        $this->set('LOCATION', new TextValue($location));

        return $this;
    }

    public function url(string $url): static
    {
        $this->set('URL', new UriValue($url));

        return $this;
    }

    public function starts(DateTimeInterface|DateTimeValue $start): static
    {
        $this->set('DTSTART', $this->toDateTimeValue($start));

        return $this;
    }

    /** Copy a complete DTSTART property, retaining VALUE, TZID, and extensions. */
    public function startProperty(Property $property): static
    {
        return $this->replaceCompleteProperty('DTSTART', $property);
    }

    /** Sets DTEND, clearing any DURATION (the two are mutually exclusive). */
    public function ends(DateTimeInterface|DateTimeValue $end): static
    {
        $this->removeProperty('DURATION');
        $this->set('DTEND', $this->toDateTimeValue($end));

        return $this;
    }

    /** Sets DURATION, clearing any DTEND. */
    public function lasting(Duration|DateInterval $duration): static
    {
        $this->removeProperty('DTEND');
        $this->set('DURATION', $this->toDuration($duration));

        return $this;
    }

    public function timestamp(DateTimeInterface|DateTimeValue $dtstamp): static
    {
        $this->set('DTSTAMP', $this->toDateTimeValue($dtstamp));

        return $this;
    }

    public function created(DateTimeInterface|DateTimeValue $created): static
    {
        $this->set('CREATED', $this->toDateTimeValue($created));

        return $this;
    }

    public function lastModified(DateTimeInterface|DateTimeValue $lastModified): static
    {
        $this->set('LAST-MODIFIED', $this->toDateTimeValue($lastModified));

        return $this;
    }

    public function status(EventStatus $status): static
    {
        $this->set('STATUS', $status);

        return $this;
    }

    public function transparency(Transparency $transparency): static
    {
        $this->set('TRANSP', $transparency);

        return $this;
    }

    public function classification(Classification $classification): static
    {
        $this->set('CLASS', $classification);

        return $this;
    }

    public function priority(int $priority): static
    {
        $this->set('PRIORITY', new IntegerValue($priority));

        return $this;
    }

    public function sequence(int $sequence): static
    {
        if ($sequence < 0) {
            throw new InvalidValueException('SEQUENCE must be a non-negative integer.');
        }

        $this->set('SEQUENCE', new IntegerValue($sequence));

        return $this;
    }

    public function geo(float $latitude, float $longitude): static
    {
        $this->set('GEO', GeoValue::of($latitude, $longitude));

        return $this;
    }

    /** The RFC 7986 COLOR property (a CSS3 colour name). */
    public function color(string $color): static
    {
        $this->set('COLOR', new TextValue($color));

        return $this;
    }

    public function categories(string ...$categories): static
    {
        if ($categories === []) {
            $this->removeProperty('CATEGORIES');

            return $this;
        }

        $this->set('CATEGORIES', array_values(array_map(static fn (string $c): TextValue => new TextValue($c), $categories)));

        return $this;
    }

    public function organizer(string $address, ?string $name = null, ?string $sentBy = null): static
    {
        $parameters = new ParameterBag;
        if ($name !== null) {
            $parameters = $parameters->with(new RawParameter('CN', $name));
        }
        if ($sentBy !== null) {
            $parameters = $parameters->with(new RawParameter('SENT-BY', $this->toCalAddress($sentBy)->toString()));
        }

        $this->set('ORGANIZER', $this->toCalAddress($address), $parameters);

        return $this;
    }

    public function addAttendee(
        string $address,
        ?Role $role = null,
        ?PartStat $partStat = null,
        ?CuType $cuType = null,
        ?bool $rsvp = null,
        ?string $name = null,
    ): static {
        if (in_array($partStat, [PartStat::Completed, PartStat::InProcess], true)) {
            throw new InvalidValueException(sprintf(
                'PARTSTAT=%s is valid for VTODO but not VEVENT.',
                $partStat->value,
            ));
        }

        $parameters = new ParameterBag;
        if ($name !== null) {
            $parameters = $parameters->with(new RawParameter('CN', $name));
        }
        if ($role !== null) {
            $parameters = $parameters->with($role);
        }
        if ($partStat !== null) {
            $parameters = $parameters->with($partStat);
        }
        if ($cuType !== null) {
            $parameters = $parameters->with($cuType);
        }
        if ($rsvp !== null) {
            $parameters = $parameters->with(new RawParameter('RSVP', $rsvp ? 'TRUE' : 'FALSE'));
        }

        $this->append('ATTENDEE', $this->toCalAddress($address), $parameters);

        return $this;
    }

    public function recurrence(Recurrence $recurrence): static
    {
        $this->set('RRULE', $recurrence);

        return $this;
    }

    /** Mark this event as an override of one instance of a recurring series. */
    public function recurrenceId(DateTimeInterface|DateTimeValue $recurrenceId, ?Range $range = null): static
    {
        $parameters = $range !== null ? new ParameterBag($range) : null;
        $this->set('RECURRENCE-ID', $this->toDateTimeValue($recurrenceId), $parameters);

        return $this;
    }

    /** Copy a complete RECURRENCE-ID property, including RANGE and extensions. */
    public function recurrenceIdProperty(Property $property): static
    {
        return $this->replaceCompleteProperty('RECURRENCE-ID', $property);
    }

    /** Copy a complete organizer property, including scheduling parameters. */
    public function organizerProperty(Property $property): static
    {
        return $this->replaceCompleteProperty('ORGANIZER', $property);
    }

    /** Append a complete attendee property without a positional parameter API. */
    public function attendeeProperty(Property $property): static
    {
        if ($property->name !== 'ATTENDEE') {
            throw new \InvalidArgumentException('Expected an ATTENDEE property.');
        }
        $this->properties[] = $property;

        return $this;
    }

    /** Copy a complete SEQUENCE property. */
    public function sequenceProperty(Property $property): static
    {
        return $this->replaceCompleteProperty('SEQUENCE', $property);
    }

    public function addExceptionDate(DateTimeInterface|DateTimeValue ...$dates): static
    {
        if ($dates === []) {
            return $this;
        }

        $this->appendDateList('EXDATE', array_values($dates));

        return $this;
    }

    public function addRecurrenceDate(DateTimeInterface|DateTimeValue ...$dates): static
    {
        if ($dates === []) {
            return $this;
        }

        $this->appendDateList('RDATE', array_values($dates));

        return $this;
    }

    /** Append PERIOD-valued recurrence dates without mixing RDATE value types. */
    public function addRecurrencePeriod(Period ...$periods): static
    {
        foreach ($periods as $period) {
            // One property per period keeps each VALUE/TZID parameter derived
            // from the period itself even when callers add different zones.
            $this->append('RDATE', $period);
        }

        return $this;
    }

    public function addAlarm(Alarm|AlarmBuilder $alarm): static
    {
        $this->addChild($alarm instanceof AlarmBuilder ? $alarm->get() : $alarm);

        return $this;
    }

    public function get(): Event
    {
        return new Event($this->propertyBag(), $this->componentList());
    }

    private function replaceCompleteProperty(string $expectedName, Property $property): static
    {
        if ($property->name !== $expectedName) {
            throw new \InvalidArgumentException("Expected a {$expectedName} property.");
        }

        $this->removeProperty($expectedName);
        $this->properties[] = $property;

        return $this;
    }

    /**
     * One TZID and VALUE parameter applies to every value on a content line.
     * Keep compatible adjacent values together while splitting heterogeneous
     * lists into independently serializable properties without reordering them.
     *
     * @param  list<DateTimeInterface|DateTimeValue>  $dates
     */
    private function appendDateList(string $name, array $dates): void
    {
        $group = [];
        $signature = null;

        foreach ($dates as $date) {
            $value = $this->toDateTimeValue($date);
            $valueSignature = match (true) {
                $value->isDateOnly => 'DATE',
                $value->isUtc => 'UTC',
                $value->isFloating() => 'FLOATING',
                default => 'TZID:'.$value->tzid,
            };

            if ($signature !== null && $valueSignature !== $signature) {
                $this->append($name, $group);
                $group = [];
            }

            $signature = $valueSignature;
            $group[] = $value;
        }

        if ($group !== []) {
            $this->append($name, $group);
        }
    }
}
