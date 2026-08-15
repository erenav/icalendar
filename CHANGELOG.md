# Changelog

All notable changes to `erenav/icalendar` are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2026-08-15

### Added
- Ordered `RecurrencePart` support for semantically preserving unknown RRULE segments,
  numeric/contextual BY-part validation, and explicit `UnsupportedRecurrenceException`
  for unsafe expansion.
- `Event::recurrenceRange()`, RANGE-aware `EventBuilder::recurrenceId()`, coherent
  `THISANDFUTURE` expansion, deterministic `EventRevisionComparator`, and effective-start
  calendar window semantics.
- `Event::recurrenceDatePeriods()`, `Event::effectiveEnd()`, `Event::color()`, and
  `Calendar::name()` read APIs; `EventBuilder::addRecurrencePeriod()` and
  RFC-effective `Occurrence::$end` support.
- Calendar-scoped `TimeZoneResolver` arithmetic for embedded VTIMEZONE definitions,
  including non-IANA TZIDs, RRULE/RDATE observances, gaps/folds, recurrence, PERIODs,
  detached overrides, and RANGE propagation.
- Complete-property `EventBuilder` methods for UID, DTSTART, RECURRENCE-ID, SEQUENCE,
  ORGANIZER, and ATTENDEE, plus standard attendee/organizer scheduling accessors with
  typed calendar-address/URI companions.

### Changed
- Known RRULE parts retain their existing canonical order while unknown parts are emitted
  afterward in stable relative input order. Strict parsing rejects unknown parts; lenient
  parsing retains them, but the default expander refuses to guess their semantics.
- DATE and floating DATE-TIME values now have timezone-neutral wall-clock backing values.
  `DateTimeValue::zoned()` interprets supplied fields in its explicit TZID, and resolves
  DST gaps/folds according to RFC 5545 (pre-transition offset / first occurrence).
- UTC DATE-TIME backing values now normalize to RFC whole-second precision, matching their
  serialized form instead of retaining invisible microseconds.
- `EventBuilder` splits heterogeneous DATE/floating/UTC/TZID inputs to RDATE and EXDATE
  into adjacent parameter-compatible properties without reordering them. Serialization
  rejects manually assembled multi-value properties whose typed values require conflicting
  `VALUE`, `TZID`, or `ENCODING` interpretations, and rejects explicit controlling
  parameters that contradict a typed value.
- Calendar expansion uses effective-start-inclusive windows, selects duplicate identities
  by SEQUENCE, DTSTAMP, LAST-MODIFIED, then canonical content, and materializes coherent
  effective events for `RANGE=THISANDFUTURE` while retaining the original series slot.
- `TimeZoneGenerator` now models transition eras over a configurable 1970–2100 default
  horizon: superseded rules are UTC-bounded, irregular transitions use exact RDATEs, and
  only a complete stable transition cycle verified through the horizon remains unbounded.
  IANA compatibility links such as `US/Eastern` are accepted as generation inputs.

### Fixed
- iTIP replies preserve complete UID, DTSTART, RECURRENCE-ID (including RANGE), SEQUENCE,
  ORGANIZER, and matching ATTENDEE properties. DTSTAMP and the attendee PARTSTAT are
  replaced; RSVP is removed because it is forbidden on a VEVENT REPLY, while every other
  attendee parameter remains. Malformed/duplicate/untyped singleton identity or
  recurrence metadata is rejected. Newly generated CANCEL revisions receive a fresh
  DTSTAMP.
- iTIP construction/validation now rejects an empty PUBLISH, rejects METHOD calendars
  with no VEVENT, requires exactly one requesting ATTENDEE for REFRESH without incorrectly
  requiring ORGANIZER, and keeps STATUS optional for CANCEL as required by METHOD:CANCEL.
  RFC 5546 table checks no longer require optional SUMMARY/SEQUENCE/ORGANIZER fields and
  now enforce REPLY/COUNTER/DECLINECOUNTER attendee cardinality plus ADD/DECLINECOUNTER
  recurrence/revision metadata. Duplicate or multi-valued METHOD and required singleton
  properties are rejected, as are multi-valued ATTENDEE properties where the method
  imposes attendee cardinality.
- Covered iTIP transactions now validate DTSTAMP as one UTC DATE-TIME without TZID,
  validate SEQUENCE as one non-negative INTEGER, reject VTODO-only ATTENDEE PARTSTAT
  values and REPLY RSVP, and constrain optional CANCEL STATUS to singleton CANCELLED.
  REQUEST/CANCEL construction rejects ambiguous source sequence metadata; CANCEL guards
  its increment against RFC INTEGER overflow.
