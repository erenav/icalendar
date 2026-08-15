<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Parser\Parser;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Property\PropertyBag;
use Erenav\ICalendar\Recurrence\Frequency;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\Recurrence\UnsupportedRecurrenceException;
use Erenav\ICalendar\ValueType\DateTimeValue;
use PHPUnit\Framework\TestCase;

final class OccurrenceExpansionTest extends TestCase
{
    private function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /** @param list<DateTimeImmutable> $occurrences */
    private function dates(array $occurrences): array
    {
        return array_map(static fn (DateTimeImmutable $d): string => $d->format('Ymd'), $occurrences);
    }

    public function test_weekly_with_count(): void
    {
        $event = Event::build()
            ->uid('1')
            ->starts(DateTimeValue::utc($this->utc('2026-07-01 10:00:00')))
            ->recurrence(Recurrence::weekly()->times(3))
            ->get();

        $occurrences = $event->occurrencesBetween($this->utc('2026-01-01 00:00:00'), $this->utc('2026-12-31 23:59:59'));
        $this->assertSame(['20260701', '20260708', '20260715'], $this->dates($occurrences));
    }

    public function test_daily_until(): void
    {
        $event = Event::build()
            ->uid('1')
            ->starts(DateTimeValue::utc($this->utc('2026-07-01 10:00:00')))
            ->recurrence(Recurrence::daily()->until($this->utc('2026-07-05 10:00:00')))
            ->get();

        $occurrences = $event->occurrencesBetween($this->utc('2026-07-01 00:00:00'), $this->utc('2026-08-01 00:00:00'));
        $this->assertCount(5, $occurrences);
    }

    public function test_exception_dates_are_removed(): void
    {
        $event = Event::build()
            ->uid('1')
            ->starts(DateTimeValue::utc($this->utc('2026-07-01 10:00:00')))
            ->recurrence(Recurrence::daily()->times(5))
            ->addExceptionDate(DateTimeValue::utc($this->utc('2026-07-03 10:00:00')))
            ->get();

        $occurrences = $event->occurrencesBetween($this->utc('2026-07-01 00:00:00'), $this->utc('2026-08-01 00:00:00'));
        $this->assertSame(['20260701', '20260702', '20260704', '20260705'], $this->dates($occurrences));
    }

