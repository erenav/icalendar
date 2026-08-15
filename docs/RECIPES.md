# Recipes

Copy-paste examples for common tasks. The whole library is two directions:

- **Build → serialize** — make objects, turn them into an `.ics` string.
- **Parse → read** — turn an `.ics` string into objects, read them with typed getters.

```php
use Erenav\ICalendar\Component\{Calendar, Event};
use Erenav\ICalendar\Serializer\IcsSerializer;
use Erenav\ICalendar\Parser\Parser;
```

---

## Create one event

```php
$event = Event::build()
    ->uid('1@example.com')                 // set a stable, unique id
    ->summary('Lunch')
    ->starts(new DateTimeImmutable('2026-07-01 12:00', new DateTimeZone('UTC')))
    ->lasting(\Erenav\ICalendar\ValueType\Duration::hours(1))
    ->get();
```

## Wrap events in a calendar and get the `.ics` text

```php
$calendar = Calendar::build()
    ->prodId('-//Example//EN')
    ->add($event)
    ->get();

$ics = (new IcsSerializer)->serialize($calendar);   // the .ics string
```

TEXT line breaks are escaped normally. Parameter line breaks are normalized to RFC 6868
`^n`, while a carriage return or line feed in a non-TEXT value is rejected rather than
allowed to create an injected content line.

## An all-day event

```php
use Erenav\ICalendar\ValueType\DateTimeValue;

$event = Event::build()
    ->uid('2@example.com')
    ->summary('Company holiday')
    ->starts(DateTimeValue::date(new DateTimeImmutable('2026-07-04')))
    ->get();
```

## An event in a specific time zone

```php
$event = Event::build()
    ->uid('3@example.com')
    ->summary('Standup')
    ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-07-01 09:30'), 'America/New_York'))
    ->lasting(\Erenav\ICalendar\ValueType\Duration::minutes(15))
    ->get();
```

## Add a reminder (alarm)

```php
use Erenav\ICalendar\Component\Alarm;
use Erenav\ICalendar\Property\AlarmAction;
use Erenav\ICalendar\ValueType\Duration;

$event = Event::build()
    ->uid('4@example.com')
    ->summary('Dentist')
    ->starts(new DateTimeImmutable('2026-07-01 15:00', new DateTimeZone('UTC')))
    ->addAlarm(
        Alarm::build()
            ->action(AlarmAction::Display)
            ->description('Leave now')
            ->trigger(Duration::minutes(-30))   // 30 min before
    )
    ->get();
```

## Add an organizer and attendees

```php
use Erenav\ICalendar\Parameter\{Role, PartStat};

$event = Event::build()
    ->uid('5@example.com')
    ->summary('Planning')
    ->organizer('boss@example.com', name: 'The Boss')
    ->addAttendee('alice@example.com', role: Role::Chair, rsvp: true)
    ->addAttendee('bob@example.com', partStat: PartStat::Accepted)
    ->get();
```

## A recurring event, then list the next occurrences

```php
use Erenav\ICalendar\Recurrence\{Recurrence, Weekday};

$event = Event::build()
    ->uid('6@example.com')
    ->summary('Weekly sync')
    ->starts(new DateTimeImmutable('2026-07-01 09:00', new DateTimeZone('UTC')))
    ->recurrence(Recurrence::weekly()->on(Weekday::Monday, Weekday::Wednesday))
    ->get();

foreach ($event->occurrencesBetween(
    new DateTimeImmutable('2026-07-01', new DateTimeZone('UTC')),
    new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
) as $date) {
    echo $date->format('D Y-m-d H:i'), "\n";    // each occurrence (DateTimeImmutable)
}
```

For a DATE or floating DATE-TIME series, use UTC query bounds whose displayed fields are
the desired calendar limits. The returned `DateTimeImmutable` values are neutral
wall-clock containers, not UTC instants. The default expander rejects a zoned recurrence
whose candidate set reaches a nonexistent DST-gap local time in a selection interval that
can affect the requested expansion horizon, instead of letting the underlying engine
normalize and count an RFC-invalid generated instance. A candidate discarded before
`BYSETPOS` can change an earlier selection in the same `FREQ` interval even when it lies
after the query bound or `UNTIL`, or `COUNT` appears complete before it. A finite rule
completed in an earlier, unaffected interval remains expandable.

The bundled `rlanvin/php-rrule` engine also has an unresolved sparse-rule limit: after 28
consecutive YEARLY intervals with no match (and analogous limits at other frequencies),
it stops searching. Use a custom `RecurrenceExpander` when valid rules can cross such a
gap, particularly around a non-leap century.

Common rules:

