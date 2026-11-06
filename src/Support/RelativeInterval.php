<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Support;

use DateTimeImmutable;
use ErrorException;
use Throwable;

use function array_map;
use function implode;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function restore_error_handler;
use function set_error_handler;
use function substr_count;

use const PREG_SET_ORDER;

/**
 * A `shares.expiration_options` interval (PHP relative datetime modifier such as "1 hour" or "7 days", applied with a
 * leading "+" as ShareExtender does).
 */
final class RelativeInterval
{
    /** One `<N> <unit>` term of a modifier (units of PHP's relative formats; words and weekdays are not terms). */
    private const TERM = '([+-]*)[ \t]*(\d+[ \t]*(?:msec|ms|millisecond|usec|microsecond|secs?|second|mins?|minute|hour|day|fortnight|forthnight|weekday|week|month|year)s?)';

    /**
     * Modifier that goes back in time by `$modifier`, as a duration (retention cutoff). A leading "-" alone negates
     * only the first term ("-1 month 2 days" is minus a month PLUS two days), so every `<N> <unit>` term is negated.
     * A modifier with anything else in it (`1 month ago`, `14 days 2 pm`) has no terms to negate reliably: it keeps the
     * leading "-". Single terms give the same date as a leading "-".
     */
    public static function negated(string $modifier): string
    {
        if (preg_match('/^[ \t]*(?:' . self::TERM . '[ \t,]*)+$/iD', $modifier) !== 1) {
            return '-' . $modifier;
        }

        preg_match_all('/' . self::TERM . '/i', $modifier, $terms, PREG_SET_ORDER);

        return implode(' ', array_map(
            static fn (array $term): string => (substr_count($term[1], '-') % 2 === 0 ? '-' : '+') . $term[2],
            $terms,
        ));
    }

    /**
     * Seconds the interval adds, or null when it is empty, malformed or does not move time forward. Never throws: a
     * malformed modifier (a warning + false on PHP 8.2, a DateMalformedStringException on PHP >= 8.3) gives null.
     */
    public static function seconds(string $interval): ?int
    {
        $interval = ltrim($interval, '+ ');

        if ($interval === '') {
            return null;
        }

        // PHP 8.2 reports a malformed modifier as a warning: turn it into the PHP >= 8.3 exception, one path for both.
        // Scoped to the modify() call: the previous handler is restored in `finally` (nothing persists on a worker).
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $end = (new DateTimeImmutable('@0'))->modify('+' . $interval);
        } catch (Throwable) {
            $end = false;
        } finally {
            restore_error_handler();
        }

        return $end instanceof DateTimeImmutable && $end->getTimestamp() > 0 ? $end->getTimestamp() : null;
    }
}
