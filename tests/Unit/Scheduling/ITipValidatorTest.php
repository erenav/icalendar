<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Tests\Unit\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use Erenav\ICalendar\Component\Calendar;
use Erenav\ICalendar\Component\Event;
use Erenav\ICalendar\Exception\SchedulingException;
use Erenav\ICalendar\Parameter\ParameterBag;
use Erenav\ICalendar\Parameter\PartStat;
use Erenav\ICalendar\Parameter\RawParameter;
use Erenav\ICalendar\Property\EventStatus;
use Erenav\ICalendar\Property\Property;
use Erenav\ICalendar\Property\PropertyBag;
use Erenav\ICalendar\Scheduling\ITip;
use Erenav\ICalendar\Scheduling\ITipValidator;
use Erenav\ICalendar\Scheduling\Method;
use Erenav\ICalendar\ValueType\CalAddress;
use Erenav\ICalendar\ValueType\DateTimeValue;
use Erenav\ICalendar\ValueType\IntegerValue;
use Erenav\ICalendar\ValueType\RawValue;
use PHPUnit\Framework\TestCase;

final class ITipValidatorTest extends TestCase
{
    private ITipValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ITipValidator;
    }

    private function invitation(): Event
    {
        return Event::build()
            ->uid('m@test')
            ->summary('Plan')
            ->starts(DateTimeValue::utc(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC'))))
            ->organizer('boss@test')
            ->addAttendee('alice@test')
            ->get();
    }

    public function test_valid_request_passes(): void
    {
        $this->assertTrue($this->validator->isValid(ITip::request($this->invitation())));
    }

    public function test_valid_reply_passes(): void
    {
        $this->assertSame([], $this->validator->validate(ITip::reply($this->invitation(), 'alice@test', PartStat::Accepted)));
    }

    public function test_missing_method_is_reported(): void
    {
        $errors = $this->validator->validate(Calendar::build()->add($this->invitation())->get());
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('METHOD', $errors[0]);
    }

    public function test_request_requires_an_attendee(): void
    {
        $event = Event::build()
            ->uid('m@test')->summary('Plan')->sequence(0)
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->starts(DateTimeValue::utc(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC'))))
            ->organizer('boss@test')
            ->get();

        $calendar = Calendar::build()->method(Method::Request)->add($event)->get();
        $errors = $this->validator->validate($calendar);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('ATTENDEE', implode(' ', $errors));
    }

    public function test_reply_requires_partstat(): void
    {
        $event = Event::build()
            ->uid('m@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->organizer('boss@test')
            ->addAttendee('alice@test') // no PARTSTAT
            ->get();

        $calendar = Calendar::build()->method(Method::Reply)->add($event)->get();

        $this->assertFalse($this->validator->isValid($calendar));
    }

    public function test_assert_valid_throws_on_invalid(): void
    {
        $this->expectException(SchedulingException::class);
        $this->validator->assertValid(Calendar::build()->method(Method::Cancel)->add(Event::build()->summary('x')->get())->get());
    }

    public function test_a_scheduling_calendar_requires_an_event(): void
    {
        $errors = $this->validator->validate(Calendar::build()->method(Method::Publish)->get());

        $this->assertStringContainsString('at least one VEVENT', implode(' ', $errors));
    }

    public function test_refresh_requires_exactly_one_requesting_attendee_but_not_organizer(): void
    {
        $base = Event::build()
            ->uid('m@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))));

        $valid = Calendar::build()->method(Method::Refresh)->add(
            $base->addAttendee('alice@test')->get(),
        )->get();
        $this->assertSame([], $this->validator->validate($valid));

        $none = Calendar::build()->method(Method::Refresh)->add(
            Event::build()
                ->uid('m@test')
                ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
                ->get(),
        )->get();
        $this->assertStringContainsString('exactly one ATTENDEE', implode(' ', $this->validator->validate($none)));

        $multiple = Calendar::build()->method(Method::Refresh)->add(
            Event::build()
                ->uid('m@test')
                ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
                ->addAttendee('alice@test')
                ->addAttendee('bob@test')
                ->get(),
        )->get();
        $this->assertStringContainsString('exactly one ATTENDEE', implode(' ', $this->validator->validate($multiple)));
    }

    public function test_publish_does_not_require_optional_summary_or_organizer(): void
    {
        $event = Event::build()
            ->uid('published@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->starts(DateTimeValue::utc(new DateTimeImmutable('2026-07-01', new DateTimeZone('UTC'))))
            ->get();

        $this->assertSame([], $this->validator->validate(
            Calendar::build()->method(Method::Publish)->add($event)->get(),
        ));
    }

    public function test_request_does_not_require_optional_summary_or_sequence(): void
    {
        $event = Event::build()
            ->uid('request@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->starts(DateTimeValue::utc(new DateTimeImmutable('2026-07-01', new DateTimeZone('UTC'))))
            ->organizer('boss@test')
            ->addAttendee('alice@test')
            ->get();

        $this->assertSame([], $this->validator->validate(
            Calendar::build()->method(Method::Request)->add($event)->get(),
        ));
    }

    public function test_reply_requires_exactly_one_attendee(): void
    {
        $event = Event::build()
            ->uid('reply@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->organizer('boss@test')
            ->addAttendee('alice@test', partStat: PartStat::Accepted)
            ->addAttendee('bob@test', partStat: PartStat::Declined)
            ->get();

        $errors = $this->validator->validate(Calendar::build()->method(Method::Reply)->add($event)->get());
        $this->assertStringContainsString('exactly one ATTENDEE', implode(' ', $errors));
    }

    public function test_add_requires_recurrence_id_and_sequence_but_not_summary(): void
    {
        $base = Event::build()
            ->uid('add@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->starts(DateTimeValue::utc(new DateTimeImmutable('2026-07-01', new DateTimeZone('UTC'))))
            ->organizer('boss@test');

        $missing = $this->validator->validate(Calendar::build()->method(Method::Add)->add($base->get())->get());
        $this->assertStringContainsString('RECURRENCE-ID', implode(' ', $missing));
        $this->assertStringContainsString('SEQUENCE', implode(' ', $missing));

        $valid = $base
            ->recurrenceId(DateTimeValue::utc(new DateTimeImmutable('2026-07-01', new DateTimeZone('UTC'))))
            ->sequence(2)
            ->get();
        $this->assertSame([], $this->validator->validate(
            Calendar::build()->method(Method::Add)->add($valid)->get(),
        ));
    }

    public function test_counter_requires_exactly_one_attendee_but_not_summary(): void
    {
        $base = Event::build()
            ->uid('counter@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->starts(DateTimeValue::utc(new DateTimeImmutable('2026-07-01', new DateTimeZone('UTC'))))
            ->organizer('boss@test');

        $valid = $base->addAttendee('alice@test')->get();
        $this->assertSame([], $this->validator->validate(
            Calendar::build()->method(Method::Counter)->add($valid)->get(),
        ));

        $multiple = $base->addAttendee('bob@test')->get();
        $this->assertStringContainsString('exactly one ATTENDEE', implode(' ', $this->validator->validate(
            Calendar::build()->method(Method::Counter)->add($multiple)->get(),
        )));
    }

    public function test_declinecounter_requires_one_attendee_and_sequence_but_not_partstat(): void
    {
        $base = Event::build()
            ->uid('decline@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->organizer('boss@test')
            ->addAttendee('alice@test');

        $missing = $this->validator->validate(
            Calendar::build()->method(Method::DeclineCounter)->add($base->get())->get(),
        );
        $this->assertStringContainsString('SEQUENCE', implode(' ', $missing));
        $this->assertStringNotContainsString('PARTSTAT', implode(' ', $missing));

        $this->assertSame([], $this->validator->validate(
            Calendar::build()->method(Method::DeclineCounter)->add($base->sequence(3)->get())->get(),
        ));
    }

    public function test_required_singletons_and_method_reject_duplicate_properties(): void
    {
        $request = ITip::request($this->invitation())->events()[0];
        $duplicateMethod = Calendar::build()
            ->method(Method::Request)
            ->property('METHOD', Method::Reply)
            ->add($request)
            ->get();
        $this->assertStringContainsString('exactly one single-valued METHOD', implode(' ', $this->validator->validate($duplicateMethod)));

        $duplicateUid = $request->toBuilder()->property('UID', 'duplicate@test')->get();
        $errors = $this->validator->validate(
            Calendar::build()->method(Method::Request)->add($duplicateUid)->get(),
        );
        $this->assertStringContainsString('UID must occur exactly once', implode(' ', $errors));
    }

    public function test_attendee_cardinality_rejects_a_multi_valued_property(): void
    {
        $event = Event::build()
            ->uid('reply@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->organizer('boss@test')
            ->attendeeProperty(new Property('ATTENDEE', [
                CalAddress::fromEmail('alice@test'),
                CalAddress::fromEmail('bob@test'),
            ]))
            ->get();

        $errors = $this->validator->validate(Calendar::build()->method(Method::Reply)->add($event)->get());
        $this->assertStringContainsString('exactly one ATTENDEE', implode(' ', $errors));
    }

    public function test_cancel_rejects_duplicate_sequence_properties(): void
    {
        $cancel = ITip::cancel($this->invitation())->events()[0]
            ->toBuilder()
            ->property('SEQUENCE', new IntegerValue(99))
            ->get();

        $errors = $this->validator->validate(
            Calendar::build()->method(Method::Cancel)->add($cancel)->get(),
        );

        $this->assertStringContainsString('SEQUENCE must occur exactly once', implode(' ', $errors));
    }

    public function test_optional_sequence_is_validated_without_becoming_required(): void
    {
        $stamp = DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC')));
        $base = $this->invitation()->toBuilder()->timestamp($stamp)->get();
        $multiValuedProperties = array_values(array_filter(
            $base->properties->all(),
            static fn (Property $property): bool => $property->name !== 'SEQUENCE',
        ));
        $multiValuedProperties[] = new Property('SEQUENCE', [new IntegerValue(1), new IntegerValue(2)]);

        $invalid = [
            'negative' => $base->toBuilder()
                ->sequenceProperty(new Property('SEQUENCE', new IntegerValue(-1)))
                ->get(),
            'untyped' => $base->toBuilder()->property('SEQUENCE', new RawValue('2'))->get(),
            'duplicate' => $base->toBuilder()->sequence(1)->property('SEQUENCE', new IntegerValue(2))->get(),
            'multi-valued' => new Event(new PropertyBag(...$multiValuedProperties), $base->children),
        ];

        foreach ($invalid as $case => $event) {
            $errors = $this->validator->validate(
                Calendar::build()->method(Method::Request)->add($event)->get(),
            );
            $this->assertStringContainsString(
                'one non-negative INTEGER',
                implode(' ', $errors),
                "Failed to reject {$case} SEQUENCE metadata.",
            );
        }

        $withoutSequence = Calendar::build()->method(Method::Request)->add($base)->get();
        $this->assertSame([], $this->validator->validate($withoutSequence));
    }

    public function test_dtstamp_must_be_exactly_one_utc_date_time(): void
    {
        $instant = new DateTimeImmutable('2026-06-20 12:00:00', new DateTimeZone('UTC'));
        $base = $this->invitation();
        $invalid = [
            'floating' => $base->toBuilder()->timestamp(DateTimeValue::floating($instant))->get(),
            'date' => $base->toBuilder()->timestamp(DateTimeValue::date($instant))->get(),
            'untyped' => $base->toBuilder()->property('DTSTAMP', new RawValue('20260620T120000Z'))->get(),
            'TZID on UTC' => $base->toBuilder()->property(
                'DTSTAMP',
                DateTimeValue::utc($instant),
                new ParameterBag(new RawParameter('TZID', 'Etc/UTC')),
            )->get(),
            'duplicate' => $base->toBuilder()
                ->timestamp(DateTimeValue::utc($instant))
                ->property('DTSTAMP', DateTimeValue::utc($instant))
                ->get(),
        ];

        foreach ($invalid as $case => $event) {
            $errors = $this->validator->validate(
                Calendar::build()->method(Method::Request)->add($event)->get(),
            );
            $this->assertStringContainsString(
                'DTSTAMP',
                implode(' ', $errors),
                "Failed to reject {$case} DTSTAMP metadata.",
            );
        }

        $explicitDateTime = $base->toBuilder()->property(
            'DTSTAMP',
            DateTimeValue::utc($instant),
            new ParameterBag(new RawParameter('VALUE', 'DATE-TIME')),
        )->get();
        $this->assertSame([], $this->validator->validate(
            Calendar::build()->method(Method::Request)->add($explicitDateTime)->get(),
        ));
    }

    public function test_reply_rejects_rsvp_and_vtodo_only_partstat_values(): void
    {
        foreach ([PartStat::Completed, PartStat::InProcess] as $partStat) {
            $event = Event::build()
                ->uid('reply@test')
                ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
                ->organizer('boss@test')
                ->attendeeProperty(new Property(
                    'ATTENDEE',
                    CalAddress::fromEmail('alice@test'),
                    new ParameterBag($partStat),
                ))
                ->get();
            $errors = $this->validator->validate(
                Calendar::build()->method(Method::Reply)->add($event)->get(),
            );
            $this->assertStringContainsString('not valid for VEVENT', implode(' ', $errors));
        }

        $withRsvp = Event::build()
            ->uid('reply@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->organizer('boss@test')
            ->addAttendee('alice@test', partStat: PartStat::Accepted, rsvp: true)
            ->get();
        $errors = $this->validator->validate(
            Calendar::build()->method(Method::Reply)->add($withRsvp)->get(),
        );
        $this->assertStringContainsString('must not carry RSVP', implode(' ', $errors));
    }

    public function test_cancel_status_is_optional_but_must_be_single_cancelled_when_present(): void
    {
        $base = Event::build()
            ->uid('cancel@test')
            ->timestamp(DateTimeValue::utc(new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC'))))
            ->organizer('boss@test')
            ->sequence(4)
            ->addAttendee('removed@test')
            ->get();

        $this->assertSame([], $this->validator->validate(
            Calendar::build()->method(Method::Cancel)->add($base)->get(),
        ));

        $cancelledText = $base->toBuilder()->property('STATUS', 'CANCELLED')->get();
        $this->assertSame([], $this->validator->validate(
            Calendar::build()->method(Method::Cancel)->add($cancelledText)->get(),
        ));

        $invalid = [
            'wrong value' => $base->toBuilder()->status(EventStatus::Confirmed)->get(),
            'raw value' => $base->toBuilder()->property('STATUS', new RawValue('CANCELLED'))->get(),
            'duplicate' => $base->toBuilder()
                ->status(EventStatus::Cancelled)
                ->property('STATUS', EventStatus::Cancelled)
                ->get(),
        ];
        foreach ($invalid as $case => $event) {
            $errors = $this->validator->validate(
                Calendar::build()->method(Method::Cancel)->add($event)->get(),
            );
            $this->assertStringContainsString(
                'exactly once with value CANCELLED',
                implode(' ', $errors),
                "Failed to reject {$case} CANCEL STATUS.",
            );
        }
    }
}
