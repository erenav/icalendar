<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Recurrence;

use DateTimeImmutable;

/** @internal Maps the typed recurrence value to rlanvin/php-rrule options. */
final class RRuleAdapter
{
    /**
     * @return array<string, mixed>
     */
    public static function options(
        Recurrence $rule,
        DateTimeImmutable $dtstart,
        bool $includeUntil = true,
    ): array {
        $parts = [
            'FREQ' => $rule->frequency->value,
            'INTERVAL' => $rule->interval,
            'DTSTART' => $dtstart,
        ];

        if ($rule->count !== null) {
            $parts['COUNT'] = $rule->count;
        }
        if ($includeUntil && $rule->until !== null) {
            $parts['UNTIL'] = $rule->until->dateTime;
        }
        if ($rule->byDay !== []) {
            $parts['BYDAY'] = array_map(static fn (WeekdayRule $day): string => $day->toString(), $rule->byDay);
        }
        if ($rule->byMonthDay !== []) {
            $parts['BYMONTHDAY'] = $rule->byMonthDay;
        }
        if ($rule->byMonth !== []) {
            $parts['BYMONTH'] = $rule->byMonth;
        }
        if ($rule->byYearDay !== []) {
            $parts['BYYEARDAY'] = $rule->byYearDay;
        }
        if ($rule->byWeekNo !== []) {
            $parts['BYWEEKNO'] = $rule->byWeekNo;
        }
        if ($rule->byHour !== []) {
            $parts['BYHOUR'] = $rule->byHour;
        }
        if ($rule->byMinute !== []) {
            $parts['BYMINUTE'] = $rule->byMinute;
        }
        if ($rule->bySecond !== []) {
            $parts['BYSECOND'] = $rule->bySecond;
        }
        if ($rule->bySetPosition !== []) {
            $parts['BYSETPOS'] = $rule->bySetPosition;
        }
        if ($rule->weekStart !== null) {
            $parts['WKST'] = $rule->weekStart->value;
        }

        return $parts;
    }
}