```php
Recurrence::daily()->times(10);                                   // 10 days
Recurrence::weekly()->every(2)->on(Weekday::Friday);             // every other Friday
Recurrence::monthly()->on(new \Erenav\ICalendar\Recurrence\WeekdayRule(Weekday::Monday, -1)); // last Monday
Recurrence::yearly()->until(new DateTimeImmutable('2030-01-01', new DateTimeZone('UTC')));
```

When one call to `addRecurrenceDate()` or `addExceptionDate()` receives DATE, floating,
UTC, or differently zoned values, the builder preserves their order but splits them into
adjacent properties. One `RDATE`/`EXDATE` content line has only one `VALUE`/`TZID`
interpretation.

## Read an existing `.ics`

```php
$calendar = Parser::lenient()->parseCalendar($ics);   // recovering; use ::strict() to reject malformed supported values

echo $calendar->name(), "\n";
foreach ($calendar->events() as $event) {
    echo $event->summary(), "\n";
    echo $event->color(), "\n";
    echo $event->start()?->toString(), "\n";
    echo $event->organizer()?->email(), "\n";
    foreach ($event->attendees() as $attendee) {
        echo $attendee->email(), ' — ', $attendee->participationStatus()?->value, "\n";
    }
}
```

Strict parsing rejects malformed typed values and ambiguous or contradictory controlling
`TZID`, `VALUE`, or `ENCODING` parameters. Lenient parsing retains the affected raw value
and parameters instead of picking an interpretation. `REQUEST-STATUS` also remains
structured raw data so its semicolon-separated fields survive re-export.

## Edit an event without mutating the original

Everything is immutable; `toBuilder()` gives you a fresh editable copy.

```php
$updated = $event->toBuilder()
    ->summary('Weekly sync (moved)')
    ->starts(new DateTimeImmutable('2026-07-02 09:00', new DateTimeZone('UTC')))
    ->get();

// $event is unchanged; $updated is the new version
```

## Add generated time-zone definitions

```php
$calendar = Calendar::build()->prodId('-//Example//EN')->add($event)->get()
    ->withTimeZones();   // adds a VTIMEZONE for each zone the events use
```

The default generator inspects 1970–2100. It emits bounded rules for superseded eras,
exact RDATEs for irregular transitions, and an unbounded rule only for a stable suffix
verified through the configured horizon. Pass explicit `TimeZoneGenerator` constructor
bounds for different historical coverage; no generated file can predict future political
changes absent from the installed tz database.

## Send an invitation (iTIP)

```php
use Erenav\ICalendar\Scheduling\ITip;
use Erenav\ICalendar\Parameter\PartStat;

$request = ITip::request($event);                                    // organizer invites
$reply   = ITip::reply($event, 'alice@example.com', PartStat::Accepted);  // attendee responds
$cancel  = ITip::cancel($event);                                     // organizer cancels

$ics = (new IcsSerializer)->serialize($request);
```

`ITip::reply()` retains the complete source `UID`, `DTSTART`, `RECURRENCE-ID` (including
`RANGE`), `SEQUENCE`, `ORGANIZER`, and matching `ATTENDEE` properties. It creates a new
`DTSTAMP`, changes the attendee's `PARTSTAT`, and removes `RSVP`, which is forbidden on a
VEVENT REPLY; every other attendee parameter is retained. Missing, duplicate,
multi-valued, or untyped singleton identity/recurrence metadata is rejected rather than
copied partially. A newly generated CANCEL also receives a fresh `DTSTAMP`.

For supported VEVENT transactions, validation requires a singleton UTC DATE-TIME
`DTSTAMP`, validates `SEQUENCE` as a singleton non-negative INTEGER, rejects VTODO-only
`PARTSTAT=COMPLETED`/`IN-PROCESS`, and validates optional CANCEL `STATUS` as singleton
`CANCELLED`. REQUEST/CANCEL construction rejects duplicate, multi-valued, untyped, or
negative source `SEQUENCE`, and CANCEL guards its increment against RFC INTEGER overflow.

## Copy external properties without dropping parameters

Typed setters are convenient when creating data. When importing and re-exporting a
complete external property, use the corresponding complete-property method:

```php
$builder = Event::build();

if (($property = $source->property('RECURRENCE-ID')) !== null) {
    $builder->recurrenceIdProperty($property); // retains RANGE, TZID, and extensions
}
if (($property = $source->property('ORGANIZER')) !== null) {
    $builder->organizerProperty($property);    // retains SENT-BY, DIR, LANGUAGE, etc.
}
foreach ($source->properties->all('ATTENDEE') as $property) {
    $builder->attendeeProperty($property);     // appends each complete attendee
}

$copy = $builder->get();
```

