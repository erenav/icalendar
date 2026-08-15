<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Erenav\ICalendar\Component\Alarm;
use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Parameter\ParameterBag;
use Erenav\ICalendar\Parameter\Range;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Parser\Parser;
use Erenav\ICalendar\Property\AlarmAction;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Recurrence\Occurrence;
use Erenav\ICalendar\Recurrence\Recurrence;
use Erenav\ICalendar\Recurrence\UnsupportedRecurrenceException;
use Erenav\ICalendar\Serializer\IcsSerializer;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use PHPUnit\Framework\TestCase;

final class OverrideResolutionTest extends TestCase
{
    private function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private function dtv(string $time): DateTimeValue
    {
        return DateTimeValue::utc($this->utc($time));
    }

    private function dailySeriesWithOverrides(): Calendar
    {
        $master = Event::build()
            ->uid('series@test')
            ->summary('Daily standup')
            ->starts($this->dtv('2026-07-01 10:00:00'))
            ->recurrence(Recurrence::daily()->times(5))
            ->get();

        $moved = Event::build()
            ->uid('series@test')
            ->recurrenceId($this->dtv('2026-07-03 10:00:00'))
            ->starts($this->dtv('2026-07-03 14:00:00'))
            ->summary('Standup (moved to afternoon)')
            ->get();

        $cancelled = Event::build()
            ->uid('series@test')
            ->recurrenceId($this->dtv('2026-07-04 10:00:00'))
            ->starts($this->dtv('2026-07-04 10:00:00'))
            ->status(EventStatus::Cancelled)
            ->get();

        return Calendar::build()->prodId('-//Test//EN')->add($master)->add($moved)->add($cancelled)->get();
    }

    /** @param list<Occurrence> $occurrences */
    private function summarise(array $occurrences): array
    {
        return array_map(
            static fn (Occurrence $o): string => $o->start->format('Y-m-d H:i').' | '.$o->event->summary().($o->isOverride ? ' *' : ''),
            $occurrences,
        );
    }

    public function test_overrides_modify_and_cancel_instances(): void
    {
        $occurrences = $this->dailySeriesWithOverrides()
            ->occurrencesBetween($this->utc('2026-07-01 00:00:00'), $this->utc('2026-07-31 00:00:00'));

        $this->assertSame([
            '2026-07-01 10:00 | Daily standup',
            '2026-07-02 10:00 | Daily standup',
            '2026-07-03 14:00 | Standup (moved to afternoon) *', // moved
            '2026-07-05 10:00 | Daily standup',                  // 07-04 cancelled, gone
        ], $this->summarise($occurrences));
    }

    public function test_override_occurrence_metadata(): void
    {
        $occurrences = $this->dailySeriesWithOverrides()
            ->occurrencesBetween($this->utc('2026-07-01 00:00:00'), $this->utc('2026-07-31 00:00:00'));

        $moved = $occurrences[2];
        $this->assertTrue($moved->isOverride);
        $this->assertSame('2026-07-03 14:00', $moved->start->format('Y-m-d H:i'));
        $this->assertSame('2026-07-03 10:00', $moved->recurrenceId->format('Y-m-d H:i')); // original slot
        $this->assertFalse($occurrences[0]->isOverride);
    }

    public function test_results_are_sorted_by_start(): void
    {
        $occurrences = $this->dailySeriesWithOverrides()
            ->occurrencesBetween($this->utc('2026-07-01 00:00:00'), $this->utc('2026-07-31 00:00:00'));

        $previous = null;
        foreach ($occurrences as $occurrence) {
            if ($previous !== null) {
                $this->assertGreaterThanOrEqual($previous, $occurrence->start);
            }
            $previous = $occurrence->start;
        }
    }

    public function test_overrides_survive_serialize_and_parse(): void
    {
        $ics = (new IcsSerializer)->serialize($this->dailySeriesWithOverrides());
        $this->assertStringContainsString('RECURRENCE-ID:20260703T100000Z', $ics);

        $calendar = Parser::lenient()->parseCalendar($ics);
        $occurrences = $calendar->occurrencesBetween($this->utc('2026-07-01 00:00:00'), $this->utc('2026-07-31 00:00:00'));

        $this->assertCount(4, $occurrences);
        $this->assertSame('Standup (moved to afternoon)', $occurrences[2]->event->summary());
    }

    public function test_event_recurrence_id_getter(): void
    {
        $override = Event::build()->uid('x')->recurrenceId($this->dtv('2026-07-03 10:00:00'))->get();
        $this->assertSame('20260703T100000Z', $override->recurrenceId()?->toString());
    }

