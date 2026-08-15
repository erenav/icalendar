<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\Builder;

use Erenav\ICalendar\Component\Alarm;
use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Property\AlarmAction;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\Duration;
use PHPUnit\Framework\TestCase;

final class CalendarAndAlarmBuilderTest extends TestCase
{
    public function test_calendar_defaults_version_and_adds_components(): void
    {
        $calendar = Calendar::build()
            ->prodId('-//Erenav//ICalendar 1.0//EN')
            ->add(Event::build()->uid('1'))
            ->add(Event::build()->uid('2')->get())
            ->get();

        $this->assertSame('2.0', $calendar->version());
        $this->assertSame('-//Erenav//ICalendar 1.0//EN', $calendar->productId());
        $this->assertCount(2, $calendar->events());
    }

    public function test_version_is_overridable(): void
    {
        $this->assertSame('1.0', Calendar::build()->version('1.0')->get()->version());
    }

    public function test_calendar_name_has_matching_read_api(): void
    {
        $this->assertSame('Team calendar', Calendar::build()->name('Team calendar')->get()->name());
    }

    public function test_alarm_builder_relative_trigger(): void
    {
        $alarm = Alarm::build()
            ->action(AlarmAction::Display)
            ->description('Reminder')
            ->trigger(Duration::minutes(-15))
            ->get();

        $this->assertSame(AlarmAction::Display, $alarm->action());
        $this->assertSame('Reminder', $alarm->description());
        $this->assertInstanceOf(Duration::class, $alarm->trigger());
        $this->assertSame('-PT15M', $alarm->trigger()?->toString());
    }

    public function test_alarm_builder_absolute_trigger(): void
    {
        $alarm = Alarm::build()
            ->action(AlarmAction::Audio)
            ->trigger(new \DateTimeImmutable('2026-07-01 09:45:00', new \DateTimeZone('UTC')))
            ->get();

        $this->assertInstanceOf(DateTimeValue::class, $alarm->trigger());
    }

    public function test_event_builder_attaches_alarm_from_builder(): void
    {
        $event = Event::build()
            ->uid('1')
            ->addAlarm(Alarm::build()->action(AlarmAction::Display)->trigger(Duration::minutes(-10)))
            ->get();

        $this->assertCount(1, $event->alarms());
        $this->assertSame(AlarmAction::Display, $event->alarms()[0]->action());
    }
}
