# erenav/icalendar — Design & Roadmap

Two-package project:

- **`erenav/icalendar`** — framework-agnostic core (this repo).
- **`erenav/laravel-icalendar`** — Laravel wrapper, depends on the core (phase 4).

Namespace: `Erenav\ICalendar`. PHP **8.3+**. The core has one runtime dependency,
`rlanvin/php-rrule`, behind the recurrence-expander interface, and no framework dependency.

---

## Decision record

| # | Decision | Choice |
|---|----------|--------|
| A | Round-trip fidelity | **Level 1 — semantic round-trip.** Unknown / `X-` / unmodeled IANA properties and parameters are represented by ordinary `Property`, `RawValue`, and `RawParameter` objects and re-emitted in component order. Output is canonicalized, **not** byte-identical. Malformed input outside the supported recovery model can be rejected or normalized; byte/source fidelity is a separate future level. |
| B | Builder ergonomics | **Mutable fluent builder → immutable `readonly` product.** `Event::build()->summary(...)->get()`. Edit via `$event->toBuilder()->...->get()`. |
| C | RRULE expansion engine | **Wrap `rlanvin/php-rrule` behind our own `RecurrenceExpander` interface** (phase 2), so we can swap to a homegrown engine later. We own the typed `Recurrence` VO + its serialization. |
| — | Class naming | **No `V` prefix.** `Event`, `Calendar`, `Alarm`, … The `VEVENT`/`VCALENDAR` wire token lives on each component (`wireName()`). |
| — | Parser strictness | **Lenient by default** (`Parser::lenient()`): preserve recoverable malformed values and ambiguous controlling parameters as raw data rather than guessing. `Parser::strict()` throws for malformed structure and typed-value violations; it is not a complete RFC cardinality/context validator. |
| — | External recurrence safety | Known RRULE parts stay typed/canonical; unknown parts preserve relative order after them. Unknown RRULE parts and malformed recurrence properties are retained where possible but rejected explicitly during unsafe expansion. PERIOD RDATEs retain their own effective ends. Calendar windows use effective starts. The bundled engine's sparse-rule cycle cutoff remains a documented limitation. |
| — | Revision selection | Duplicate masters and overrides use higher SEQUENCE (missing = 0), then later DTSTAMP, later LAST-MODIFIED, then lexicographically greater canonical ICS. Metadata is validated lazily at the tier needed to decide. This deterministic import policy is not provider conflict resolution. |
| — | Dates | Core uses `DateTimeImmutable` / `DateTimeInterface` only. DATE/floating values are neutral wall fields; zoned fields are interpreted in their explicit TZID. Carbon flows into builders, but core never references it. |
| — | DATE/floating windows | DATE and floating recurrence output/query limits use neutral UTC-backed wall-clock containers, not UTC instants. Callers supply UTC bounds containing the desired calendar fields. |

## RFC scope

| RFC | What | When |
|-----|------|------|
| 5545 | Implemented iCalendar core subset (components, properties, params, value types, RRULE, VTIMEZONE) | Phases 1–2 |
| 7986 | Selected new properties, including COLOR, IMAGE, and CONFERENCE | Phase 1/3 |
| 5546 | Common iTIP transactions and pragmatic validation (REQUEST/REPLY/CANCEL/PUBLISH, METHOD) | Phase 3 |
| 6321 / 7265 | xCal / jCal serializations | Someday (drop-in via serializer Strategy seam) |
| 6047 / 7529 | iMIP / non-Gregorian recurrence | Not planned |

---

## Architecture

```
Builder      (mutable, fluent)        →  produces  →  Component (immutable)
Component    (Composite tree: Calendar ▸ Event ▸ Alarm)
  └ holds → PropertyBag (ordered, preserves unknowns ← Level-1 key)
Property     (generic ordered property; typed access lives on components)
  ├ value  → ValueType (DateTimeValue, Duration, Period, CalAddress, …)
  └ params → Parameter[] (PartStat, Role, Tzid, … enums + VOs)

Parser:     bytes → LineUnfolder → split content line → hydrate values → frame stack → Component
Serializer: Component → properties/components → content-line text → UTF-8 fold → CRLF bytes
```

The **canonical state of every component is its ordered `PropertyBag`.** Typed getters
and the builder are ergonomics on top of that bag. Keeping unknown properties and
parameters in the bag enables Level-1 semantic preservation; canonicalization and
malformed-recovery limits still apply.

### Design patterns in play