The same replacement-style API is available as `uidProperty()`, `startProperty()`, and
`sequenceProperty()`. Every method validates that the supplied `Property` has the expected
name.

## Handle modified/cancelled instances of a series

When a calendar has a recurring event plus override events (same UID + `RECURRENCE-ID`),
expand at the **calendar** level to get the resolved result:

```php
foreach ($calendar->occurrencesBetween($from, $to) as $occurrence) {
    $occurrence->start;       // when it actually happens
    $occurrence->end;         // RFC-effective end, including PERIOD/implicit duration
    $occurrence->event;       // master, detached override, or materialized range result
    $occurrence->isOverride;  // true if this instance was modified
}
```

The window is inclusive and tests the effective (possibly moved) start. Range overrides
can be built without making RANGE an independent event property:

```php
use Erenav\ICalendar\Parameter\Range;

$override = Event::build()
    ->uid($master->uid() ?? throw new LogicException('The master needs a UID.'))
    ->recurrenceId($originalSlot, Range::ThisAndFuture)
    ->starts($newStart)
    ->get();
```

`Occurrence::$recurrenceId` remains the original series slot while `Occurrence::$start`
and `Occurrence::$event->start()` agree on the effective start. Under the default sparse
merge policy, a later range replaces timing/properties it states; its start move is a
fixed wall-coordinate delta even across unequal month lengths. Earlier non-temporal and
duration changes remain until replaced, while a later non-cancelled range resumes a
cancelled tail. Each later range begins a new timing segment, so omitting DTSTART resets a
prior start move instead of inheriting it. A single-instance override wins for its slot and changes only what it
supplies: absent timing, non-temporal properties, and children inherit from the active
range or materialized master slot; explicit `DTSTART`, `DTEND`, or `DURATION` wins; and
supplied children replace the inherited child set. A non-cancelled single can restore that
one slot inside a cancelled tail. Every explicit source override keeps its complete
`RECURRENCE-ID`, including IANA/X parameters and RANGE on a range onset. Only synthetic
later range events use generated recurrence IDs without `RANGE` or slot-specific
parameters. Duplicate
masters and detached overrides are chosen deterministically by `SEQUENCE`, `DTSTAMP`,
`LAST-MODIFIED`, then canonical serialized content, independent of document order.

A valid resolvable-zoned range delta may newly move a later effective start into a DST gap. That
materialized event preserves the intended wall literal and uses RFC 5545's pre-transition
offset for its instant. Inputs already carrying an unresolved/gap representation,
and rule-generated base gap candidates, are rejected. DATE-valued recurrence masters and
overrides likewise require day- or week-based durations; sub-day DURATION values fail
closed rather than materializing an equal/truncated DATE end.

Calendar-level expansion resolves custom TZIDs from typed embedded VTIMEZONE
STANDARD/DAYLIGHT observances; the embedded definition is authoritative even when its
TZID is also an IANA name. Missing, duplicate, malformed, discontinuous, or
recurrence-unsupported definitions fail closed when used. Recurrence sets that mix
floating DATE-TIME coordinates with instants remain unsupported. An explicit PERIOD end
must still resolve to an instant after its start under that authoritative definition;
lexical wall-clock ordering alone is not sufficient across an offset transition.

For direct duration arithmetic outside calendar expansion, build a resolver with
`TimeZoneResolver::fromCalendar($calendar)` and pass it to `$event->end($resolver)` or
`$event->effectiveEnd($resolver)`. This keeps nominal day/week arithmetic distinct from
exact hour/minute/second arithmetic across embedded-zone transitions.

Unknown RRULE parts survive repeated semantic round trips and remain rejected by the
default expander because ignoring their semantics would be unsafe. PERIOD-valued RDATEs
are expanded at calendar level with their own effective end/duration; EXDATE and override
precedence apply to their slots, and sparse range/single overlays retain that duration
until timing is explicitly replaced. The event-level API continues returning starts only.
The same fail-closed rule applies to unsynchronized DTSTART/RRULE pairs, recurrence-set
properties on detached RECURRENCE-ID components, and UID-less recurrence IDs.
Known RRULE parts serialize canonically; unknown parts retain their relative order after
them. Escaped semicolons remain escaped across repeated round trips; programmatic unknown
parts reject unescaped structural delimiters, dangling escapes, standard-part names, and
control bytes. Inspect these values via `$rule->unknownParts` and
`$event->recurrenceDatePeriods()`.

---

Using Laravel? See [`erenav/laravel-icalendar`](https://github.com/erenav/laravel-icalendar)
for facades, feeds, Eloquent mapping, and mail attachments.