    public function test_exception_date_can_exclude_dtstart_without_an_rrule(): void
    {
        $start = DateTimeValue::utc($this->utc('2026-07-01 10:00:00'));
        $event = Event::build()->uid('exdate-only')->starts($start)->addExceptionDate($start)->get();

        $this->assertSame([], $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-02')));
    }

    public function test_window_narrows_results(): void
    {
        $event = Event::build()
            ->uid('1')
            ->starts(DateTimeValue::utc($this->utc('2026-07-01 10:00:00')))
            ->recurrence(Recurrence::daily()->times(10))
            ->get();

        $occurrences = $event->occurrencesBetween($this->utc('2026-07-03 00:00:00'), $this->utc('2026-07-05 23:59:59'));
        $this->assertSame(['20260703', '20260704', '20260705'], $this->dates($occurrences));
    }

    public function test_non_recurring_event_yields_single_occurrence_in_window(): void
    {
        $event = Event::build()
            ->uid('1')
            ->starts(DateTimeValue::utc($this->utc('2026-07-01 10:00:00')))
            ->get();

        $this->assertCount(1, $event->occurrencesBetween($this->utc('2026-07-01 00:00:00'), $this->utc('2026-07-31 00:00:00')));
        $this->assertCount(0, $event->occurrencesBetween($this->utc('2026-08-01 00:00:00'), $this->utc('2026-08-31 00:00:00')));
    }

    public function test_calendar_occurrences_expose_rfc_implicit_ends(): void
    {
        $date = Event::build()
            ->uid('implicit-date')
            ->starts(DateTimeValue::date($this->utc('2026-07-01')))
            ->get();
        $dateTime = Event::build()
            ->uid('implicit-date-time')
            ->starts(DateTimeValue::utc($this->utc('2026-07-01 10:00:00')))
            ->get();
        $calendar = Calendar::build()->add($date, $dateTime)->get();

        $occurrences = $calendar->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-02'));
        $this->assertSame('20260702', $occurrences[0]->end?->format('Ymd'));
        $this->assertSame($occurrences[1]->start->getTimestamp(), $occurrences[1]->end?->getTimestamp());
    }

    public function test_rdate_only_event(): void
    {
        $event = Event::build()
            ->uid('1')
            ->starts(DateTimeValue::utc($this->utc('2026-07-01 10:00:00')))
            ->addRecurrenceDate(DateTimeValue::utc($this->utc('2026-07-10 10:00:00')))
            ->get();

        $occurrences = $event->occurrencesBetween($this->utc('2026-07-01 00:00:00'), $this->utc('2026-07-31 00:00:00'));
        $this->assertSame(['20260701', '20260710'], $this->dates($occurrences));
    }

    public function test_expansion_keeps_wall_time_across_dst(): void
    {
        $newYork = new DateTimeZone('America/New_York');

        // 2026-03-08 is the US spring-forward day; a weekly 09:30 event should stay 09:30 local.
        $event = Event::build()
            ->uid('1')
            ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-03-01 09:30:00', $newYork), 'America/New_York'))
            ->recurrence(Recurrence::weekly()->times(3))
            ->get();

        $occurrences = $event->occurrencesBetween(
            new DateTimeImmutable('2026-03-01 00:00:00', $newYork),
            new DateTimeImmutable('2026-04-01 00:00:00', $newYork),
        );

        $this->assertCount(3, $occurrences);
        foreach ($occurrences as $occurrence) {
            $this->assertSame('09:30', $occurrence->setTimezone($newYork)->format('H:i'));
        }
    }

    public function test_unknown_rule_parts_are_preserved_but_not_unsafely_expanded(): void
    {
        $event = Event::build()->starts($this->utc('2026-07-01'))->recurrence(Recurrence::parse('FREQ=DAILY;X-SKIP=HOLIDAYS'))->get();
        $this->expectException(UnsupportedRecurrenceException::class);
        $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-31'));
    }

    public function test_rfc_valid_leap_second_rule_is_preserved_but_not_misexpanded(): void
    {
        $event = Event::build()
            ->starts(DateTimeValue::utc($this->utc('2026-07-01 10:00:00')))
            ->recurrence(Recurrence::parse('FREQ=MINUTELY;COUNT=2;BYSECOND=60'))
            ->get();

        $this->assertSame('FREQ=MINUTELY;COUNT=2;BYSECOND=60', $event->recurrenceRule()?->toString());
        $this->expectException(UnsupportedRecurrenceException::class);
        $event->occurrencesBetween($this->utc('2026-07-01 10:00:00'), $this->utc('2026-07-01 10:05:00'));
    }

    public function test_period_rdate_is_exposed_and_expanded_with_its_own_end(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:p\r\nDTSTART:20260701T100000Z\r\nRDATE;VALUE=PERIOD:20260702T100000Z/PT2H\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $event = $calendar->events()[0];
        $this->assertSame('20260702T100000Z/PT2H', $event->recurrenceDatePeriods()[0]->toString());

        $starts = $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
        $this->assertSame(['20260701T100000Z', '20260702T100000Z'], array_map(
            static fn (DateTimeImmutable $date): string => $date->format('Ymd\THis\Z'),
            $starts,
        ));

        $occurrences = $calendar->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
        $this->assertCount(2, $occurrences);
        $period = $occurrences[1];
        $this->assertSame('20260702T100000Z', $period->event->start()?->toString());
        $this->assertSame('20260702T120000Z', $period->event->effectiveEnd()?->toString());
        $this->assertSame('20260702T120000Z', $period->end?->format('Ymd\THis\Z'));
    }

    public function test_conflicting_period_rdates_for_one_slot_fail_closed(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:p\r\nDTSTART:20260701T100000Z\r\nRDATE;VALUE=PERIOD:20260702T100000Z/PT1H,20260702T100000Z/PT2H\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $this->expectException(UnsupportedRecurrenceException::class);
        $calendar->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
    }

    public function test_explicit_period_end_and_exdate_participate_in_the_recurrence_set(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:period-explicit\r\nDTSTART:20260701T100000Z\r\nRDATE;VALUE=PERIOD:20260702T100000Z/20260702T123000Z,20260703T100000Z/20260703T130000Z\r\nEXDATE:20260703T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $occurrences = $calendar->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-04'));

        $this->assertCount(2, $occurrences);
        $this->assertSame('20260702T123000Z', $occurrences[1]->event->effectiveEnd()?->toString());
        $this->assertSame('20260702T123000Z', $occurrences[1]->end?->format('Ymd\THis\Z'));
    }

    public function test_sparse_range_and_single_override_preserve_period_slot_duration(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:period-range\r\nDTSTART:20260701T100000Z\r\nDURATION:PT1H\r\nRRULE:FREQ=DAILY;COUNT=4\r\nRDATE;VALUE=PERIOD:20260703T100000Z/PT3H\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:period-range\r\nRECURRENCE-ID;RANGE=THISANDFUTURE:20260702T100000Z\r\nDTSTART:20260702T120000Z\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:period-range\r\nRECURRENCE-ID:20260703T100000Z\r\nSUMMARY:One-off label\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $occurrences = $calendar->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-05'));

        $this->assertCount(4, $occurrences);
        $this->assertSame('20260703T120000Z', $occurrences[2]->event->start()?->toString());
        $this->assertSame('20260703T150000Z', $occurrences[2]->event->effectiveEnd()?->toString());
        $this->assertSame('One-off label', $occurrences[2]->event->summary());
        $this->assertSame('20260704T130000Z', $occurrences[3]->end?->format('Ymd\THis\Z'));
    }

    public function test_detached_components_cannot_redefine_a_recurrence_set_during_direct_expansion(): void
    {
        foreach (['RRULE:FREQ=DAILY;COUNT=2', 'RDATE:20260703T100000Z', 'EXDATE:20260702T100000Z'] as $recurrenceLine) {
            $event = Parser::lenient()->parseCalendar(
                "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:detached-set\r\nRECURRENCE-ID:20260702T100000Z\r\nDTSTART:20260702T100000Z\r\n{$recurrenceLine}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            )->events()[0];

            try {
                $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-05'));
                $this->fail('Expected recurrence-set data on a detached component to be rejected.');
            } catch (UnsupportedRecurrenceException $exception) {
                $this->assertStringContainsString('detached', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_unsupported_recurrence_properties_are_not_hidden_by_missing_dtstart(): void
    {
        $documents = [
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nRDATE;VALUE=PERIOD:20260702T100000Z/PT2H\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nRRULE:FREQ=DAILY;BYHOUR=99\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        ];

        foreach ($documents as $document) {
            $event = Parser::lenient()->parseCalendar($document)->events()[0];
            try {
                $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
                $this->fail('Expected unsupported recurrence data to remain explicit without DTSTART.');
            } catch (UnsupportedRecurrenceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_typed_recurrence_properties_are_not_silently_ignored_without_dtstart(): void
    {
        $date = DateTimeValue::utc($this->utc('2026-07-02 10:00'));
        $events = [
            Event::build()->recurrence(Recurrence::daily()->times(2))->get(),
            Event::build()->recurrence(Recurrence::parse('FREQ=DAILY;X-SKIP=HOLIDAYS'))->get(),
            Event::build()->addRecurrenceDate($date)->get(),
            Event::build()->addExceptionDate($date)->get(),
        ];

        foreach ($events as $event) {
            try {
                $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
                $this->fail('Expected recurrence data without DTSTART to be rejected.');
            } catch (UnsupportedRecurrenceException $exception) {
                $this->assertStringContainsString('DTSTART', $exception->getMessage());
            }
        }
    }

    public function test_leniently_preserved_invalid_rrule_is_not_silently_expanded_as_one_event(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:bad\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;BYHOUR=99\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->expectException(UnsupportedRecurrenceException::class);
        $calendar->events()[0]->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
    }

    public function test_leniently_preserved_invalid_rdate_is_not_silently_ignored(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:bad-date\r\nDTSTART:20260701T100000Z\r\nRDATE:not-a-date\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->expectException(UnsupportedRecurrenceException::class);
        $calendar->events()[0]->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
    }

    public function test_mixed_valid_and_invalid_recurrence_dates_are_not_partially_expanded(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:mixed\r\nDTSTART:20260701T100000Z\r\nRDATE:20260702T100000Z\r\nRDATE:not-a-date\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->expectException(UnsupportedRecurrenceException::class);
        $calendar->events()[0]->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
    }

    public function test_malformed_exception_date_is_not_silently_ignored(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:bad-exdate\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEXDATE:not-a-date\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->expectException(UnsupportedRecurrenceException::class);
        $calendar->events()[0]->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
    }

    public function test_duplicate_rrule_properties_are_not_partially_expanded(): void
    {
        $calendar = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:two-rules\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nRRULE:FREQ=WEEKLY;COUNT=2\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->expectException(UnsupportedRecurrenceException::class);
        $calendar->events()[0]->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-31'));
    }

    public function test_one_multivalued_rrule_property_is_not_partially_expanded(): void
    {
        $event = new Event(new PropertyBag(
            new Property('DTSTART', DateTimeValue::utc($this->utc('2026-07-01 10:00'))),
            new Property('RRULE', [Recurrence::daily()->times(2), Recurrence::weekly()->times(2)]),
        ));

        $this->expectException(UnsupportedRecurrenceException::class);
        $this->expectExceptionMessage('multi-valued');
        $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-31'));
    }

    public function test_event_level_expansion_rejects_ambiguous_or_untyped_dtstart(): void
    {
        $first = DateTimeValue::utc($this->utc('2026-07-01 10:00'));
        $second = DateTimeValue::utc($this->utc('2026-07-02 10:00'));
        $duplicate = Event::build()->starts($first)->property('DTSTART', $second)->get();
        $multiValued = new Event(new PropertyBag(new Property('DTSTART', [$first, $second])));
        $untyped = Parser::lenient()->parseCalendar(
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:not-a-date\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        )->events()[0];

        foreach ([$duplicate, $multiValued, $untyped] as $event) {
            try {
                $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-31'));
                $this->fail('Expected ambiguous or untyped DTSTART to be rejected.');
            } catch (UnsupportedRecurrenceException $exception) {
                $this->assertStringContainsString('DTSTART', $exception->getMessage());
            }
        }

        $this->assertSame([], Event::build()->get()->occurrencesBetween(
            $this->utc('2026-07-01'),
            $this->utc('2026-07-31'),
        ));
    }

    public function test_until_form_must_match_dtstart_for_safe_expansion(): void
    {
        $event = Event::build()
            ->starts(DateTimeValue::floating($this->utc('2026-07-01 10:00')))
            ->recurrence(Recurrence::daily()->until(DateTimeValue::utc($this->utc('2026-07-03 10:00'))))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
    }

    public function test_date_only_start_rejects_time_based_rule_parts(): void
    {
        $event = Event::build()
            ->starts(DateTimeValue::date($this->utc('2026-07-01')))
            ->recurrence(new Recurrence(Frequency::Daily, byHour: [10]))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
    }

    public function test_unsynchronized_dtstart_and_rrule_are_rejected_as_rfc_undefined(): void
    {
        $event = Event::build()
            ->starts(DateTimeValue::utc($this->utc('2026-07-01 10:00:00')))
            ->recurrence(Recurrence::parse('FREQ=WEEKLY;COUNT=2;BYDAY=MO'))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $this->expectExceptionMessage('not synchronized');
        $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-31'));
    }

    public function test_rdate_value_type_must_match_dtstart(): void
    {
        $event = Event::build()
            ->starts(DateTimeValue::date($this->utc('2026-07-01')))
            ->addRecurrenceDate(DateTimeValue::utc($this->utc('2026-07-02 10:00')))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
    }

    public function test_recurrence_set_rejects_unresolved_zones_and_mixed_wall_instant_forms(): void
    {
        $utcStart = DateTimeValue::utc($this->utc('2026-07-01 10:00'));
        $floatingStart = DateTimeValue::floating($this->utc('2026-07-01 10:00'));
        $custom = DateTimeValue::zoned($this->utc('2026-07-02 10:00'), 'Provider/Custom');
        $floatingDate = DateTimeValue::floating($this->utc('2026-07-02 10:00'));
        $utcDate = DateTimeValue::utc($this->utc('2026-07-02 10:00'));

        $events = [
            Event::build()->starts($custom)->get(),
            Event::build()->starts($utcStart)->addRecurrenceDate($custom)->get(),
            Event::build()->starts($utcStart)->recurrence(Recurrence::daily()->times(2))->addExceptionDate($custom)->get(),
            Event::build()->starts($floatingStart)->addRecurrenceDate($utcDate)->get(),
            Event::build()->starts($utcStart)->addRecurrenceDate($floatingDate)->get(),
            Event::build()->starts($utcStart)->recurrence(Recurrence::daily()->times(2))->addExceptionDate($floatingDate)->get(),
        ];

        foreach ($events as $event) {
            try {
                $event->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
                $this->fail('Expected unresolved or mixed-coordinate recurrence timing to be rejected.');
            } catch (UnsupportedRecurrenceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_known_iana_gap_rdate_remains_a_resolvable_explicit_instant(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $gap = DateTimeValue::zoned($this->utc('2026-03-08 02:30'), 'America/New_York');
        $event = Event::build()
            ->starts(DateTimeValue::utc($this->utc('2026-03-07 07:30')))
            ->addRecurrenceDate($gap)
            ->get();

        $occurrences = $event->occurrencesBetween(
            new DateTimeImmutable('2026-03-07', $zone),
            new DateTimeImmutable('2026-03-09', $zone),
        );

        $this->assertSame('20260308T023000', $gap->toString());
        $this->assertSame(['20260307T073000Z', '20260308T073000Z'], array_map(
            static fn (DateTimeImmutable $date): string => $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            $occurrences,
        ));

        $gapStartWithExdate = Event::build()
            ->starts($gap)
            ->addExceptionDate(DateTimeValue::utc($this->utc('2026-03-09 07:30')))
            ->get();
        $this->assertCount(1, $gapStartWithExdate->occurrencesBetween(
            new DateTimeImmutable('2026-03-07', $zone),
            new DateTimeImmutable('2026-03-09', $zone),
        ));
    }

    public function test_rfc_gap_value_is_preserved_for_a_single_event_but_not_unsafely_recurred(): void
    {
        $single = Parser::lenient()->parseCalendar("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;TZID=America/New_York:20260308T023000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")->events()[0];
        $this->assertSame('20260308T023000', $single->start()?->toString());
        $this->assertCount(1, $single->occurrencesBetween(
            new DateTimeImmutable('2026-03-08 00:00', new DateTimeZone('America/New_York')),
            new DateTimeImmutable('2026-03-09 00:00', new DateTimeZone('America/New_York')),
        ));

        $recurring = $single->toBuilder()->recurrence(Recurrence::daily()->times(2))->get();
        $this->expectException(UnsupportedRecurrenceException::class);
        $recurring->occurrencesBetween(
            new DateTimeImmutable('2026-03-08 00:00', new DateTimeZone('America/New_York')),
            new DateTimeImmutable('2026-03-10 00:00', new DateTimeZone('America/New_York')),
        );
    }

    public function test_rule_generated_dst_gap_instance_is_rejected_instead_of_normalized_and_counted(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $event = Event::build()
            ->uid('generated-gap')
            ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-03-07 02:30:00', $zone), 'America/New_York'))
            ->recurrence(Recurrence::daily()->times(3))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $this->expectExceptionMessage('generates the nonexistent local time 20260308T023000');
        $event->occurrencesBetween(
            new DateTimeImmutable('2026-03-01', $zone),
            new DateTimeImmutable('2026-03-20', $zone),
        );
    }

    public function test_gap_candidate_that_changes_bysetpos_selection_is_rejected(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $event = Event::build()
            ->uid('gap-setpos')
            ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-03-07 03:00:00', $zone), 'America/New_York'))
            ->recurrence(new Recurrence(
                Frequency::Daily,
                count: 3,
                byHour: [2, 3],
                bySetPosition: [2],
            ))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $this->expectExceptionMessage('nonexistent local time 20260308T020000');
        $event->occurrencesBetween(
            new DateTimeImmutable('2026-03-01', $zone),
            new DateTimeImmutable('2026-03-20', $zone),
        );
    }

    public function test_gap_candidate_cannot_silently_promote_an_earlier_bysetpos_result_into_the_window(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $event = Event::build()
            ->uid('gap-setpos-promotion')
            ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-03-07 02:00:00', $zone), 'America/New_York'))
            ->recurrence(Recurrence::parse('FREQ=DAILY;BYHOUR=1,2;BYSETPOS=-1'))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $event->occurrencesBetween(
            new DateTimeImmutable('2026-03-08 00:00:00', $zone),
            new DateTimeImmutable('2026-03-08 01:30:00', $zone),
        );
    }

    public function test_bysetpos_gap_interval_is_checked_when_the_window_ends_at_dtstart(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $start = new DateTimeImmutable('2026-03-08 01:00:00', $zone);
        $event = Event::build()
            ->uid('gap-setpos-start-boundary')
            ->starts(DateTimeValue::zoned($start, 'America/New_York'))
            ->recurrence(Recurrence::parse('FREQ=DAILY;COUNT=2;BYHOUR=1,2;BYSETPOS=-2'))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $event->occurrencesBetween($start, $start);
    }

    public function test_gap_candidate_after_until_can_change_an_earlier_bysetpos_selection(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $event = Event::build()
            ->uid('gap-setpos-until')
            ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-03-07 01:00:00', $zone), 'America/New_York'))
            ->recurrence(new Recurrence(
                Frequency::Daily,
                until: DateTimeValue::utc(new DateTimeImmutable('2026-03-08 06:30:00', new DateTimeZone('UTC'))),
                byHour: [1, 2],
                bySetPosition: [-2],
            ))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $this->expectExceptionMessage('nonexistent local time 20260308T020000');
        $event->occurrencesBetween(
            new DateTimeImmutable('2026-03-01', $zone),
            new DateTimeImmutable('2026-03-20', $zone),
        );
    }

    public function test_count_completed_before_gap_is_not_safe_when_bysetpos_depends_on_that_gap(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $event = Event::build()
            ->uid('gap-setpos-count')
            ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-03-07 01:00:00', $zone), 'America/New_York'))
            ->recurrence(new Recurrence(
                Frequency::Daily,
                count: 2,
                byHour: [1, 2],
                bySetPosition: [-2],
            ))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $this->expectExceptionMessage('nonexistent local time 20260308T020000');
        $event->occurrencesBetween(
            new DateTimeImmutable('2026-03-01', $zone),
            new DateTimeImmutable('2026-03-20', $zone),
        );
    }

    public function test_finite_rule_that_ends_before_a_later_gap_remains_expandable(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $event = Event::build()
            ->uid('before-gap')
            ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-03-01 02:30:00', $zone), 'America/New_York'))
            ->recurrence(Recurrence::daily()->times(2))
            ->get();

        $occurrences = $event->occurrencesBetween(
            new DateTimeImmutable('2026-03-01', $zone),
            new DateTimeImmutable('2026-03-20', $zone),
        );

        $this->assertCount(2, $occurrences);
        $this->assertSame(['2026-03-01 02:30', '2026-03-02 02:30'], array_map(
            static fn (DateTimeImmutable $occurrence): string => $occurrence->setTimezone($zone)->format('Y-m-d H:i'),
            $occurrences,
        ));
    }
}
