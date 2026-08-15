<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Recurrence;

use Erenav\ICalendar\Exception\ICalendarException;
use RuntimeException;

/** Raised when a recurrence is preserved but cannot be expanded safely. */
final class UnsupportedRecurrenceException extends RuntimeException implements ICalendarException {}