- VEVENT builder APIs reject negative SEQUENCE and VTODO-only ATTENDEE PARTSTAT values;
  organizer SENT-BY input is normalized as a calendar address.
- Duplicate master/override revisions no longer depend on document order; moved-out
  overrides are no longer returned merely because their original slot is in the window,
  while moved-in and orphan overrides use their effective starts consistently.
- Revision comparison validates only the metadata tier needed to decide: SEQUENCE first,
  then UTC DTSTAMP, then UTC LAST-MODIFIED. Ambiguous or non-RFC values at a required tier
  fail closed, while malformed lower-priority metadata cannot overturn a decisive tier.
- Range expansion now handles moved/resized/property-modified/cancelled tails, multiple
  ranges, later single-instance precedence, all-day values, cross-zone moves, and DST-fold
  durations without making `Occurrence::$start` disagree with the effective event. Sparse
  singles overlay the active range state, inherit absent timing/properties/children, and
  can restore one slot in a cancelled tail; supplied children replace inherited children.
  Only the actual range onset retains the complete source `RECURRENCE-ID`, including
  `RANGE=THISANDFUTURE` and IANA/X parameters; synthetic slots omit range/slot metadata.
- RANGE movement and DATE/floating DTEND-derived duration now propagate fixed wall-clock
  differences rather than PHP month/year intervals, avoiding drift across unequal months.
  Sparse singles over an ordinary master are materialized coherently with inherited state,
  and sparse orphans synthesize DTSTART from RECURRENCE-ID.
- Recurrence slot identity now compares DATE/floating values by lexical wall fields and
  UTC/zoned values by instant. Unresolved custom-TZID arithmetic, rule-generated gap candidates,
  unresolved range inputs, misaligned range anchors, and malformed/duplicate
  temporal properties fail closed. Valid range deltas that newly land in a DST gap retain
  the intended wall literal and RFC pre-transition instant.
- Lenient parsing no longer drops explicit `TZID`, `VALUE`, or `ENCODING` parameters in
  contradictory or unmodelled input; strict parsing rejects mismatched component ends,
  impossible dates, UTC date-times carrying `TZID`, and DATE values carrying `TZID`.
- Duplicate parameter names are rejected strictly. Lenient parsing combines their logical
  values into an ambiguous raw parameter; conflicting TZID/VALUE/ENCODING keeps the
  property value raw, and duplicate RANGE cannot silently become THISANDFUTURE.
- Comma-multivalued controlling TZID/VALUE/ENCODING parameters follow the same
  fail-strict/preserve-raw policy. REQUEST-STATUS remains structured raw data so its
  semicolon-delimited fields survive re-export rather than receiving TEXT escaping.
- Enumerated RFC tokens are interpreted case-insensitively by typed METHOD, STATUS,
  ACTION, ROLE, PARTSTAT, CUTYPE, TRANSP, and CLASS accessors.
- RFC INTEGER, RRULE COUNT/INTERVAL, duration components/totals, BY-part ranges/zero
  restrictions, and BYDAY ordinals are now bounds-checked without PHP integer overflow.
  Invalid programmatic recurrence construction throws `InvalidValueException`.
- RFC value validation now enforces positive PERIODs, compatible period TZIDs, and bounded
  UTC-OFFSET components; valid zero durations such as `PT0S` are accepted.
- Zoned PERIOD values now derive `TZID` during serialization and timezone discovery;
  parsed GEO values retain their lexical precision during re-export, including an explicit
  `VALUE=FLOAT` form.
- Invalid TEXT escapes remain raw in lenient mode and fail strict mode instead of being
  silently altered. Malformed optional attendee/organizer URI and calendar-address
  parameters remain available raw while typed access returns `null`/valid list entries;
  attendee RSVP is now correctly tri-state.
- Unsafe mixed/duplicate/multi-valued singleton recurrence properties and
  DTSTART/UNTIL/RDATE/EXDATE/RECURRENCE-ID value-type mismatches are rejected during
  expansion. Event-level and UID-less calendar expansion validate DTSTART cardinality,
  and recurrence properties without a typed DTSTART are rejected rather than silently
  ignored.
- Recurrence-set properties on detached RECURRENCE-ID components, UID-less recurrence IDs,
  and RFC-undefined unsynchronized DTSTART/RRULE pairs are explicitly rejected rather than
  ignored or delegated to dependency-specific behavior.
- Default recurrence-set expansion rejects mixed floating/instant RDATE or EXDATE forms.
  Embedded VTIMEZONE definitions now resolve custom or IANA-named TZIDs authoritatively;
  missing, ambiguous, malformed, discontinuous, or recurrence-unsupported definitions
  fail closed when used.
