<?php

declare(strict_types=1);

namespace Erenav\ICalendar\Exception;

use Erenav\ICalendar\Parser\Parser;
use RuntimeException;

/**
 * Thrown when parsing cannot produce the requested component. Strict mode uses
 * it for RFC-shape/value violations; lenient mode can still raise it when no
 * complete component exists or when {@see Parser::parseCalendar()} receives a
 * non-VCALENDAR root.
 */
final class ParseException extends RuntimeException implements ICalendarException {}
