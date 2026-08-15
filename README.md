# erenav/icalendar

[![Latest Version](https://img.shields.io/packagist/v/erenav/icalendar.svg)](https://packagist.org/packages/erenav/icalendar)
[![Tests](https://github.com/erenav/icalendar/actions/workflows/ci.yml/badge.svg)](https://github.com/erenav/icalendar/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/erenav/icalendar.svg)](https://packagist.org/packages/erenav/icalendar)
[![Total Downloads](https://img.shields.io/packagist/dt/erenav/icalendar.svg)](https://packagist.org/packages/erenav/icalendar)
[![License](https://img.shields.io/packagist/l/erenav/icalendar.svg)](LICENSE)

A modern, strongly-typed, **immutable** iCalendar library for PHP 8.3+.

Models the package's documented portions of
[RFC 5545](https://www.rfc-editor.org/rfc/rfc5545) (iCalendar),
[RFC 7986](https://www.rfc-editor.org/rfc/rfc7986) (new properties), and
[RFC 5546](https://www.rfc-editor.org/rfc/rfc5546) (iTIP scheduling).

No stringly-typed array access, no `$event['VEVENT']['SUMMARY']`. Fluent builders,
immutable value objects, typed getters, and semantic preservation of supported external
data the library does not model directly.

```php
use Erenav\ICalendar\Component\{Calendar, Event};
use Erenav\ICalendar\Serializer\IcsSerializer;
use Erenav\ICalendar\ValueType\Duration;

$calendar = Calendar::build()
    ->prodId('-//Acme//Booking 1.0//EN')
    ->add(
        Event::build()
            ->uid('booking-42@acme.test')
            ->summary('Sprint Planning')
            ->starts(new DateTimeImmutable('2026-07-01 10:00', new DateTimeZone('UTC')))
            ->lasting(Duration::hours(1))
            ->addAttendee('alice@acme.test')
    )
    ->get();

echo (new IcsSerializer)->serialize($calendar);
```

---

> 📖 **New here?** The [Recipes](docs/RECIPES.md) page has short, copy-paste examples for
> the most common tasks — start there.

## Table of contents

- [Why this library](#why-this-library)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Building calendars](#building-calendars)
- [Serializing to `.ics`](#serializing-to-ics)
- [Parsing `.ics`](#parsing-ics)
- [Reading data](#reading-data)
- [Editing immutably](#editing-immutably)
- [Dates, times & time zones](#dates-times--time-zones)
- [Durations](#durations)
- [Attendees & organizer](#attendees--organizer)
- [Alarms](#alarms)
- [Recurring events](#recurring-events)
- [Time zones](#time-zones)
- [Scheduling (iTIP)](#scheduling-itip)
- [Custom & unknown properties](#custom--unknown-properties)
- [Strict vs lenient](#strict-vs-lenient)
- [Error handling](#error-handling)
- [Gotchas & current limitations](#gotchas--current-limitations)
- [Architecture](#architecture)
- [Testing](#testing)
- [Roadmap](#roadmap)
- [License](#license)

---

## Why this library

[`sabre/vobject`](https://github.com/sabre-io/vobject) is the established option, but
it leans on stringly-typed array access and mutable objects. `erenav/icalendar` aims for:

- **Strong typing** — enums for parameters/statuses, dedicated value objects for dates,
  durations, periods, geo, etc. Typed value objects reject invalid construction.
- **Immutability** — every component and value is `readonly`. You mutate through a builder
  and get a fresh object.
- **Fluent construction** — `Event::build()->summary(...)->addAttendee(...)->get()`.
- **Semantic round-trips** — properties and components it doesn't model are retained as
  generic/raw values and re-emitted canonically. This is not arbitrary malformed-input or
  byte/source fidelity.
- **One focused runtime dependency** — `rlanvin/php-rrule`, isolated behind the
  `RecurrenceExpander` interface.

## Requirements

- PHP **8.3+**
- One runtime dependency: `rlanvin/php-rrule` for recurrence expansion

## Installation

```bash
composer require erenav/icalendar
```

## Quick start

```php
require 'vendor/autoload.php';

use Erenav\ICalendar\Component\{Calendar, Event};
use Erenav\ICalendar\Parser\Parser;
use Erenav\ICalendar\Serializer\IcsSerializer;
use Erenav\ICalendar\ValueType\Duration;

// Build
$calendar = Calendar::build()
    ->prodId('-//Acme//EN')
    ->add(
        Event::build()
            ->uid('1@acme.test')
            ->summary('Lunch')
            ->starts(new DateTimeImmutable('2026-07-01 12:00', new DateTimeZone('UTC')))
            ->lasting(Duration::hours(1))
    )
    ->get();

// Serialize
$ics = (new IcsSerializer)->serialize($calendar);

// Parse back
$parsed = Parser::lenient()->parseCalendar($ics);
echo $parsed->events()[0]->summary(); // "Lunch"
```

## Building calendars

`Calendar::build()`, `Event::build()` and `Alarm::build()` return **mutable builders**.
Calling `->get()` produces the **immutable** component.

```php
use Erenav\ICalendar\Component\{Calendar, Event};
use Erenav\ICalendar\Parameter\{Role, PartStat};
use Erenav\ICalendar\Property\{EventStatus, Transparency, Classification};
use Erenav\ICalendar\ValueType\Duration;

$event = Event::build()
    ->uid('meeting-42@acme.test')
    ->timestamp(new DateTimeImmutable('now', new DateTimeZone('UTC'))) // DTSTAMP
    ->summary('Sprint Planning, Q3')          // commas/semicolons escaped automatically
    ->description("Agenda:\n- demo\n- retro")
    ->location('Room 4')
    ->url('https://acme.test/meetings/42')
    ->starts(new DateTimeImmutable('2026-07-01 09:30', new DateTimeZone('UTC')))
    ->lasting(Duration::hours(1))             // or ->ends($dateTime)
    ->status(EventStatus::Confirmed)
    ->transparency(Transparency::Opaque)
    ->classification(Classification::Private)
    ->priority(5)
    ->categories('work', 'planning')
    ->color('cornflowerblue')                 // RFC 7986
    ->organizer('boss@acme.test', name: 'The Boss')
    ->addAttendee('alice@acme.test', role: Role::Chair, rsvp: true, name: 'Alice')
    ->addAttendee('bob@acme.test', partStat: PartStat::Accepted)
    ->get();

$calendar = Calendar::build()
    ->prodId('-//Acme//Booking 1.0//EN')      // VERSION defaults to 2.0
    ->name('Team Calendar')                   // RFC 7986
    ->add($event)                             // accepts components or builders
    ->get();
```

## Serializing to `.ics`

```php
use Erenav\ICalendar\Serializer\IcsSerializer;

$ics = (new IcsSerializer)->serialize($calendar);

// Strict mode enforces the serializer's selected required-property set;
// typed value objects validate when they are constructed.
$ics = (new IcsSerializer(strict: true))->serialize($calendar);
```

The serializer handles CRLF line endings, 75-octet line folding (UTF-8 safe), TEXT
escaping, RFC 6868 parameter encoding, and derives `TZID` / `VALUE` / `ENCODING`
parameters from the values themselves. Parameter carriage returns and line feeds are
normalized to RFC 6868 `^n`; non-TEXT values containing either character are rejected so
they cannot inject another content line. A manually assembled multi-value property is
also rejected when its values require incompatible controlling parameters, as is an
explicit `VALUE`, `TZID`, or `ENCODING` parameter that contradicts its typed value.

## Parsing `.ics`

```php
use Erenav\ICalendar\Parser\Parser;

$calendar = Parser::lenient()->parseCalendar($icsString); // returns Calendar
$component = Parser::lenient()->parse($icsString);         // returns the root Component

// Strict parsing throws on malformed structure and typed-value violations instead of recovering:
$calendar = Parser::strict()->parseCalendar($icsString);
```

Parsing provides **Level-1 semantic preservation** for supported RFC input: unknown
properties, unknown components, and unrecognized parameter values are retained rather
than discarded. Serialization is canonical, not byte-identical, and malformed recovery
has explicit limits (see [Gotchas](#gotchas--current-limitations)).

Strict parsing rejects duplicate or comma-multivalued controlling `TZID`, `VALUE`, and
`ENCODING` parameters, impossible DATE/DATE-TIME fields, contradictory `TZID` on DATE or
UTC DATE-TIME or non-temporal typed values, contradictory standard encodings, and invalid
TEXT escapes. Lenient parsing keeps the affected value and its controlling parameters raw
instead of choosing an interpretation. Structured
`REQUEST-STATUS` values are likewise retained raw so their status-code/description/data
semicolons are not mistaken for TEXT that needs escaping.

## Reading data

Typed getters read from the underlying model. Optional properties return `null`.

```php
$event = $calendar->events()[0];

$event->uid();            // ?string
$event->summary();        // ?string  (already unescaped)
$event->description();    // ?string
$event->location();       // ?string
$event->start();          // ?DateTimeValue
$event->end();            // ?DateTimeValue  (computed from DTSTART+DURATION if no DTEND)
$event->effectiveEnd();   // ?DateTimeValue  (also applies RFC implicit duration)
$event->duration();       // ?Duration
$event->status();         // ?EventStatus
$event->priority();       // ?int
$event->color();          // ?string
$event->categories();     // list<string>
$event->organizer();      // ?Organizer
$event->attendees();      // list<Attendee>
$event->alarms();         // list<Alarm>
$event->recurrenceId();   // ?DateTimeValue
$event->recurrenceRange(); // ?Range (a RECURRENCE-ID parameter)
$event->recurrenceDatePeriods(); // list<Period>

// Calendar level
$calendar->productId();   // ?string
$calendar->version();     // ?string
$calendar->name();        // ?string
$calendar->events();      // list<Event>
$calendar->components();  // list<Component>  (events, time zones, todos, …)
```

Anything without a dedicated getter is still reachable:

```php
$event->property('X-APPLE-TRAVEL-ADVISORY-BEHAVIOR')?->value()->toString();
$event->hasProperty('RRULE');
foreach ($event->properties as $property) { /* … */ }
```

## Editing immutably

Components are `readonly`. To change one, get a builder back, tweak it, and rebuild —
the original is untouched.

```php
$updated = $event->toBuilder()
    ->summary('Sprint Planning (rescheduled)')
    ->starts(new DateTimeImmutable('2026-07-02 09:30', new DateTimeZone('UTC')))
    ->get();

$event->summary();   // unchanged — original is immutable
$updated->summary(); // "Sprint Planning (rescheduled)"
```

## Dates, times & time zones

iCalendar distinguishes four date/time forms. The `DateTimeValue` value object models
all of them, and is the single source of truth for the `TZID` / `VALUE=DATE` parameters.

```php
use Erenav\ICalendar\ValueType\DateTimeValue;

DateTimeValue::utc(new DateTimeImmutable('2026-07-01 10:00', new DateTimeZone('UTC')));
//   → 20260701T100000Z

DateTimeValue::zoned(new DateTimeImmutable('2026-07-01 09:30'), 'America/New_York');
//   → DTSTART;TZID=America/New_York:20260701T093000

DateTimeValue::floating(new DateTimeImmutable('2026-07-01 09:30'));
//   → 20260701T093000   (no zone, "local" time)

DateTimeValue::date(new DateTimeImmutable('2026-07-01'));
//   → DTSTART;VALUE=DATE:20260701   (all-day)
```

`date()` and `floating()` copy the supplied calendar fields into a timezone-neutral
backing value; the source object's timezone does not turn them into instants. Likewise,
`zoned($dateTime, $tzid)` interprets the supplied fields in the explicit `TZID` instead of
converting an instant from the source timezone. For an ambiguous local time, the first
occurrence is selected. For a nonexistent local time during a forward transition, the
pre-transition UTC offset is used, as required by RFC 5545.

An elapsed `DURATION` can end at the second occurrence of a folded wall time, which a
TZID/local literal cannot distinguish from the RFC-selected first occurrence.
`Event::end()` returns UTC for that derived end so its instant stays exact. Calendar-level
range materialization rejects a cross-zone conversion that would otherwise change the
instant at such a fold.

`DateTimeValue::adding($duration)` applies day/week components in wall-clock coordinates
before exact hour/minute/second components, so `P1D` and `PT24H` correctly differ across
DST. For a custom or embedded TZID, pass its calendar resolver to
`$event->end($resolver)` / `$event->effectiveEnd($resolver)`, or call
`$resolver->addDuration($start, $duration)` directly.

The builder's date setters accept **any `DateTimeInterface`** (so Carbon works), or a
`DateTimeValue` when you need an explicit form:

```php
$event = Event::build()
    ->starts($carbonInstance)                                  // inferred form
    ->ends(DateTimeValue::zoned($dt, 'Europe/Paris'))          // explicit form
    ->get();

// All-day event:
$allDay = Event::build()
    ->starts(DateTimeValue::date(new DateTimeImmutable('2026-07-01')))
    ->ends(DateTimeValue::date(new DateTimeImmutable('2026-07-02')))
    ->get();
```

## Durations

`Duration` is a dedicated value object (not `DateInterval`) because the iCalendar
`DURATION` type forbids months/years, has a distinct week form, and must be immutable.
It bridges to native PHP both ways:

```php
use Erenav\ICalendar\ValueType\Duration;

Duration::hours(1);                 // PT1H
Duration::minutes(-15);             // -PT15M  (negative — e.g. an alarm trigger)
Duration::weeks(2);                 // P2W
Duration::of(days: 1, hours: 6);    // P1DT6H
Duration::parse('PT90M');           // from a string

// Interop (CarbonInterval extends DateInterval, so it works too):
Duration::fromDateInterval(new DateInterval('PT1H'));
Duration::hours(1)->toDateInterval();
```

For backward compatibility, `Duration::equals()` compares a context-free normalized
second count, so `P1D` and `PT24H` compare equal. RFC duration application can distinguish
them across a daylight-saving transition. Do not use `equals()` to infer equal event ends
in a named zone; apply each duration to its `DTSTART` and compare the resolved ends.

## Attendees & organizer

`addAttendee()` builds the `ATTENDEE` property and its parameters. `attendees()` returns
typed `Attendee` views; each exposes its complete underlying `Property`.

```php
use Erenav\ICalendar\Parameter\{Role, PartStat, CuType};

$event = Event::build()
    ->organizer('boss@acme.test', name: 'The Boss', sentBy: 'mailto:assistant@acme.test')
    ->addAttendee('alice@acme.test', role: Role::Chair, partStat: PartStat::Accepted, rsvp: true, name: 'Alice')
    ->addAttendee('room-a@acme.test', cuType: CuType::Room)
    ->get();

$attendee = $event->attendees()[0];     // a typed Attendee
$attendee->address()->toString();      // "mailto:alice@acme.test"
$attendee->email();                    // "alice@acme.test"
$attendee->role();                     // Role::Chair       (typed)
$attendee->participationStatus();      // PartStat::Accepted
$attendee->commonName();               // "Alice"
$attendee->rsvp();                     // true
$attendee->delegatedTo();              // list<CalAddress>
$attendee->delegatedFrom();            // list<CalAddress>
$attendee->members();                  // list<CalAddress>
$attendee->sentBy();                   // ?CalAddress
$attendee->directory();                // ?string (DIR URI)
$attendee->directoryUri();             // ?UriValue
$attendee->language();                 // ?string
$attendee->property;                   // the complete underlying Property escape hatch

$organizer = $event->organizer();
$organizer?->sentBy();                  // ?string (backward-compatible raw value)
$organizer?->sentByAddress();           // ?CalAddress
$organizer?->directory();               // ?string (DIR URI)
$organizer?->directoryUri();            // ?UriValue
$organizer?->language();                // ?string
```

The organizer builder accepts either an email address or `mailto:` URI for `sentBy` and
stores a canonical calendar address. Because this builder creates VEVENTs,
`addAttendee()` rejects the VTODO-only `PARTSTAT` values `COMPLETED` and `IN-PROCESS`.

Typed URI/calendar-address parameter accessors return `null` (or omit an invalid list
entry) when lenient input retained a malformed value. The complete raw parameter remains
available through the underlying `Property`.

The concise `organizer()` and `addAttendee()` builder methods intentionally cover common
parameters without a long positional API. When copying an external property, use the
complete-property methods so IANA, experimental, and less-common scheduling parameters
survive:

```php
$copy = Event::build();

if (($property = $source->property('UID')) !== null) {
    $copy->uidProperty($property);
}
if (($property = $source->property('DTSTART')) !== null) {
    $copy->startProperty($property);
}
if (($property = $source->property('RECURRENCE-ID')) !== null) {
    $copy->recurrenceIdProperty($property);
}
if (($property = $source->property('SEQUENCE')) !== null) {
    $copy->sequenceProperty($property);
}
if (($property = $source->property('ORGANIZER')) !== null) {
    $copy->organizerProperty($property);
}
foreach ($source->properties->all('ATTENDEE') as $property) {
    $copy->attendeeProperty($property);
}

$copiedEvent = $copy->get();
```

Each method requires a `Property` with the matching name; `attendeeProperty()` appends,
while the other methods replace that property name. Check optional source properties for
`null` before copying them.

## Alarms

```php
use Erenav\ICalendar\Component\{Event, Alarm};
use Erenav\ICalendar\Property\AlarmAction;
use Erenav\ICalendar\ValueType\Duration;

$event = Event::build()
    ->uid('1@acme.test')
    ->addAlarm(
        Alarm::build()
            ->action(AlarmAction::Display)
            ->description('Starts in 15 minutes')
            ->trigger(Duration::minutes(-15))   // relative; or pass a DateTimeInterface for absolute
    )
    ->get();

$event->alarms()[0]->action();   // AlarmAction::Display
$event->alarms()[0]->trigger();  // Duration (or DateTimeValue)
```

## Recurring events

Recurrence rules are modelled by the immutable `Recurrence` value object and built
fluently (each modifier returns a new instance):

```php
use Erenav\ICalendar\Recurrence\{Recurrence, Weekday, WeekdayRule};

Recurrence::daily()->times(10);                          // FREQ=DAILY;COUNT=10
Recurrence::weekly()->every(2)->on(Weekday::Monday, Weekday::Wednesday);
Recurrence::monthly()->on(new WeekdayRule(Weekday::Friday, -1)); // last Friday of the month
Recurrence::yearly()->until(new DateTimeImmutable('2030-01-01', new DateTimeZone('UTC')));
Recurrence::parse('FREQ=WEEKLY;BYDAY=MO,WE');           // from an RRULE string
```

Attach one to an event, with optional exception (`EXDATE`) and extra (`RDATE`) dates:

```php
use Erenav\ICalendar\ValueType\DateTimeValue;

$event = Event::build()
    ->uid('standup@acme.test')
    ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-07-01 09:30'), 'America/New_York'))
    ->recurrence(Recurrence::weekly()->on(Weekday::Monday, Weekday::Wednesday))
    ->addExceptionDate(new DateTimeImmutable('2026-12-25 09:30', new DateTimeZone('America/New_York')))
    ->get();

$event->isRecurring();     // true
$event->recurrenceRule();  // ?Recurrence
```

PERIOD-valued RDATEs carry an occurrence-specific end or duration. Add them with
`addRecurrencePeriod(Period ...$periods)` and inspect them with
`recurrenceDatePeriods()`. `EXDATE` removes their slots, detached overrides take normal
precedence, and a sparse range/single override retains a PERIOD slot's duration unless an
effective range or single explicitly replaces it. `addRecurrenceDate()` and
`addExceptionDate()` split adjacent DATE, floating, UTC, or differently zoned inputs into
separate parameter-compatible properties without reordering them; a single content line
cannot safely mix those forms. For a zoned explicit PERIOD, the end must remain later than
the start after authoritative embedded-VTIMEZONE resolution, not merely in lexical wall
time.

Unknown/IANA/experimental RRULE parts are retained in `Recurrence::$unknownParts` and
re-emitted after the canonically ordered known parts, preserving their relative input
order and duplicates. Lenient parsing preserves them; strict parsing rejects them.
Because an unknown part may change the occurrence set, the default expander throws
`UnsupportedRecurrenceException` instead of silently ignoring it. Invalid or duplicate
known RRULE parts similarly remain a `RawValue` in lenient parser mode and fail strict
mode. Programmatic construction validates RFC numeric ranges and contextual restrictions
and throws `InvalidValueException` when they are violated. A programmatic
`RecurrencePart` also rejects standard-part names, unescaped structural semicolons,
dangling escapes, and control bytes; escaped semicolons are preserved across repeated
round trips.

Expand the concrete occurrence starts in a window (`RRULE` + DATE/DATE-TIME/PERIOD
`RDATE` − `EXDATE`, DST-aware for resolvable TZID values — wall-clock time is preserved
across ordinary transitions):

```php
$from = new DateTimeImmutable('2026-07-01 00:00:00', new DateTimeZone('UTC'));
$to   = new DateTimeImmutable('2026-08-01 00:00:00', new DateTimeZone('UTC'));

foreach ($event->occurrencesBetween($from, $to) as $occurrence) {
    echo $occurrence->format('Y-m-d H:i'); // DateTimeImmutable
}
```

Expansion wraps [`rlanvin/php-rrule`](https://github.com/rlanvin/php-rrule) behind a
`RecurrenceExpander` interface — pass your own implementation to `occurrencesBetween()`
to swap the engine. `Event::occurrencesBetween()` intentionally remains start-only. Use
calendar-level expansion when a PERIOD's effective end/duration is needed.

UTC and resolvable-zoned DATE-TIME windows are ordinary instant bounds. DATE and floating
DATE-TIME values have no instant or viewer timezone, so the default expander represents
their fields in a neutral UTC-backed wall-clock coordinate. Query those series with UTC
bounds carrying the desired calendar fields (for example, `09:00 UTC` as the neutral
representation of floating `09:00`); returned `DateTimeImmutable` values are wall-clock
containers, not UTC instants.

### Modified & cancelled instances (`RECURRENCE-ID`)

A recurring series can have individual instances overridden by a second `VEVENT` with the
same `UID` plus a `RECURRENCE-ID`. Expand at the **calendar** level to resolve those —
`Calendar::occurrencesBetween()` returns rich `Occurrence` objects (the effective event
per instance), applying modifications and dropping cancellations:

```php
foreach ($calendar->occurrencesBetween($from, $to) as $occurrence) {
    $occurrence->start;        // DateTimeImmutable (may differ from the slot if moved)
    $occurrence->recurrenceId; // the original slot in the series
    $occurrence->event;        // master, detached override, or materialized range result
    $occurrence->isOverride;   // true if a RECURRENCE-ID override applied
    $occurrence->end;          // RFC-effective end (including PERIOD/implicit duration)
}
```

(`Event::occurrencesBetween()` expands a single event and returns bare
`DateTimeImmutable` starts;
`Calendar::occurrencesBetween()` is the override-aware version across the whole calendar.)

Build a range override with
`->recurrenceId($originalSlot, Range::ThisAndFuture)`. `RANGE` is a parameter of that
`RECURRENCE-ID`, not an independent event property, and is retained by `toBuilder()` and
iTIP replies.

Calendar windows are inclusive and apply to the **effective start**, so moved-in overrides
are included and moved-out overrides are excluded. `RANGE=THISANDFUTURE` propagates the
same fixed wall-coordinate start delta (not a reusable month/year interval), duration/end
and changed properties. Under the default expander's deterministic
sparse-range merge policy, a later range replaces values it states; earlier non-temporal
and duration changes remain effective when the later range does not replace them. Each
later range starts a new timing segment: omitting `DTSTART` resets the start delta to zero
rather than inheriting the prior move. A later non-cancelled range resumes a cancelled tail.

An explicit single-instance override wins for its slot and overlays only the properties it
supplies. Omitted values inherit from the active range, or from the materialized master
slot when no range is active; explicit `DTSTART`, `DTEND`, or `DURATION` wins, and supplied
child components replace the inherited child set. This sparse-merge policy means omission
does not clear inherited state. An active single override can restore its one slot inside a
cancelled range. The effective event is materialized coherently without recurrence-set
properties, while `Occurrence::$recurrenceId` continues to identify the original slot.
Every explicit source override retains its complete `RECURRENCE-ID`, including IANA/X
parameters (and `RANGE=THISANDFUTURE` on a range onset). Only synthetic later range events
use generated recurrence IDs without `RANGE` or slot-specific parameters, so re-exporting
one cannot accidentally reapply the range directive. A sparse orphan has no master state
to inherit; it receives a coherent `DTSTART` copied from its recurrence ID when absent.

Duplicate revisions are selected independently of document order: higher `SEQUENCE`
(missing is treated as `0`), then later `DTSTAMP`, then later `LAST-MODIFIED`; a present
timestamp sorts after a missing one. Remaining ties use lexicographic canonical ICS
content, with the greater value preferred. This is a deterministic import selection
policy based on RFC revision metadata, not application/provider conflict resolution.
When duplicate identities must be compared, ambiguous/non-RFC `SEQUENCE`, `DTSTAMP`, or
`LAST-MODIFIED` metadata is rejected rather than used to choose a winner.

## Time zones

Zoned date-times reference a `TZID`. For portability, a calendar can carry its own
`VTIMEZONE` definitions so clients don't need to know the zone. Calendar-level expansion
uses an embedded definition as authoritative for its `TZID`, including non-IANA ids such
as Outlook's `Eastern Standard Time`. `withTimeZones()` generates definitions from PHP's
tz database for every referenced IANA zone:

```php
$calendar = Calendar::build()
    ->prodId('-//Acme//EN')
    ->add(
        Event::build()->uid('1@acme')
            ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-07-01 09:30'), 'America/New_York')),
    )
    ->get()
    ->withTimeZones(); // prepends generated STANDARD/DAYLIGHT observances

$calendar->timeZones();          // list<TimeZone>
$calendar->timeZones()[0]->tzid(); // "America/New_York"
```

Parsed `VTIMEZONE` blocks are first-class `TimeZone` components with typed `Observance`
children:

```php
$tz = $calendar->timeZones()[0];
foreach ($tz->observances() as $observance) {
    $observance->isDaylight();        // bool
    $observance->offsetTo();          // ?UtcOffset
    $observance->recurrenceRule();    // ?Recurrence
    $observance->recurrenceDates();   // list<DateTimeValue>
}
```

You can also generate one directly: `(new TimeZoneGenerator())->forIana('Europe/Paris')`.
Canonical identifiers and IANA backward-compatibility links such as `US/Eastern` are
accepted.
The default generator inspects 1970–2100. Known rule eras are bounded with UTC `UNTIL`,
irregular transitions use exact `RDATE`s, and only a stable suffix verified for at least
five consecutive years through the horizon for every side of a complete transition cycle
remains unbounded. Supply explicit constructor bounds when another coverage horizon is
required. `TimeZoneResolver::fromCalendar()` is the public calendar-scoped resolver used
by default expansion.

## Scheduling (iTIP)

Build common [RFC 5546](https://www.rfc-editor.org/rfc/rfc5546) scheduling messages —
invitations, replies, and cancellations — with the appropriate `METHOD` via `ITip`.
The source event must still contain the properties required by that transaction; validate
the result when consuming untrusted or dynamically assembled data:

```php
use Erenav\ICalendar\Scheduling\{ITip, ITipValidator};
use Erenav\ICalendar\Parameter\PartStat;

$request = ITip::request($event);                                      // organizer invites attendees
$reply   = ITip::reply($event, 'alice@acme.test', PartStat::Accepted); // attendee responds
$cancel  = ITip::cancel($event);                                       // + STATUS:CANCELLED, SEQUENCE++
$publish = ITip::publish([$eventA, $eventB]);                          // a non-interactive feed

$request->schedulingMethod(); // Method::Request   (typed METHOD getter)
```

Validate a message against its method's constraints:

```php
$validator = new ITipValidator();

$validator->isValid($request);     // bool
$validator->validate($request);    // list<string> of problems (empty = valid)
$validator->assertValid($request); // throws SchedulingException if invalid
```

Within its documented transaction subset, validation also rejects duplicate or
multi-valued `METHOD` and required singleton properties. Methods with attendee
cardinality rules reject an `ATTENDEE` property that carries multiple calendar addresses.

`ITip::reply()` copies the complete source `UID`, `DTSTART`, `RECURRENCE-ID` (including
`RANGE`), `SEQUENCE`, `ORGANIZER`, and matching `ATTENDEE` properties when present. It
intentionally generates a fresh `DTSTAMP`, replaces the attendee's `PARTSTAT`, and removes
`RSVP` because that request parameter is forbidden on a VEVENT REPLY; all other standard,
IANA, and experimental parameters are retained. It rejects malformed/untyped
or duplicate singleton `UID`, `DTSTART`, `RECURRENCE-ID`, `SEQUENCE`, or `ORGANIZER`
metadata (and a missing UID) instead of producing a lossy reply. A newly created CANCEL
also receives a fresh `DTSTAMP`. `ITip::publish([])` is invalid and throws
`SchedulingException`.

For the covered VEVENT transactions, validation requires one UTC DATE-TIME `DTSTAMP`
without `TZID`, validates any `SEQUENCE` as one non-negative INTEGER, rejects the
VTODO-only attendee states `COMPLETED` and `IN-PROCESS`, and rejects `RSVP` on REPLY.
CANCEL requires `SEQUENCE`; its optional `STATUS`, when present, must be the singleton
`CANCELLED`. REQUEST and CANCEL builders reject duplicate, multi-valued, untyped, or
negative source `SEQUENCE`, and CANCEL refuses to overflow the RFC INTEGER range when
incrementing it.

In the [Laravel package](https://github.com/erenav/laravel-icalendar), attaching an iTIP
calendar advertises the method in the MIME type (`text/calendar; method=REQUEST`), so mail
clients treat it as an invitation.

## Custom & unknown properties

Add arbitrary properties with `->property()` (it appends, so it can repeat):

```php
$event = Event::build()
    ->uid('1@acme.test')
    ->property('X-ACME-ROOM-ID', '4')
    ->get();
```

When **parsing**, unsupported property values are retained as `RawValue` where the parser
can recover (and unknown components become a `GenericComponent`), then re-emitted
semantically. Canonical serialization and malformed-input limits still apply:

```php
$event->property('X-ACME-ROOM-ID')?->value()->toString(); // "4"
// VTODO / VJOURNAL etc. survive as GenericComponent; VTIMEZONE is a typed TimeZone.
```

## Strict vs lenient

Both the parser and serializer have a strict mode. **Lenient is the default**, because
real-world `.ics` files frequently bend the RFC.

| Mode | Parser | Serializer |
|---|---|---|
| **Lenient** (default) | Recovers from violations; unparseable values become `RawValue`; duplicate parameters remain ambiguous | Skips required-property checks but still enforces content-line-safe value encoding |
| **Strict** | Throws on malformed structure and typed-value violations | Enforces the package's selected required-property set |

```php
Parser::strict()->parseCalendar($ics);          // reject supported structural/typed violations
(new IcsSerializer(strict: true))->serialize($c); // validate output before sending
```

## Error handling

Every exception implements `Erenav\ICalendar\Exception\ICalendarException`, so you can
catch the whole family at once.

```php
use Erenav\ICalendar\Exception\{ICalendarException, ParseException, InvalidValueException, MissingPropertyException};

try {
    $calendar = Parser::strict()->parseCalendar($ics);
} catch (ParseException $e) {
    // malformed input in strict mode
} catch (ICalendarException $e) {
    // any other library error
}
```

- `InvalidValueException` — building an illegal value (bad duration, out-of-range geo, …).
- `ParseException` — malformed input; strict mode rejects additional violations.
- `MissingPropertyException` — required property absent (strict serialization only).
- `SchedulingException` — invalid iTIP construction or validation.
- `Recurrence\UnsupportedRecurrenceException` — preserved recurrence data cannot be
  expanded safely by the selected core behavior.

## Gotchas & current limitations

- **You must set `UID` and `DTSTAMP` yourself.** They are not auto-generated.
  `$event->uid()` returns `null` if absent. Use strict serialization to catch this.
- **Use the calendar-level expander for overrides.** `Event::occurrencesBetween()` expands
  one event in isolation and ignores `RECURRENCE-ID` overrides. To honour modified/cancelled
  instances, expand the whole calendar with `Calendar::occurrencesBetween()`.
- **DATE and floating recurrence windows are wall-clock coordinates.** The default
  expander uses a neutral UTC-backed container for their fields. Supply UTC bounds whose
  displayed fields are the desired calendar limits; do not interpret returned values as
  UTC instants without first choosing an application timezone. A calendar mixing these
  coordinates with instant-based events is sorted deterministically by backing values,
  not by a universal real-world chronology (none exists without a viewer timezone).
- **`Duration::equals()` is context-free.** It normalizes components to seconds for
  backward compatibility (`P1D` equals `PT24H`), which is not sufficient for comparing
  effective ends across a daylight-saving transition. Resolve both ends in context.
- **Recurrence rules whose candidate sets reach a DST gap in a selection interval that
  can affect the requested expansion horizon are rejected by the default expander.**
  Explicit values stay typed and re-export with their original wall fields,
  and a non-recurring gap occurrence uses RFC 5545's pre-transition offset. RFC 5545
  requires a rule-generated nonexistent local instance to be ignored without being
  counted, while the recurrence dependency normalizes and counts it. The safety check also
  examines candidates that RFC processing would remove before `BYSETPOS`, because one can
  change an earlier selected instance in the same `FREQ` interval even when the candidate
  lies after the query bound or `UNTIL`, or `COUNT` appears complete before the candidate.
  Expansion therefore throws `UnsupportedRecurrenceException` instead of returning an
  altered recurrence set. A finite rule completed in an earlier, unaffected interval
  remains expandable.
- **Range shifts into a DST gap are a distinct supported case.** If a valid resolvable-zoned
  range starts from representable inputs and its wall-clock delta newly moves a later slot
  into a gap, the materialized event retains that wall literal and uses the RFC 5545
  pre-transition offset for its instant. Range inputs that already contain unresolved or
  gap representations are rejected; rule-generated base gap candidates
  are rejected by the recurrence safety check described above.
- **Unsupported recurrence semantics fail closed.** Unknown RRULE parts, untyped or
  duplicate/multi-valued singleton recurrence properties, incompatible DATE/DATE-TIME or
  floating/instant recurrence-set forms, unresolved zoned instants, recurrence properties
  without a typed `DTSTART`, unsynchronized DTSTART/RRULE pairs (undefined by RFC 5545),
  recurrence-set properties on detached `RECURRENCE-ID` components, and DATE rules with
  sub-daily parts or sub-day DURATION values are not partially expanded. A UID-less
  recurrence ID cannot be resolved; a `RANGE=THISANDFUTURE` override must target a real
  master slot and requires a typed master `DTSTART`. Cross-zone materialization that would map an
  instant to the unrepresentable second occurrence of a local-time fold is rejected instead
  of shifting it silently.
- **`Event::end()` retains its historical explicit-only behavior.** For backward
  compatibility, it returns `null` without `DTEND` or `DURATION`. Use
  `Event::effectiveEnd()` or `Occurrence::$end` for RFC 5545's implicit one-day DATE and
  zero-duration DATE-TIME ends.
- **Leap seconds are preserved only where the value model can represent them.** RFC-valid
  `BYSECOND=60` rules are retained but rejected by the default expander because PHP and
  the recurrence dependency normalize them incorrectly. DATE-TIME literals ending in
  second 60 cannot currently be represented and are raw in lenient mode.
- **The default recurrence dependency has a sparse-rule cutoff.**
  `rlanvin/php-rrule` stops after 28 consecutive YEARLY intervals (and corresponding
  limits for other frequencies) that produce no occurrence. That 28-year shortcut is not
  a complete 400-year Gregorian cycle, so an otherwise valid, extremely sparse rule can
  truncate across a non-leap century. This remains unresolved in the bundled expander;
  use a replacement `RecurrenceExpander` when such rules are in scope.
- **Strict mode is not a complete RFC validator.** It validates component structure,
  typed values, and selected required properties, but does not enforce every RFC 5545
  property cardinality, component-context rule, or cross-property constraint.
- **iTIP validation is a pragmatic subset.** `ITipValidator` covers the common transaction
  requirements implemented by this package, not every RFC 5546 table entry and state
  transition.
- **Generated VTIMEZONE coverage is finite.** The default 1970–2100 inspection horizon is
  much broader than the former representative window and preserves known rule changes,
  but it cannot predict political changes not present in the installed tz database. A
  stable final rule is projected beyond the horizon; choose explicit generator bounds for
  other historical coverage.
- **Embedded VTIMEZONE arithmetic is intentionally typed and fail-closed.** The resolver
  supports STANDARD/DAYLIGHT `DTSTART`, `TZOFFSETFROM`, `TZOFFSETTO`, RRULE and RDATE,
  including gaps/folds and authoritative definitions whose TZID is also an IANA name.
  Missing, duplicate, discontinuous, raw, or recurrence-unsupported definitions are
  preserved but rejected if expansion actually needs them.
- **"Level-1" round-trip ≠ byte-identical or arbitrary-malformed-input fidelity.** For
  supported RFC data, `serialize(parse($ics))` preserves the semantic model and component
  property order while canonicalizing numeric/text forms, parameter quoting/order, escaping,
  and folding. Duplicate invalid parameters and other malformed constructs may normalize or
  be rejected. Byte/source fidelity is a separate future level.
- **COLOR validation is intentionally limited.** RFC 7986 expects a CSS color name. The
  builder currently preserves the supplied text but does not validate the CSS name registry.
- **Immutability surprise:** builder methods that read like mutations (`addAttendee`)
  mutate the *builder*; the produced component is immutable. Edit an existing component
  via `->toBuilder()`.
- **No Laravel glue here.** The framework integration (`erenav/laravel-icalendar`) is a
  separate package (phase 4). This core has zero framework dependencies.

## Architecture

A layered, immutable object model. The canonical state of every component is its ordered
property bag, which enables semantic preservation of supported unknown data.

```
Builder      (mutable, fluent)        →  produces  →  Component (immutable)
Component    (Composite: Calendar ▸ Event ▸ Alarm)
  └ holds → PropertyBag (ordered, preserves unknowns)
Property     (name + typed values + parameters)
  ├ value  → ValueType (DateTimeValue, Duration, Period, TextValue, RawValue, …)
  └ params → Parameter (Role, PartStat, … enums + RawParameter fallback)

Parser:     text → unfold → split content lines → hydrate values → assemble tree
Serializer: Component → content lines → fold → text   (Strategy: Ics, future jCal/xCal)
```

Patterns in use: **Composite** (component tree), **Builder** (fluent construction),
**Strategy** (`Serializer` interface), **Factory** (value-type construction), and a
**pipeline** parser. See [`docs/PHASE-1-SPEC.md`](docs/PHASE-1-SPEC.md) for the full
design and decision record.

## Testing

```bash
composer install
composer check         # formatting check + PHPStan + PHPUnit
```

Use `composer test` (or `vendor/bin/phpunit`) when only the test suite is needed.

The suite is split into `tests/Unit` (per-class) and `tests/Integration`
(serializer + round-trip). Round-trip stability is asserted as a fixed point:
`serialize(parse(x))` equals `serialize(parse(serialize(parse(x))))`.

## Roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | Core model, parse/serialize, Level-1 semantic preservation (documented RFC 5545 + 7986 subset) | ✅ done |
| 2 | Recurrence + time zones — `occurrencesBetween()`, `RECURRENCE-ID` overrides, `VTIMEZONE` generation/typed components | ✅ done |
| 3 | iTIP scheduling (RFC 5546) — METHOD, message builders, validation | ✅ done |
| 4 | [`erenav/laravel-icalendar`](https://github.com/erenav/laravel-icalendar) — service provider, facade, Eloquent mapping, feeds, Artisan, notifications | ✅ released separately |
| 5 | jCal/xCal serializers and byte-fidelity round-trip | someday |

## License

MIT