- PERIOD-valued RDATEs now participate in the recurrence set and materialize a coherent
  occurrence-specific DTSTART plus DTEND/DURATION; EXDATE, overrides, windows, and custom
  timezone duration arithmetic apply to their original slots. Sparse range/single
  overlays retain a period slot's duration until effective timing replaces it.
- RFC duration application now distinguishes nominal days/weeks from exact time
  components across IANA and embedded-zone transitions, preserves pre-gap wall literals,
  and supports direct custom-zone event-end calculation with a calendar resolver.
- Malformed embedded VTIMEZONEs that claim an IANA TZID no longer fall through to PHP's
  native zone data; the malformed authoritative claim fails closed when used.
- Embedded VTIMEZONE definitions are authoritative even when their TZID is an IANA name
  for ordinary occurrences, overrides, exclusions, range propagation, and PERIODs.
  Explicit PERIOD ends are revalidated after resolution and must be later instants than
  their starts.
- Programmatic RRULE UNTIL rejects unrepresentable TZID-bearing values; unknown RRULE
  values reject structural/control injection while preserving escaped semicolons; explicit
  PERIOD endpoints now require compatible UTC/floating/zoned forms.
- Parameter CR/LF is normalized with RFC 6868 `^n`; non-TEXT serialized values,
  calendar addresses, and URIs reject CR/LF content-line injection. REFRESH-INTERVAL now
  always declares `VALUE=DURATION`.
- Zoned RRULE safety checks inspect candidates before `BYSETPOS` through the end of a
  relevant `FREQ` selection interval. They reject a later gap candidate that can change an
  earlier selection despite `UNTIL` or apparently completed `COUNT`, while allowing a
  finite rule completed in an earlier, unaffected interval.
- The same gap guard now catches removal of a later invalid candidate that would promote
  an earlier BYSETPOS result into the window, including a window ending exactly at DTSTART.
- DATE-valued recurrence masters and detached/range overrides reject sub-day DURATION
  sources instead of materializing a truncated end equal to the occurrence date.
- DURATION-derived ends retain an otherwise-unrepresentable second-fold instant by using
  UTC; unsafe cross-zone fold materialization is rejected instead of changing the instant.

### Limitations
- RRULEs containing unsupported parts are preserved but rejected by the default expander
  rather than being partially or silently expanded.
- RFC-valid `BYSECOND=60` rules are preserved but explicitly rejected by the default
  expander because its underlying date-time stack cannot represent leap seconds safely.
- The bundled `rlanvin/php-rrule` engine stops after 28 consecutive empty YEARLY
  intervals (with corresponding limits at other frequencies). This shortcut does not
  cover the complete 400-year Gregorian cycle, so extremely sparse valid rules can
  truncate; callers with those rules need a replacement `RecurrenceExpander` until the
  dependency limitation is resolved.
- Floating recurrence windows use neutral UTC-backed wall-clock coordinates; returned
  values are containers for floating fields, not UTC instants.
- DATE windows use the same neutral coordinate convention. Mixed DATE/floating/instant
  calendar results have deterministic backing-value order, not a universal chronology.
- A zoned RRULE whose candidate set reaches a nonexistent DST-gap local time in a
  selection interval that can affect the requested expansion horizon is rejected.
  Default occurrence expansion and override resolution also reject any unresolved TZID
  instant because window comparison is undefined. Resolvable non-recurring gap values and
  valid propagated range shifts into a gap remain typed and use RFC 5545's pre-transition
  offset.
- Generated VTIMEZONEs cannot predict political changes absent from the installed tz
  database. The default inspection horizon starts in 1970 and ends in 2100; callers can
  configure different bounds.
- `Duration::equals()` remains a context-free seconds comparison for backward
  compatibility and must not be used to infer equal effective ends across DST changes.
- `Event::end()` remains `null` without DTEND/DURATION for backward compatibility;
  `Event::effectiveEnd()` and `Occurrence::$end` expose RFC implicit duration instead.

## [0.4.0] - 2026-06-20

### Added
- Typed `Attendee` and `Organizer` read models — `address()`, `email()`, `commonName()`,
  and (Attendee) `role()`, `participationStatus()`, `userType()`, `rsvp()` / (Organizer)
  `sentBy()`. The underlying `Property` stays reachable via `->property`.

### Changed
- **BREAKING:** `Event::attendees()` now returns `list<Attendee>` (was `list<Property>`)
  and `Event::organizer()` returns `?Organizer` (was `?Property`).