    public function test_this_and_future_moves_resizes_and_changes_properties_coherently(): void
    {
        $master = Event::build()->uid('range')->summary('Old')->starts($this->dtv('2026-07-01 10:00'))->ends($this->dtv('2026-07-01 11:00'))->recurrence(Recurrence::daily()->times(5))->get();
        $range = Event::build()->uid('range')->summary('New')->description('Range description')->recurrenceId($this->dtv('2026-07-03 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-03 12:00'))->ends($this->dtv('2026-07-03 14:00'))->get();
        $single = Event::build()->uid('range')->summary('One')->recurrenceId($this->dtv('2026-07-04 10:00'))->starts($this->dtv('2026-07-04 09:00'))->get();
        $calendar = Calendar::build()->add($master, $range, $single)->get();
        $occurrences = $calendar->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));

        $this->assertSame(['10:00', '10:00', '12:00', '09:00', '12:00'], array_map(static fn (Occurrence $o): string => $o->start->format('H:i'), $occurrences));
        $this->assertSame('New', $occurrences[4]->event->summary());
        $this->assertSame('12:00', $occurrences[4]->event->start()?->dateTime->format('H:i'));
        $this->assertSame('14:00', $occurrences[4]->event->end()?->dateTime->format('H:i'));
        $this->assertSame('10:00', $occurrences[4]->recurrenceId->format('H:i'));
        $this->assertNull($occurrences[4]->event->recurrenceRule());
        $this->assertFalse($occurrences[4]->event->isRecurring());
        $this->assertSame('09:00', $occurrences[3]->event->start()?->dateTime->format('H:i'));
        $this->assertSame('11:00', $occurrences[3]->event->end()?->dateTime->format('H:i'));
        $this->assertSame('Range description', $occurrences[3]->event->description());
    }

    public function test_range_cancellation_and_later_range_supersession(): void
    {
        $master = Event::build()->uid('ranges')->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(6))->get();
        $cancel = Event::build()->uid('ranges')->recurrenceId($this->dtv('2026-07-03 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-03 10:00'))->status(EventStatus::Cancelled)->get();
        $resume = Event::build()->uid('ranges')->recurrenceId($this->dtv('2026-07-05 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-05 11:00'))->get();
        $occurrences = Calendar::build()->add($master, $cancel, $resume)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
        $this->assertSame(['01 10:00', '02 10:00', '05 11:00', '06 11:00'], array_map(static fn (Occurrence $o): string => $o->start->format('d H:i'), $occurrences));
    }

    public function test_later_range_retains_unreplaced_properties_from_earlier_range(): void
    {
        $master = Event::build()->uid('chain')->summary('Master')->starts($this->dtv('2026-07-01 10:00'))->ends($this->dtv('2026-07-01 11:00'))->recurrence(Recurrence::daily()->times(5))->get();
        $first = Event::build()->uid('chain')->summary('First range')->recurrenceId($this->dtv('2026-07-02 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-02 11:00'))->ends($this->dtv('2026-07-02 13:00'))->get();
        $second = Event::build()->uid('chain')->recurrenceId($this->dtv('2026-07-04 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-04 12:00'))->get();
        $occurrences = Calendar::build()->add($master, $first, $second)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
        $this->assertSame('First range', $occurrences[4]->event->summary());
        $this->assertSame('12:00', $occurrences[4]->start->format('H:i'));
        $this->assertSame('14:00', $occurrences[4]->event->end()?->dateTime->format('H:i'));
    }

    public function test_effective_start_window_moves_in_and_out_inclusively(): void
    {
        $master = Event::build()->uid('window')->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(3))->get();
        $out = Event::build()->uid('window')->recurrenceId($this->dtv('2026-07-02 10:00'))->starts($this->dtv('2026-08-01 10:00'))->get();
        $in = Event::build()->uid('window')->recurrenceId($this->dtv('2026-07-03 10:00'))->starts($this->dtv('2026-07-02 23:59'))->get();
        $occurrences = Calendar::build()->add($master, $out, $in)->get()->occurrencesBetween($this->utc('2026-07-02 00:00'), $this->utc('2026-07-02 23:59'));
        $this->assertSame(['2026-07-02 23:59'], array_map(static fn (Occurrence $o): string => $o->start->format('Y-m-d H:i'), $occurrences));
    }

    public function test_newer_revisions_win_independent_of_document_order(): void
    {
        $old = Event::build()->uid('rev')->sequence(1)->timestamp($this->dtv('2026-01-01'))->summary('old')->starts($this->dtv('2026-07-01'))->get();
        $new = Event::build()->uid('rev')->sequence(2)->timestamp($this->dtv('2025-01-01'))->summary('new')->starts($this->dtv('2026-07-02'))->get();
        foreach ([[$old, $new], [$new, $old]] as $events) {
            $occurrences = Calendar::build()->add(...$events)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
            $this->assertSame('new', $occurrences[0]->event->summary());
        }
    }

    public function test_newer_override_revision_wins_and_missing_metadata_has_deterministic_fallback(): void
    {
        $master = Event::build()->uid('override-rev')->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(2))->get();
        $stale = Event::build()->uid('override-rev')->sequence(1)->recurrenceId($this->dtv('2026-07-02 10:00'))->starts($this->dtv('2026-07-02 11:00'))->summary('stale')->get();
        $fresh = Event::build()->uid('override-rev')->sequence(2)->recurrenceId($this->dtv('2026-07-02 10:00'))->starts($this->dtv('2026-07-02 12:00'))->summary('fresh')->get();
        foreach ([[$stale, $fresh], [$fresh, $stale]] as $overrides) {
            $occurrences = Calendar::build()->add($master, ...$overrides)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
            $this->assertSame('fresh', $occurrences[1]->event->summary());
        }

        $a = Event::build()->uid('fallback')->summary('A')->starts($this->dtv('2026-07-01'))->get();
        $b = Event::build()->uid('fallback')->summary('B')->starts($this->dtv('2026-07-01'))->get();
        $chosen = [];
        foreach ([[$a, $b], [$b, $a]] as $masters) {
            $chosen[] = Calendar::build()->add(...$masters)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-02'))[0]->event->summary();
        }
        $this->assertSame([$chosen[0], $chosen[0]], $chosen);
    }

    public function test_revision_ties_use_dtstamp_then_last_modified(): void
    {
        $olderStamp = Event::build()->uid('stamp')->sequence(3)->timestamp($this->dtv('2026-01-01'))->lastModified($this->dtv('2026-06-01'))->summary('older stamp')->starts($this->dtv('2026-07-01'))->get();
        $newerStamp = Event::build()->uid('stamp')->sequence(3)->timestamp($this->dtv('2026-02-01'))->lastModified($this->dtv('2025-01-01'))->summary('newer stamp')->starts($this->dtv('2026-07-01'))->get();
        foreach ([[$olderStamp, $newerStamp], [$newerStamp, $olderStamp]] as $masters) {
            $chosen = Calendar::build()->add(...$masters)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-02'));
            $this->assertSame('newer stamp', $chosen[0]->event->summary());
        }

        $olderModified = Event::build()->uid('modified')->sequence(3)->timestamp($this->dtv('2026-02-01'))->lastModified($this->dtv('2026-03-01'))->summary('older modified')->starts($this->dtv('2026-07-01'))->get();
        $newerModified = Event::build()->uid('modified')->sequence(3)->timestamp($this->dtv('2026-02-01'))->lastModified($this->dtv('2026-04-01'))->summary('newer modified')->starts($this->dtv('2026-07-01'))->get();
        foreach ([[$olderModified, $newerModified], [$newerModified, $olderModified]] as $masters) {
            $chosen = Calendar::build()->add(...$masters)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-02'));
            $this->assertSame('newer modified', $chosen[0]->event->summary());
        }
    }

    public function test_revision_selection_rejects_ambiguous_or_non_rfc_revision_metadata(): void
    {
        $metadataSets = [
            ["SEQUENCE:-1\r\nDTSTAMP:20260101T000000Z", 'SEQUENCE:0'],
            ["SEQUENCE:1\r\nSEQUENCE:2", 'SEQUENCE:0'],
            ['DTSTAMP:20260101T000000', 'DTSTAMP:20260102T000000Z'],
            ["DTSTAMP:20260101T000000Z\r\nLAST-MODIFIED:20260102T000000", 'DTSTAMP:20260101T000000Z'],
        ];

        foreach ($metadataSets as [$leftMetadata, $rightMetadata]) {
            $calendar = Parser::lenient()->parseCalendar(
                "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:invalid-revision\r\n{$leftMetadata}\r\nDTSTART:20260701T100000Z\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:invalid-revision\r\n{$rightMetadata}\r\nDTSTART:20260702T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            );

            try {
                $calendar->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-03'));
                $this->fail('Expected malformed revision metadata to be rejected.');
            } catch (UnsupportedRecurrenceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_decisive_revision_tiers_do_not_consult_malformed_lower_priority_metadata(): void
    {
        $floating = DateTimeValue::floating($this->utc('2026-01-01'));
        $staleSequence = Event::build()->uid('lazy-sequence')->sequence(1)->timestamp($floating)->summary('stale')->starts($this->dtv('2026-07-01'))->get();
        $newSequence = Event::build()->uid('lazy-sequence')->sequence(2)->summary('new')->starts($this->dtv('2026-07-01'))->get();

        $olderStamp = Event::build()->uid('lazy-stamp')->sequence(1)->timestamp($this->dtv('2026-01-01'))->lastModified($floating)->summary('older')->starts($this->dtv('2026-07-01'))->get();
        $newerStamp = Event::build()->uid('lazy-stamp')->sequence(1)->timestamp($this->dtv('2026-02-01'))->summary('newer')->starts($this->dtv('2026-07-01'))->get();

        foreach ([
            [$staleSequence, $newSequence, 'new'],
            [$newSequence, $staleSequence, 'new'],
            [$olderStamp, $newerStamp, 'newer'],
            [$newerStamp, $olderStamp, 'newer'],
        ] as [$first, $second, $expected]) {
            $occurrences = Calendar::build()->add($first, $second)->get()
                ->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-02'));
            $this->assertSame($expected, $occurrences[0]->event->summary());
        }
    }

    public function test_effective_window_boundaries_cover_master_orphan_and_range_results(): void
    {
        $boundary = $this->utc('2026-07-02 12:00');

        $master = Event::build()->uid('boundary-master')->starts($this->dtv('2026-07-02 12:00'))->get();
        $this->assertCount(1, Calendar::build()->add($master)->get()->occurrencesBetween($boundary, $boundary));

        $orphan = Event::build()->uid('boundary-orphan')->recurrenceId($this->dtv('2026-07-01 10:00'))->starts($this->dtv('2026-07-02 12:00'))->get();
        $this->assertCount(1, Calendar::build()->add($orphan)->get()->occurrencesBetween($boundary, $boundary));

        $sparseOrphan = Event::build()->uid('boundary-sparse-orphan')->recurrenceId($this->dtv('2026-07-02 12:00'))->summary('orphan')->get();
        $sparseResults = Calendar::build()->add($sparseOrphan)->get()->occurrencesBetween($boundary, $boundary);
        $this->assertCount(1, $sparseResults);
        $this->assertSame('20260702T120000Z', $sparseResults[0]->event->start()?->toString());
        $this->assertSame('20260702T120000Z', $sparseResults[0]->event->recurrenceId()?->toString());

        $rangeMaster = Event::build()->uid('boundary-range')->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(2))->get();
        $range = Event::build()->uid('boundary-range')->recurrenceId($this->dtv('2026-07-02 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-02 12:00'))->get();
        $results = Calendar::build()->add($rangeMaster, $range)->get()->occurrencesBetween($boundary, $boundary);
        $this->assertCount(1, $results);
        $this->assertSame('12:00', $results[0]->start->format('H:i'));
    }

    public function test_range_parameter_round_trips_and_to_builder_preserves_it(): void
    {
        $event = Event::build()->uid('x')->recurrenceId($this->dtv('2026-07-03 10:00'), Range::ThisAndFuture)->get();
        $this->assertSame(Range::ThisAndFuture, $event->recurrenceRange());
        $copy = $event->toBuilder()->summary('edit')->get();
        $this->assertSame(Range::ThisAndFuture, $copy->recurrenceRange());
        $parsed = Parser::lenient()->parseCalendar((new IcsSerializer)->serialize(Calendar::build()->add($copy)->get()));
        $this->assertSame(Range::ThisAndFuture, $parsed->events()[0]->recurrenceRange());
    }

    public function test_all_day_range_preserves_date_form(): void
    {
        $master = Event::build()->uid('days')->starts(DateTimeValue::date($this->utc('2026-07-01')))->ends(DateTimeValue::date($this->utc('2026-07-02')))->recurrence(Recurrence::daily()->times(3))->get();
        $range = Event::build()->uid('days')->recurrenceId(DateTimeValue::date($this->utc('2026-07-02')), Range::ThisAndFuture)->starts(DateTimeValue::date($this->utc('2026-07-03')))->ends(DateTimeValue::date($this->utc('2026-07-05')))->get();
        $occurrences = Calendar::build()->add($master, $range)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
        $this->assertSame(['01', '03', '04'], array_map(static fn (Occurrence $o): string => $o->start->format('d'), $occurrences));
        $this->assertTrue($occurrences[2]->event->start()?->isDateOnly);
        $this->assertSame('20260706', $occurrences[2]->event->end()?->toString());
    }

    public function test_date_recurrence_rejects_sub_day_duration_sources(): void
    {
        $date = fn (string $value): DateTimeValue => DateTimeValue::date($this->utc($value));
        $invalidMaster = Event::build()
            ->uid('date-duration-master')
            ->starts($date('2026-07-01'))
            ->lasting(Duration::hours(12))
            ->recurrence(Recurrence::daily()->times(2))
            ->get();

        $singleMaster = Event::build()
            ->uid('date-duration-single')
            ->starts($date('2026-07-01'))
            ->lasting(Duration::days(1))
            ->recurrence(Recurrence::daily()->times(2))
            ->get();
        $invalidSingle = Event::build()
            ->uid('date-duration-single')
            ->recurrenceId($date('2026-07-02'))
            ->lasting(Duration::hours(12))
            ->get();

        $rangeMaster = Event::build()
            ->uid('date-duration-range')
            ->starts($date('2026-07-01'))
            ->lasting(Duration::days(1))
            ->recurrence(Recurrence::daily()->times(2))
            ->get();
        $invalidRange = Event::build()
            ->uid('date-duration-range')
            ->recurrenceId($date('2026-07-02'), Range::ThisAndFuture)
            ->lasting(Duration::hours(12))
            ->get();

        foreach ([
            Calendar::build()->add($invalidMaster)->get(),
            Calendar::build()->add(
                Event::build()->starts($date('2026-07-01'))->lasting(Duration::hours(12))->get(),
            )->get(),
            Calendar::build()->add($singleMaster, $invalidSingle)->get(),
            Calendar::build()->add($rangeMaster, $invalidRange)->get(),
        ] as $calendar) {
            try {
                $calendar->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
                $this->fail('Expected a sub-day DATE duration source to be rejected.');
            } catch (UnsupportedRecurrenceException $exception) {
                $this->assertStringContainsString('day- or week-based DURATION', $exception->getMessage());
            }
        }
    }

    public function test_zoned_range_keeps_shifted_wall_time_across_dst(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $zoned = static fn (string $value): DateTimeValue => DateTimeValue::zoned(new DateTimeImmutable($value, $zone), 'America/New_York');
        $master = Event::build()->uid('dst')->starts($zoned('2026-03-01 09:30'))->recurrence(Recurrence::weekly()->times(3))->get();
        $range = Event::build()->uid('dst')->recurrenceId($zoned('2026-03-08 09:30'), Range::ThisAndFuture)->starts($zoned('2026-03-08 10:30'))->get();
        $occurrences = Calendar::build()->add($master, $range)->get()->occurrencesBetween(new DateTimeImmutable('2026-03-01', $zone), new DateTimeImmutable('2026-03-31', $zone));
        $this->assertSame(['09:30', '10:30', '10:30'], array_map(static fn (Occurrence $o): string => $o->start->setTimezone($zone)->format('H:i'), $occurrences));
    }

    public function test_range_start_only_keeps_master_end_duration_coherent(): void
    {
        $master = Event::build()->uid('end')->starts($this->dtv('2026-07-01 10:00'))->ends($this->dtv('2026-07-01 11:30'))->recurrence(Recurrence::daily()->times(3))->get();
        $range = Event::build()->uid('end')->recurrenceId($this->dtv('2026-07-02 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-02 12:00'))->get();
        $occurrences = Calendar::build()->add($master, $range)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
        $this->assertSame('2026-07-03 13:30', $occurrences[2]->event->end()?->dateTime->format('Y-m-d H:i'));
    }

    public function test_range_duration_change_propagates_to_future_effective_events(): void
    {
        $master = Event::build()->uid('duration')->starts($this->dtv('2026-07-01 10:00'))->lasting(Duration::hours(1))->recurrence(Recurrence::daily()->times(3))->get();
        $range = Event::build()->uid('duration')->recurrenceId($this->dtv('2026-07-02 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-02 11:00'))->lasting(Duration::hours(3))->get();
        $occurrences = Calendar::build()->add($master, $range)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));

        $this->assertSame('PT3H', $occurrences[2]->event->duration()?->toString());
        $this->assertSame('2026-07-03 14:00', $occurrences[2]->event->end()?->dateTime->format('Y-m-d H:i'));
    }

    public function test_range_uses_the_same_wall_clock_delta_across_unequal_months(): void
    {
        $master = Event::build()
            ->uid('fixed-range-delta')
            ->starts($this->dtv('2026-01-01 10:00'))
            ->recurrence(Recurrence::monthly()->times(3))
            ->get();
        $range = Event::build()
            ->uid('fixed-range-delta')
            ->recurrenceId($this->dtv('2026-01-01 10:00'), Range::ThisAndFuture)
            ->starts($this->dtv('2026-02-01 10:00'))
            ->get();

        $occurrences = Calendar::build()->add($master, $range)->get()->occurrencesBetween(
            $this->utc('2026-01-01'),
            $this->utc('2026-05-01'),
        );

        $this->assertSame(
            ['2026-02-01 10:00', '2026-03-04 10:00', '2026-04-01 10:00'],
            array_map(static fn (Occurrence $occurrence): string => $occurrence->start->format('Y-m-d H:i'), $occurrences),
        );
    }

    public function test_date_dtend_range_uses_a_fixed_derived_duration_across_unequal_months(): void
    {
        $date = fn (string $value): DateTimeValue => DateTimeValue::date($this->utc($value));
        $master = Event::build()
            ->uid('fixed-date-duration')
            ->starts($date('2026-01-01'))
            ->ends($date('2026-01-02'))
            ->recurrence(Recurrence::monthly()->times(2))
            ->get();
        $range = Event::build()
            ->uid('fixed-date-duration')
            ->recurrenceId($date('2026-01-01'), Range::ThisAndFuture)
            ->starts($date('2026-01-01'))
            ->ends($date('2026-02-01'))
            ->get();

        $occurrences = Calendar::build()->add($master, $range)->get()->occurrencesBetween(
            $this->utc('2026-01-01'),
            $this->utc('2026-04-01'),
        );

        $this->assertSame('20260304', $occurrences[1]->event->end()?->toString());
    }

    public function test_recurrence_id_value_type_must_match_master_start(): void
    {
        $master = Event::build()->uid('type')->starts(DateTimeValue::date($this->utc('2026-07-01')))->recurrence(Recurrence::daily()->times(2))->get();
        $override = Event::build()->uid('type')->recurrenceId($this->dtv('2026-07-02 00:00'))->starts($this->dtv('2026-07-02 01:00'))->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        Calendar::build()->add($master, $override)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
    }

    public function test_date_and_floating_slots_match_by_calendar_fields(): void
    {
        $newYork = new DateTimeZone('America/New_York');
        $tokyo = new DateTimeZone('Asia/Tokyo');

        $dateMaster = Event::build()
            ->uid('date-wall')
            ->starts(DateTimeValue::date(new DateTimeImmutable('2026-07-01', $newYork)))
            ->recurrence(Recurrence::daily()->times(2))
            ->get();
        $dateOverride = Event::build()
            ->uid('date-wall')
            ->recurrenceId(DateTimeValue::date(new DateTimeImmutable('2026-07-02', $tokyo)))
            ->starts(DateTimeValue::date(new DateTimeImmutable('2026-07-03', $tokyo)))
            ->summary('date override')
            ->get();
        $dateOccurrences = Calendar::build()->add($dateMaster, $dateOverride)->get()
            ->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-05'));
        $this->assertCount(2, $dateOccurrences);
        $this->assertSame(['20260701', '20260703'], array_map(static fn (Occurrence $occurrence): string => $occurrence->event->start()?->toString() ?? '', $dateOccurrences));

        $floatingMaster = Event::build()
            ->uid('floating-wall')
            ->starts(DateTimeValue::floating(new DateTimeImmutable('2026-07-01 10:00', $newYork)))
            ->recurrence(Recurrence::daily()->times(2))
            ->get();
        $floatingOverride = Event::build()
            ->uid('floating-wall')
            ->recurrenceId(DateTimeValue::floating(new DateTimeImmutable('2026-07-02 10:00', $tokyo)))
            ->starts(DateTimeValue::floating(new DateTimeImmutable('2026-07-02 12:00', $tokyo)))
            ->summary('floating override')
            ->get();
        $floatingOccurrences = Calendar::build()->add($floatingMaster, $floatingOverride)->get()
            ->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-05'));
        $this->assertCount(2, $floatingOccurrences);
        $this->assertSame(['20260701T100000', '20260702T120000'], array_map(static fn (Occurrence $occurrence): string => $occurrence->event->start()?->toString() ?? '', $floatingOccurrences));
    }

    public function test_override_resolution_rejects_unresolved_custom_tzid_instants(): void
    {
        $custom = DateTimeValue::zoned($this->utc('2026-07-02 10:00'), 'Provider/Custom');
        $master = Event::build()
            ->uid('custom-override')
            ->starts($this->dtv('2026-07-01 10:00'))
            ->recurrence(Recurrence::daily()->times(2))
            ->get();

        $calendars = [
            Calendar::build()->add(Event::build()->uid('custom-orphan')->recurrenceId($custom)->get())->get(),
            Calendar::build()->add(
                $master,
                Event::build()->uid('custom-override')->recurrenceId($this->dtv('2026-07-02 10:00'))->starts($custom)->get(),
            )->get(),
            Calendar::build()->add(
                $master,
                Event::build()->uid('custom-override')->recurrenceId($this->dtv('2026-07-02 10:00'))->starts($this->dtv('2026-07-02 11:00'))->ends($custom)->get(),
            )->get(),
        ];

        foreach ($calendars as $calendar) {
            try {
                $calendar->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
                $this->fail('Expected unresolved custom-TZID override timing to be rejected.');
            } catch (UnsupportedRecurrenceException $exception) {
                $this->assertStringContainsString('custom TZID', $exception->getMessage());
            }
        }
    }

    public function test_single_override_can_use_an_explicit_resolved_iana_gap_start(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $zoned = static fn (string $value): DateTimeValue => DateTimeValue::zoned(new DateTimeImmutable($value, $zone), 'America/New_York');
        $master = Event::build()
            ->uid('single-gap')
            ->starts($zoned('2026-03-07 01:30'))
            ->recurrence(Recurrence::daily()->times(2))
            ->get();
        $gapStart = DateTimeValue::zoned($this->utc('2026-03-08 02:30'), 'America/New_York');
        $override = Event::build()
            ->uid('single-gap')
            ->recurrenceId($zoned('2026-03-08 01:30'))
            ->starts($gapStart)
            ->get();

        $occurrences = Calendar::build()->add($master, $override)->get()->occurrencesBetween(
            new DateTimeImmutable('2026-03-07', $zone),
            new DateTimeImmutable('2026-03-10', $zone),
        );

        $moved = $occurrences[1];
        $this->assertSame('20260308T023000', $moved->event->start()?->toString());
        $this->assertSame('03:30 EDT', $moved->start->setTimezone($zone)->format('H:i T'));
        $this->assertSame($moved->start->getTimestamp(), $moved->event->start()?->dateTime->getTimestamp());
    }

    public function test_cross_zone_range_materializes_an_event_coherent_with_occurrence_start(): void
    {
        $newYork = new DateTimeZone('America/New_York');
        $losAngeles = new DateTimeZone('America/Los_Angeles');
        $ny = static fn (string $value): DateTimeValue => DateTimeValue::zoned(new DateTimeImmutable($value, $newYork), 'America/New_York');
        $la = static fn (string $value): DateTimeValue => DateTimeValue::zoned(new DateTimeImmutable($value, $losAngeles), 'America/Los_Angeles');

        $master = Event::build()->uid('cross-zone')->starts($ny('2026-07-01 09:00'))->recurrence(Recurrence::daily()->times(3))->get();
        $range = Event::build()
            ->uid('cross-zone')
            ->recurrenceId($ny('2026-07-02 09:00'), Range::ThisAndFuture)
            ->starts($la('2026-07-02 09:00'))
            ->get();
        $occurrences = Calendar::build()->add($master, $range)->get()->occurrencesBetween(
            new DateTimeImmutable('2026-07-01', $newYork),
            new DateTimeImmutable('2026-07-10', $newYork),
        );

        $future = $occurrences[2];
        $this->assertSame($future->start->getTimestamp(), $future->event->start()?->dateTime->getTimestamp());
        $this->assertSame('America/Los_Angeles', $future->event->start()?->tzid);
        $this->assertSame('20260703T090000', $future->event->start()?->toString());
        $this->assertSame('2026-07-03 09:00', $future->start->setTimezone($losAngeles)->format('Y-m-d H:i'));
    }

    public function test_cross_zone_range_rejects_an_unrepresentable_second_fold_instant(): void
    {
        $utc = new DateTimeZone('UTC');
        $newYork = new DateTimeZone('America/New_York');
        $master = Event::build()
            ->uid('cross-zone-fold')
            ->starts(DateTimeValue::utc(new DateTimeImmutable('2026-10-31 06:30:00', $utc)))
            ->recurrence(Recurrence::daily()->times(3))
            ->get();
        $range = Event::build()
            ->uid('cross-zone-fold')
            ->recurrenceId(DateTimeValue::utc(new DateTimeImmutable('2026-10-31 06:30:00', $utc)), Range::ThisAndFuture)
            ->starts(DateTimeValue::zoned(new DateTimeImmutable('2026-10-31 02:30:00', $newYork), 'America/New_York'))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        $this->expectExceptionMessage('ambiguous local-time fold');
        Calendar::build()->add($master, $range)->get()->occurrencesBetween(
            new DateTimeImmutable('2026-10-31', $utc),
            new DateTimeImmutable('2026-11-03', $utc),
        );
    }

    public function test_malformed_or_ambiguous_detached_temporal_properties_fail_closed(): void
    {
        $documents = [
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:raw-rid\r\nSEQUENCE:1\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:raw-rid\r\nSEQUENCE:2\r\nRECURRENCE-ID:not-a-date\r\nDTSTART:20260702T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:raw-start\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:raw-start\r\nRECURRENCE-ID:20260702T100000Z\r\nDTSTART:not-a-date\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:raw-duration\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:raw-duration\r\nRECURRENCE-ID:20260702T100000Z\r\nDURATION:not-a-duration\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:duplicate-start\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:duplicate-start\r\nRECURRENCE-ID:20260702T100000Z\r\nDTSTART:20260702T110000Z\r\nDTSTART:20260702T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:raw-end\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:raw-end\r\nRECURRENCE-ID:20260702T100000Z\r\nDTSTART:20260702T110000Z\r\nDTEND:not-a-date\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:duplicate-end\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:duplicate-end\r\nRECURRENCE-ID:20260702T100000Z\r\nDTSTART:20260702T110000Z\r\nDTEND:20260702T120000Z\r\nDTEND:20260702T130000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:duplicate-duration\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:duplicate-duration\r\nRECURRENCE-ID:20260702T100000Z\r\nDURATION:PT1H\r\nDURATION:PT2H\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:end-and-duration\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:end-and-duration\r\nRECURRENCE-ID:20260702T100000Z\r\nDTSTART:20260702T110000Z\r\nDTEND:20260702T120000Z\r\nDURATION:PT1H\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:bad-range\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:bad-range\r\nRECURRENCE-ID;RANGE=THISANDPRIOR:20260702T100000Z\r\nDTSTART:20260702T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:duplicate-range\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:duplicate-range\r\nRECURRENCE-ID;RANGE=THISANDPRIOR;RANGE=THISANDFUTURE:20260702T100000Z\r\nDTSTART:20260702T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        ];

        foreach ($documents as $document) {
            try {
                Parser::lenient()->parseCalendar($document)->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
                $this->fail('Expected malformed detached temporal data to be rejected.');
            } catch (UnsupportedRecurrenceException) {
                $this->addToAssertionCount(1);
            }
        }

        $master = Event::build()->uid('duplicate-rid')->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(2))->get();
        $duplicate = Event::build()
            ->uid('duplicate-rid')
            ->recurrenceId($this->dtv('2026-07-02 10:00'))
            ->property('RECURRENCE-ID', $this->dtv('2026-07-03 10:00'))
            ->starts($this->dtv('2026-07-02 12:00'))
            ->get();
        $this->expectException(UnsupportedRecurrenceException::class);
        Calendar::build()->add($master, $duplicate)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
    }

    public function test_calendar_expansion_rejects_recurrence_set_data_on_detached_overrides(): void
    {
        foreach (['RRULE:FREQ=WEEKLY;COUNT=2', 'RDATE:20260704T100000Z', 'EXDATE:20260703T100000Z'] as $recurrenceLine) {
            $calendar = Parser::lenient()->parseCalendar(
                "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:detached-calendar-set\r\nDTSTART:20260701T100000Z\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:detached-calendar-set\r\nRECURRENCE-ID:20260702T100000Z\r\nDTSTART:20260702T120000Z\r\n{$recurrenceLine}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            );

            try {
                $calendar->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
                $this->fail('Expected recurrence-set data on a detached override to be rejected.');
            } catch (UnsupportedRecurrenceException $exception) {
                $this->assertStringContainsString('detached', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_sparse_overrides_without_dtstart_keep_defined_inheritance_semantics(): void
    {
        $master = Event::build()
            ->uid('sparse')
            ->summary('master')
            ->starts($this->dtv('2026-07-01 10:00'))
            ->lasting(Duration::hours(1))
            ->recurrence(Recurrence::daily()->times(3))
            ->get();
        $single = Event::build()->uid('sparse')->recurrenceId($this->dtv('2026-07-02 10:00'))->summary('single')->get();
        $range = Event::build()
            ->uid('sparse')
            ->recurrenceId($this->dtv('2026-07-03 10:00'), Range::ThisAndFuture)
            ->ends($this->dtv('2026-07-03 13:00'))
            ->summary('range')
            ->get();
        $occurrences = Calendar::build()->add($master, $single, $range)->get()
            ->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));

        $this->assertSame(['10:00', '10:00', '10:00'], array_map(static fn (Occurrence $occurrence): string => $occurrence->start->format('H:i'), $occurrences));
        $this->assertSame(['master', 'single', 'range'], array_map(static fn (Occurrence $occurrence): ?string => $occurrence->event->summary(), $occurrences));
        $this->assertSame('20260702T100000Z', $occurrences[1]->event->start()?->toString());
        $this->assertSame('PT1H', $occurrences[1]->event->duration()?->toString());
        $this->assertSame('20260702T110000Z', $occurrences[1]->event->end()?->toString());
        $this->assertFalse($occurrences[1]->event->isRecurring());
        $this->assertSame('13:00', $occurrences[2]->event->end()?->dateTime->format('H:i'));
    }

    public function test_dtend_derived_range_duration_is_exact_across_a_dst_fold(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $zoned = static fn (string $value): DateTimeValue => DateTimeValue::zoned(new DateTimeImmutable($value, $zone), 'America/New_York');
        $master = Event::build()
            ->uid('fold-duration')
            ->starts($zoned('2026-10-31 00:30'))
            ->ends($zoned('2026-10-31 01:30'))
            ->recurrence(Recurrence::daily()->times(3))
            ->get();
        $range = Event::build()
            ->uid('fold-duration')
            ->recurrenceId($zoned('2026-11-01 00:30'), Range::ThisAndFuture)
            ->starts($zoned('2026-11-01 00:30'))
            ->ends($zoned('2026-11-01 02:30'))
            ->get();
        $occurrences = Calendar::build()->add($master, $range)->get()->occurrencesBetween(
            new DateTimeImmutable('2026-10-30', $zone),
            new DateTimeImmutable('2026-11-05', $zone),
        );

        $this->assertSame(['2026-11-01 02:30 EST', '2026-11-02 03:30 EST'], array_map(
            static fn (Occurrence $occurrence): string => $occurrence->event->end()?->dateTime->setTimezone($zone)->format('Y-m-d H:i T') ?? '',
            array_slice($occurrences, 1),
        ));
    }

    public function test_cancelled_selected_master_suppresses_the_series_and_standalone_event(): void
    {
        $stale = Event::build()->uid('cancelled-master')->sequence(1)->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(2))->get();
        $cancelled = Event::build()->uid('cancelled-master')->sequence(2)->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(2))->status(EventStatus::Cancelled)->get();
        $standalone = Event::build()->starts($this->dtv('2026-07-01 12:00'))->status(EventStatus::Cancelled)->get();

        $this->assertSame([], Calendar::build()->add($stale, $cancelled, $standalone)->get()
            ->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10')));
    }

    public function test_orphan_and_misaligned_range_overrides_are_rejected(): void
    {
        $orphan = Event::build()
            ->uid('orphan-range')
            ->recurrenceId($this->dtv('2026-07-01 10:00'), Range::ThisAndFuture)
            ->starts($this->dtv('2026-07-01 11:00'))
            ->get();
        try {
            Calendar::build()->add($orphan)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
            $this->fail('Expected an orphan range to be rejected.');
        } catch (UnsupportedRecurrenceException) {
            $this->addToAssertionCount(1);
        }

        $master = Event::build()->uid('misaligned-range')->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(2))->get();
        $misaligned = Event::build()
            ->uid('misaligned-range')
            ->recurrenceId($this->dtv('2026-07-02 11:00'), Range::ThisAndFuture)
            ->starts($this->dtv('2026-07-02 12:00'))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        Calendar::build()->add($master, $misaligned)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
    }

    public function test_uidless_recurrence_id_components_are_not_treated_as_ordinary_events(): void
    {
        foreach ([null, Range::ThisAndFuture] as $range) {
            $event = Event::build()
                ->recurrenceId($this->dtv('2026-07-01 10:00'), $range)
                ->starts($this->dtv('2026-07-01 12:00'))
                ->get();

            try {
                Calendar::build()->add($event)->get()
                    ->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-02'));
                $this->fail('Expected a UID-less RECURRENCE-ID component to be rejected.');
            } catch (UnsupportedRecurrenceException $exception) {
                $this->assertStringContainsString('without a UID', $exception->getMessage());
            }
        }
    }

    public function test_non_cancelled_status_is_inherited_and_later_range_resumes_cancelled_tail(): void
    {
        $master = Event::build()->uid('range-status')->status(EventStatus::Confirmed)->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(6))->get();
        $tentative = Event::build()->uid('range-status')->status(EventStatus::Tentative)->recurrenceId($this->dtv('2026-07-02 10:00'), Range::ThisAndFuture)->get();
        $move = Event::build()->uid('range-status')->recurrenceId($this->dtv('2026-07-03 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-03 11:00'))->get();
        $cancel = Event::build()->uid('range-status')->status(EventStatus::Cancelled)->recurrenceId($this->dtv('2026-07-04 10:00'), Range::ThisAndFuture)->get();
        $resume = Event::build()->uid('range-status')->recurrenceId($this->dtv('2026-07-06 10:00'), Range::ThisAndFuture)->get();
        $occurrences = Calendar::build()->add($master, $tentative, $move, $cancel, $resume)->get()
            ->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));

        $this->assertSame(['01 10:00', '02 10:00', '03 11:00', '06 10:00'], array_map(static fn (Occurrence $occurrence): string => $occurrence->start->format('d H:i'), $occurrences));
        $this->assertSame(EventStatus::Tentative, $occurrences[2]->event->status());
        $this->assertSame(EventStatus::Tentative, $occurrences[3]->event->status());
        $this->assertFalse($occurrences[3]->event->isCancelled());
    }

    public function test_only_the_actual_range_onset_retains_range_metadata(): void
    {
        $master = Event::build()->uid('synthetic-range')->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(3))->get();
        $recurrenceId = new Property(
            'RECURRENCE-ID',
            $this->dtv('2026-07-02 10:00'),
            new ParameterBag(Range::ThisAndFuture, new RawParameter('X-PROVIDER-SLOT', 'opaque')),
        );
        $range = Event::build()->uid('synthetic-range')->recurrenceIdProperty($recurrenceId)->starts($this->dtv('2026-07-02 11:00'))->get();
        $occurrences = Calendar::build()->add($master, $range)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));

        $this->assertSame(Range::ThisAndFuture, $occurrences[1]->event->recurrenceRange());
        $this->assertSame($recurrenceId, $occurrences[1]->event->properties->first('RECURRENCE-ID'));
        $onsetMetadata = $occurrences[1]->event->properties->first('RECURRENCE-ID')?->parameter('X-PROVIDER-SLOT');
        $this->assertInstanceOf(RawParameter::class, $onsetMetadata);
        $this->assertSame('opaque', $onsetMetadata->value());
        $this->assertNull($occurrences[2]->event->recurrenceRange());
        $this->assertNull($occurrences[2]->event->properties->first('RECURRENCE-ID')?->parameter('X-PROVIDER-SLOT'));
        $this->assertSame('20260703T100000Z', $occurrences[2]->event->recurrenceId()?->toString());
    }

    public function test_sparse_single_override_inherits_the_effective_range_start(): void
    {
        $alarm = Alarm::build()->action(AlarmAction::Display)->trigger(Duration::minutes(-10));
        $master = Event::build()->uid('single-after-range')->summary('master')->starts($this->dtv('2026-07-01 10:00'))->lasting(Duration::hours(1))->recurrence(Recurrence::daily()->times(3))->addAlarm($alarm)->get();
        $range = Event::build()->uid('single-after-range')->summary('range')->description('inherited')->recurrenceId($this->dtv('2026-07-02 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-02 12:00'))->lasting(Duration::hours(2))->get();
        $single = Event::build()->uid('single-after-range')->recurrenceId($this->dtv('2026-07-03 10:00'))->location('single property change')->get();
        $occurrences = Calendar::build()->add($master, $range, $single)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));

        $this->assertSame(['10:00', '12:00', '12:00'], array_map(static fn (Occurrence $occurrence): string => $occurrence->start->format('H:i'), $occurrences));
        $effective = $occurrences[2]->event;
        $this->assertSame($occurrences[2]->start->getTimestamp(), $effective->start()?->dateTime->getTimestamp());
        $this->assertSame('range', $effective->summary());
        $this->assertSame('inherited', $effective->description());
        $this->assertSame('single property change', $effective->location());
        $this->assertSame('PT2H', $effective->duration()?->toString());
        $this->assertSame('14:00', $effective->end()?->dateTime->format('H:i'));
        $this->assertNull($effective->recurrenceRange());
        $this->assertFalse($effective->isRecurring());
        $this->assertCount(1, $occurrences[1]->event->alarms());
        $this->assertCount(1, $effective->alarms());
    }

    public function test_single_temporal_fields_override_range_timing_without_losing_range_properties(): void
    {
        $master = Event::build()->uid('single-timing')->starts($this->dtv('2026-07-01 10:00'))->ends($this->dtv('2026-07-01 11:00'))->recurrence(Recurrence::daily()->times(3))->get();
        $range = Event::build()->uid('single-timing')->summary('range state')->recurrenceId($this->dtv('2026-07-02 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-02 12:00'))->ends($this->dtv('2026-07-02 14:00'))->get();
        $single = Event::build()->uid('single-timing')->recurrenceId($this->dtv('2026-07-03 10:00'))->lasting(Duration::minutes(30))->get();
        $occurrences = Calendar::build()->add($master, $range, $single)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));

        $effective = $occurrences[2]->event;
        $this->assertSame('12:00', $occurrences[2]->start->format('H:i'));
        $this->assertSame('12:00', $effective->start()?->dateTime->format('H:i'));
        $this->assertSame('PT30M', $effective->duration()?->toString());
        $this->assertSame('12:30', $effective->end()?->dateTime->format('H:i'));
        $this->assertSame('range state', $effective->summary());
        $this->assertFalse($effective->hasProperty('DTEND'));
    }

    public function test_active_single_override_takes_precedence_inside_a_cancelled_range(): void
    {
        $master = Event::build()->uid('single-resume')->status(EventStatus::Confirmed)->starts($this->dtv('2026-07-01 10:00'))->lasting(Duration::hours(1))->recurrence(Recurrence::daily()->times(4))->get();
        $cancel = Event::build()->uid('single-resume')->summary('range state')->status(EventStatus::Cancelled)->recurrenceId($this->dtv('2026-07-02 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-02 11:00'))->get();
        $single = Event::build()->uid('single-resume')->recurrenceId($this->dtv('2026-07-03 10:00'))->location('restored instance')->get();
        $occurrences = Calendar::build()->add($master, $cancel, $single)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));

        $this->assertCount(2, $occurrences);
        $restored = $occurrences[1];
        $this->assertSame('03 11:00', $restored->start->format('d H:i'));
        $this->assertSame($restored->start->getTimestamp(), $restored->event->start()?->dateTime->getTimestamp());
        $this->assertSame(EventStatus::Confirmed, $restored->event->status());
        $this->assertSame('range state', $restored->event->summary());
        $this->assertSame('restored instance', $restored->event->location());
        $this->assertSame('12:00', $restored->event->end()?->dateTime->format('H:i'));
    }

    public function test_single_dtend_is_validated_against_the_range_effective_start(): void
    {
        $master = Event::build()->uid('single-end')->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(3))->get();
        $range = Event::build()->uid('single-end')->recurrenceId($this->dtv('2026-07-02 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-02 12:00'))->get();
        $single = Event::build()->uid('single-end')->recurrenceId($this->dtv('2026-07-03 10:00'))->ends($this->dtv('2026-07-03 11:00'))->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        Calendar::build()->add($master, $range, $single)->get()->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));
    }

    public function test_sparse_single_dtend_after_an_earlier_range_start_is_valid(): void
    {
        $master = Event::build()->uid('single-end-earlier')->starts($this->dtv('2026-07-01 10:00'))->recurrence(Recurrence::daily()->times(3))->get();
        $range = Event::build()->uid('single-end-earlier')->recurrenceId($this->dtv('2026-07-02 10:00'), Range::ThisAndFuture)->starts($this->dtv('2026-07-02 08:00'))->get();
        $single = Event::build()->uid('single-end-earlier')->recurrenceId($this->dtv('2026-07-03 10:00'))->ends($this->dtv('2026-07-03 09:00'))->get();

        $occurrences = Calendar::build()->add($master, $range, $single)->get()
            ->occurrencesBetween($this->utc('2026-07-01'), $this->utc('2026-07-10'));

        $this->assertSame('08:00', $occurrences[2]->start->format('H:i'));
        $this->assertSame('08:00', $occurrences[2]->event->start()?->dateTime->format('H:i'));
        $this->assertSame('09:00', $occurrences[2]->event->end()?->dateTime->format('H:i'));
    }

    public function test_range_arithmetic_rejects_an_unrepresentable_dst_gap_slot(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $zoned = static fn (string $value): DateTimeValue => DateTimeValue::zoned(new DateTimeImmutable($value, $zone), 'America/New_York');
        $master = Event::build()->uid('gap-range')->starts($zoned('2026-03-07 02:30'))->recurrence(Recurrence::daily()->times(2))->get();
        $range = Event::build()
            ->uid('gap-range')
            ->recurrenceId(DateTimeValue::zoned($this->utc('2026-03-08 02:30'), 'America/New_York'), Range::ThisAndFuture)
            ->starts($zoned('2026-03-08 04:30'))
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        Calendar::build()->add($master, $range)->get()->occurrencesBetween(
            new DateTimeImmutable('2026-03-07', $zone),
            new DateTimeImmutable('2026-03-10', $zone),
        );
    }

    public function test_range_materialization_accepts_a_real_post_gap_wall_time(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $zoned = static fn (string $value): DateTimeValue => DateTimeValue::zoned(new DateTimeImmutable($value, $zone), 'America/New_York');
        $master = Event::build()
            ->uid('post-gap-range')
            ->starts($zoned('2026-03-07 03:30'))
            ->recurrence(Recurrence::daily()->times(3))
            ->get();
        $range = Event::build()
            ->uid('post-gap-range')
            ->summary('property change')
            ->recurrenceId($zoned('2026-03-07 03:30'), Range::ThisAndFuture)
            ->get();

        $occurrences = Calendar::build()->add($master, $range)->get()->occurrencesBetween(
            new DateTimeImmutable('2026-03-07', $zone),
            new DateTimeImmutable('2026-03-11', $zone),
        );

        $this->assertSame(
            ['2026-03-07 03:30 EST', '2026-03-08 03:30 EDT', '2026-03-09 03:30 EDT'],
            array_map(static fn (Occurrence $occurrence): string => $occurrence->start->setTimezone($zone)->format('Y-m-d H:i T'), $occurrences),
        );
        $this->assertSame('property change', $occurrences[1]->event->summary());
        $this->assertSame('20260308T033000', $occurrences[1]->event->start()?->toString());
    }

    public function test_propagated_range_shift_preserves_a_future_nonexistent_wall_time_coherently(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $zoned = static fn (string $value): DateTimeValue => DateTimeValue::zoned(new DateTimeImmutable($value, $zone), 'America/New_York');
        $master = Event::build()->uid('shifted-gap')->starts($zoned('2026-03-07 01:30'))->recurrence(Recurrence::daily()->times(3))->get();
        $range = Event::build()
            ->uid('shifted-gap')
            ->recurrenceId($zoned('2026-03-07 01:30'), Range::ThisAndFuture)
            ->starts($zoned('2026-03-07 02:30'))
            ->get();
        $single = Event::build()->uid('shifted-gap')->summary('single overlay')->recurrenceId($zoned('2026-03-08 01:30'))->get();

        $occurrences = Calendar::build()->add($master, $range, $single)->get()->occurrencesBetween(
            new DateTimeImmutable('2026-03-07', $zone),
            new DateTimeImmutable('2026-03-11', $zone),
        );

        $gap = $occurrences[1];
        $this->assertSame('20260308T023000', $gap->event->start()?->toString());
        $this->assertSame('03:30 EDT', $gap->start->setTimezone($zone)->format('H:i T'));
        $this->assertSame($gap->start->getTimestamp(), $gap->event->start()?->dateTime->getTimestamp());
        $this->assertSame('single overlay', $gap->event->summary());
    }

    public function test_property_only_range_rejects_an_ambiguous_gap_occurrence_representation(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $zoned = static fn (string $value): DateTimeValue => DateTimeValue::zoned(new DateTimeImmutable($value, $zone), 'America/New_York');
        $master = Event::build()->uid('gap-materialization')->starts($zoned('2026-03-07 02:30'))->recurrence(Recurrence::daily()->times(3))->get();
        $range = Event::build()
            ->uid('gap-materialization')
            ->summary('property change')
            ->recurrenceId($zoned('2026-03-07 02:30'), Range::ThisAndFuture)
            ->get();

        $this->expectException(UnsupportedRecurrenceException::class);
        Calendar::build()->add($master, $range)->get()->occurrencesBetween(
            new DateTimeImmutable('2026-03-07', $zone),
            new DateTimeImmutable('2026-03-11', $zone),
        );
    }
}