- **Composite** — component tree (Calendar contains Events contains Alarms).
- **Builder** — fluent construction; mutable builder, immutable product.
- **Strategy** — the serializer interface is the seam for future jCal/xCal implementations.
- **Factory** — value-type construction (DATE vs DATE-TIME vs DURATION from raw strings).
- **Pipeline** — parser stages (unfold → content-line → assemble → hydrate).
- **Service abstraction** — recurrence expansion is replaceable behind `RecurrenceExpander`.

---

## Public API surface (selected)

### Component layer

```php
namespace Erenav\ICalendar\Component;

abstract readonly class Component
{
    public function __construct(
        public PropertyBag $properties,        // ordered; source of truth
        public ComponentList $children = new ComponentList(),
    ) {}

    abstract public function wireName(): string;   // 'VEVENT', 'VCALENDAR', …
}

final readonly class Event extends Component
{
    public function wireName(): string { return 'VEVENT'; }
    public function toBuilder(): EventBuilder;      // immutable → mutable

    public function uid(): ?string;
    public function summary(): ?string;
    public function start(): ?DateTimeValue;
    public function end(?TimeZoneResolver $resolver = null): ?DateTimeValue;
    public function effectiveEnd(?TimeZoneResolver $resolver = null): ?DateTimeValue;
    public function attendees(): array;               // list<Attendee>
    public function organizer(): ?Organizer;
    public function status(): ?EventStatus;
    public function color(): ?string;
    public function recurrenceRule(): ?Recurrence;
    public function recurrenceId(): ?DateTimeValue;
    public function recurrenceRange(): ?Range;       // parameter on RECURRENCE-ID
    public function recurrenceDatePeriods(): array;  // list<Period>; expanded with own end
}

final readonly class Calendar extends Component
{
    public function toBuilder(): CalendarBuilder;
    public function name(): ?string;
}
```

`Calendar`, `Event`, `Alarm`, and `TimeZone` have dedicated component types. `Todo`,
`Journal`, and `FreeBusy` currently round-trip as `GenericComponent` instances.

### Builder

```php
$event = Event::build()
    ->uid('meeting-42@app.test')              // caller-controlled (Eloquent needs this)
    ->summary('Sprint Planning')
    ->description("Line 1\nLine 2")           // escaping handled internally
    ->starts($carbonOrDateTime)               // DateTimeInterface → Carbon works
    ->ends($carbonOrDateTime)                 // or ->lasting(Duration::hours(1))
    ->location('Room 4')
    ->organizer('boss@app.test', name: 'The Boss')
    ->addAttendee('a@app.test', role: Role::ReqParticipant, rsvp: true)
    ->addAttendee('b@app.test', partStat: PartStat::Accepted)
    ->status(EventStatus::Confirmed)
    ->categories('work', 'planning')
    ->color('blue')                           // RFC 7986 CSS color name
    ->property('X-CUSTOM-FLAG', 'yes')        // escape hatch for anything unmodeled
    ->get();                                  // → immutable Event

$calendar = Calendar::build()
    ->prodId('-//Erenav//ICalendar 1.0//EN')
    ->add($event)
    ->get();
```

Builder verbs are imperative and mutate-then-return-`$this`. Products are `readonly`.
`uidProperty()`, `startProperty()`, `recurrenceIdProperty()`, `sequenceProperty()`, and
`organizerProperty()` replace one complete, name-checked property;
`attendeeProperty()` appends one. These APIs preserve parameters when copying external
data without lengthening `addAttendee()`'s positional signature.

### Token enums

Parameter enums include `Role`, `PartStat`, `CuType`, `FreeBusyType`, `RelationType`, and
`ValueDataType`; property-value enums include `EventStatus`, `Transparency`,
`Classification`, and `AlarmAction`. Recognized tokens are read case-insensitively.
Unknown/IANA parameter tokens fall back to `RawParameter`; unrecognized text property
tokens remain `TextValue` and their typed accessor returns `null`.

### Value types

`DateTimeValue` (DateTimeImmutable + TZID + isDateOnly), `Duration`, `Period`,
`CalAddress`, `UtcOffset`, `GeoValue`, `Recurrence`. Carbon normalizes to
`DateTimeImmutable` inside `DateTimeValue`.

**Wrap-with-interop principle.** A ValueType wraps a native PHP type whenever iCalendar
carries semantics the native type can't express, and always provides `from*()`/`to*()`
bridges so callers are never boxed in:

- `Duration` is its own type (not `DateInterval`) because RFC 5545's `DURATION` domain ≠
  `DateInterval`'s: the RFC forbids months/years, has a distinct week form (`P2W`) that
  `DateInterval` normalizes away, and `DateInterval` is mutable. `Duration` is `readonly`,
  rejects months/years, preserves weeks — and provides `fromDateInterval()` /
  `toDateInterval()`. Builders accept `Duration|DateInterval` (so `CarbonInterval`, which
  extends `DateInterval`, works too).