### Fixed
- Property values are now split on commas only for genuinely multi-valued properties
  (CATEGORIES, RESOURCES, EXDATE, RDATE, FREEBUSY). Previously an unescaped comma in a
  single-valued property (e.g. DESCRIPTION) or a comma inside a URI (e.g. a `geo:` value)
  was incorrectly split into multiple values.

### Internal
- PHPStan at `level: max` and Pint added and enforced in CI; real-world parsing corpus
  (Google / Apple / Outlook exports) added to the test suite.

## [0.3.0] - 2026-06-20

Phase 3 — iTIP scheduling (RFC 5546).

### Added
- iTIP scheduling (RFC 5546): a `Method` enum, `ITip` message builders
  (`publish()`, `request()`, `reply()`, `cancel()`), an `ITipValidator` covering the
  package's common per-method constraints, and `SchedulingException`.
- Typed `Calendar::schedulingMethod()`; `CalendarBuilder::method()` now accepts a `Method`
  (or a string).

## [0.2.0] - 2026-06-20

Phase 2 — recurrence and IANA time-zone support.

### Added
- Typed `Recurrence` value object (RRULE) with fluent construction (`Recurrence::weekly()
  ->every(2)->on(Weekday::Monday)`), `parse()`, and serialization; `Frequency`/`Weekday`
  enums and `WeekdayRule`.
- `Event::occurrencesBetween($from, $to)` — recurrence expansion (RRULE + RDATE − EXDATE),
  DST-aware for supported IANA-zone values, behind a swappable `RecurrenceExpander`
  interface (default wraps `rlanvin/php-rrule`; see current limitations).
- `Event::recurrenceRule()`, `recurrenceDates()`, `exceptionDates()`, `isRecurring()`; and
  builder methods `recurrence()`, `addExceptionDate()`, `addRecurrenceDate()`.

- Calendar-level `Calendar::occurrencesBetween()` with `RECURRENCE-ID` override resolution
  (modified and cancelled instances), returning rich `Occurrence` objects via the
  `OccurrenceExpander`; `Event::recurrenceId()`, `Event::isCancelled()`, and builder
  `recurrenceId()`.
- First-class `TimeZone` (VTIMEZONE) and `Observance` (STANDARD/DAYLIGHT) components with
  typed getters; the parser now maps `VTIMEZONE`/`STANDARD`/`DAYLIGHT` to them.
- `TimeZoneGenerator::forIana()` builds a simplified `VTIMEZONE` (STANDARD/DAYLIGHT with
  derived yearly RRULEs) from transitions in its inspection window;
  `Calendar::withTimeZones()` auto-includes one per IANA zone used by events, and
  `Calendar::timeZones()` reads them back.

### Changed
- Parsing an `RRULE` now yields a typed `Recurrence` instead of a `RawValue`.

### Dependencies
- Added `rlanvin/php-rrule` `^2.6`.

### Deferred
- Resolving UTC offsets from *custom* (non-IANA) `VTIMEZONE` definitions for instant math;
  IANA-backed arithmetic uses PHP's zone database, subject to the documented recurrence
  and generated-VTIMEZONE limitations.

## [0.1.0] - 2026-06-20

Initial release — Phase 1 core (RFC 5545 + RFC 7986).

### Added
- Immutable, strongly-typed object model: components (`Calendar`, `Event`, `Alarm`,
  `GenericComponent`), `Property`/`PropertyBag`, typed parameters, and value types
  (`DateTimeValue`, `Duration`, `Period`, `UtcOffset`, `CalAddress`, `GeoValue`, scalars).
- Fluent builders (`Event::build()`, `Calendar::build()`, `Alarm::build()`) producing
  immutable components; `toBuilder()` for immutable edits.
- `IcsSerializer` — RFC 5545 output with CRLF, 75-octet UTF-8-safe folding, TEXT escaping,
  RFC 6868 parameter encoding, derived `TZID`/`VALUE`/`ENCODING` parameters, and an
  optional strict mode.
- `Parser` — lenient (default) and strict parsing with Level-1 semantic preservation;
  unmodelled properties/components retained where supported and canonically re-emitted.

### Known limitations
- `RRULE` is preserved verbatim but not expanded (phase 2).
- No DST-aware time-zone arithmetic yet (phase 2).
- Round-trip is Level-1 semantic preservation for supported input, not byte-identical or
  arbitrary-malformed-input fidelity.

[Unreleased]: https://github.com/erenav/icalendar/compare/0.4.0...HEAD
[0.4.0]: https://github.com/erenav/icalendar/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/erenav/icalendar/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/erenav/icalendar/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/erenav/icalendar/releases/tag/0.1.0