- `DateTimeValue` wraps `DateTimeImmutable` because it must also carry `TZID` and the
  `VALUE=DATE` vs `DATE-TIME` distinction, and the three RFC forms (floating / UTC / zoned).
  DATE/floating factories copy lexical fields into a neutral backing value; UTC values
  normalize to the RFC's whole-second precision. `zoned()`
  interprets fields in the named TZID, selecting the first fold occurrence and the
  pre-transition offset for a gap, per RFC 5545.
- A DURATION-derived end at the second occurrence of a DST fold is returned in UTC because
  the local TZID literal cannot identify that instant. Unsafe cross-zone fold
  materialization fails closed.

Value-object invariants are enforced at construction (e.g. a `Duration` mixing weeks with
days, a `Period` with both an end and a duration, or an out-of-range RRULE BY-part).
`Duration::equals()` remains a backward-compatible context-free seconds comparison, so it
must not be used to infer equal effective ends across a DST transition.
`CalAddress` and `UriValue` reject carriage returns and line feeds so a programmatically
constructed value cannot inject another content line.

### Recurrence and import semantics

- Known RRULE parts retain typed, canonical serialization. Lenient parsing keeps unknown
  IANA/experimental parts in relative input order and repeated semantic round trips retain
  them; strict parsing rejects them. Invalid known parts become one `RawValue` leniently
  and fail strictly.
- PERIOD-valued RDATEs are available through `Event::recurrenceDatePeriods()` and the
  default expander materializes their occurrence-specific duration/end. The event-level
  expansion API remains start-only; calendar-level `Occurrence::$end` carries the detail.
  EXDATE and detached override precedence apply normally; sparse range/single overlays
  retain the period duration until effective timing explicitly replaces it. Explicit ends
  are rechecked after authoritative timezone resolution and must be later instants than
  their starts.
- `EventBuilder::addRecurrenceDate()` and `addExceptionDate()` group only adjacent values
  with a common DATE/floating/UTC/TZID signature. Heterogeneous input order is retained in
  separate parameter-compatible content lines; manually assembled incompatible
  multi-value properties fail serialization.
- Calendar-level windows are inclusive on effective starts. Range propagation uses a
  fixed wall-coordinate time delta (never a reusable month/year interval),
  materializes a coherent effective event, retains the original slot as recurrence ID,
  and gives a later single override precedence at its slot. Sparse ranges inherit earlier
  non-temporal/duration changes until replaced; a later range without DTSTART resets the
  earlier start delta to zero, and a later non-cancelled range resumes a
  cancelled tail under the default expander's documented deterministic policy. A sparse
  single overlays only supplied properties and otherwise inherits the active range or
  materialized master slot; supplied children replace inherited children, and an active
  single may restore one slot in a cancelled range. Every explicit source override keeps
  its complete `RECURRENCE-ID`, including IANA/X parameters and RANGE on a range onset.
  Only synthetic future range events use generated recurrence IDs without `RANGE` or
  slot-specific parameters. Sparse orphan overrides synthesize DTSTART from their RID.
- Recurring IANA-zoned and embedded-VTIMEZONE values use wall-clock rules across ordinary DST transitions. A
  rule whose candidate set reaches a nonexistent DST-gap local time in a selection
  interval that can affect the requested expansion horizon is rejected. A candidate
  discarded before `BYSETPOS` can change an earlier selection in the same `FREQ` interval
  even after the query bound or `UNTIL`, or when `COUNT` appears complete before it; a
  finite rule completed in an earlier, unaffected interval remains expandable. A valid range delta
  that newly lands in a gap is materialized with its original wall literal and the instant
  obtained from RFC 5545's pre-transition offset. Existing unresolved/gap range
  inputs, rule-generated gap candidates, any unresolved TZID instant participating
  in expansion/override resolution, mixed floating/instant recurrence-set values,
  leap-second rules, unsynchronized DTSTART/RRULE pairs, recurrence-set properties on
  detached components, UID-less recurrence IDs, DATE recurrences with sub-day durations,
  and unsafe malformed or multi-valued singleton recurrence properties are rejected
  explicitly.
- iTIP replies copy complete identity, recurrence, organizer, and matching attendee
  properties where RFC semantics permit. They replace DTSTAMP and the replying PARTSTAT,
  and remove RSVP because RFC 5546 forbids it on the VEVENT REPLY attendee; other
  scheduling parameters remain intact. Covered transactions validate UTC DTSTAMP,
  singleton/non-negative SEQUENCE, VEVENT PARTSTAT, REPLY RSVP, and optional CANCEL
  STATUS constraints before ambiguous metadata can be emitted.

### Parser pipeline

1. `LineUnfolder` — RFC 5545 §3.1 unfolding.
2. `Parser::splitContentLine()` — `NAME;PARAM=VAL:VALUE`, including quoted parameters.
3. A `ComponentFrame` stack — `BEGIN`/`END` assembly into the Composite tree.
4. Property hydration — supported values become typed VOs; unsupported or malformed
   values become `RawValue` in lenient mode. Duplicate parameter names fail strict mode;
   lenient mode keeps their logical values ambiguous instead of choosing one silently.
   Comma-multivalued `TZID`, `VALUE`, or `ENCODING` has the same fail-strict/preserve-raw
   policy. `REQUEST-STATUS` remains raw structured data so its semicolons are not escaped
   as TEXT.

`Parser::lenient()` (default) vs `Parser::strict()`.

### Serializer

`IcsSerializer implements Serializer` — `serialize(Component): string`. Strategy seam so
`JcalSerializer` / `XcalSerializer` drop in later. Handles 75-octet line folding,
TEXT escaping, RFC 6868 parameter encoding, and CRLF output. Parameter CR/LF is normalized
to `^n`; CR/LF in non-TEXT values is rejected. Typed values on one content line must agree
on their controlling `VALUE`, `TZID`, and `ENCODING` interpretation.

### Exceptions

```
ICalendarException (base)
 ├ ParseException        (structural or typed input parsing failure)
 ├ InvalidValueException (invalid programmatic value construction)
 ├ MissingPropertyException (e.g. strict serialization without required metadata)
 ├ SchedulingException   (invalid iTIP construction/validation)
 └ UnsupportedRecurrenceException (preserved recurrence cannot be expanded safely)
```

### Level-1 preservation guarantee

For supported RFC input, `parse → serialize` preserves the semantic property/value/parameter
model and property order within a component. Output is not byte-identical: casing, numeric
formatting, parameter quoting/order, escaping, and folding may canonicalize. Lenient mode
preserves unsupported values as `RawValue` where possible; it is not a source-span or
arbitrary-malformed-input fidelity layer.

---

## Eloquent-readiness constraints baked into core

Core never references Laravel/Eloquent, but these are core requirements *because* of the
phase-4 mapping (omitting them would be a painful retrofit):

1. **UID is caller-controllable** (`->uid(...)`) — Eloquent models need stable deterministic UIDs.
2. **Builders accept `DateTimeInterface`** — Eloquent's Carbon casts flow straight in.
3. **`Component::toBuilder()` + typed getters** — lets the Laravel layer decompose an `Event` back into model columns.
4. **No mutable global service singleton** — static factory/transaction entry points are
   convenience APIs; parser, serializer, resolver, comparator, and recurrence services
   remain independently constructible and replaceable for testing or container use.

## Verification

Run `composer check` as the primary local gate; it executes the formatting check, PHPStan,
and PHPUnit. `composer test` remains available when only PHPUnit is needed.

---

## Roadmap

1. **Core model + parser + ICS serializer** — delivered: Composite tree, typed values,
   builders, lenient/strict parsing, and semantic round-trip corpus tests.
2. **Recurrence + timezones** — delivered with explicit limitations: list-returning
   `occurrencesBetween()`, RRULE/RDATE/EXDATE, override resolution, and typed/generated
   VTIMEZONE support, PERIOD RDATE duration/end materialization, and calendar-scoped
   embedded-VTIMEZONE arithmetic. Recurring rules whose candidate sets reach DST gaps in
   a selection interval affecting an expansion horizon remain fail-closed. The bundled
   recurrence engine can still truncate a valid rule after 28 consecutive empty YEARLY
   intervals (and corresponding frequency cutoffs), which is not a complete 400-year
   Gregorian search.
3. **RFC 7986 + iTIP (5546)** — common properties and scheduling transactions are present;
   validation intentionally covers a documented subset of the full RFC tables.
4. **`erenav/laravel-icalendar`** — service provider, config, `Calendar` facade, Eloquent mapping (`ProvidesCalendarEvent` contract + `InteractsWithCalendar` trait), Artisan commands, notification channel, Carbon at the boundary.
5. **Someday** — jCal/xCal serializers, Level-2 byte-fidelity.

### Phase 1 deliverable boundary

**In:** Calendar/Event/Alarm typed; selected 5545 + 7986 event properties; value types;
builders; ICS parse+serialize; Level-1 semantic preservation; lenient+strict; PHPUnit +
round-trip corpus.
**Out:** recurrence expansion (p2), VTIMEZONE generation (p2), iTIP (p3), Laravel (p4), jCal/xCal (someday).
