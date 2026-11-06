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
    /** Blank between the parts of a term: PHP's parser also skips line breaks and a no-break space (bytes, no `u` flag). */
    private const BLANK = '(?:\s|\xC2\xA0)*';

    /** One `<N> <unit>` term of a modifier (units of PHP's relative formats; words and weekdays are not terms). */
    private const TERM = '([+-]*)' . self::BLANK . '(\d+)' . self::BLANK . '(msecs?|ms|milliseconds?|usecs?|microseconds?|seconds?|secs?|minutes?|mins?|hours?|days?|fortnights?|forthnights?|weekdays?|weeks?|months?|years?)';

    /** Only `<N> <unit>` terms, separated by blanks or commas. */
    private const TERMS = '/^' . self::BLANK . '(?:' . self::TERM . '(?:\s|\xC2\xA0|,)*)+$/iD';

    /** First year a cutoff may fall in: earlier ones make the purge throw on MySQL (DATETIME starts at year 1000). */
    private const MIN_CUTOFF_YEAR = 1001;

    /**
     * Whether a modifier is only `<N> <unit>` terms. Anything else (`0 days midnight`, `14 days yesterday`) can delete
     * far more than it reads.
     */
    public static function isPlainTerms(string $modifier): bool
    {
        return preg_match(self::TERMS, $modifier) === 1;
    }

    /**
     * Modifier that goes back in time by `$modifier`, as a duration (retention cutoff). A leading "-" alone negates
     * only the first term ("-1 month 2 days" is minus a month PLUS two days), so every `<N> <unit>` term is negated.
     * A modifier with anything else in it (`1 month ago`, `14 days 2 pm`) has no terms to negate reliably: it keeps the
     * leading "-". Single terms give the same date as a leading "-".
     */
    public static function negated(string $modifier): string
    {
        if (!self::isPlainTerms($modifier)) {
            return '-' . $modifier;
        }

        preg_match_all('/' . self::TERM . '/i', $modifier, $terms, PREG_SET_ORDER);

        return implode(' ', array_map(
            static fn (array $term): string => (substr_count($term[1], '-') % 2 === 0 ? '-' : '+') . $term[2] . ' ' . $term[3],
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

        $end = self::modify(new DateTimeImmutable('@0'), '+' . $interval);

        return $end instanceof DateTimeImmutable && $end->getTimestamp() > 0 ? $end->getTimestamp() : null;
    }

    /**
     * Retention cutoff of `$maxAge` at `$now`, or null when the modifier is malformed or the cutoff is out of range.
     * Shares created before the cutoff get deleted, so a cutoff at or after `$now` (`-14 days`, `1 month ago`, `0 days`)
     * would delete every live share; one in the deep past (`100000 years`) makes the purge throw. Never throws.
     */
    public static function retentionCutoff(DateTimeImmutable $now, string $maxAge): ?DateTimeImmutable
    {
        $cutoff = self::modify($now, self::negated($maxAge));

        return $cutoff instanceof DateTimeImmutable && $cutoff < $now && (int) $cutoff->format('Y') >= self::MIN_CUTOFF_YEAR ? $cutoff : null;
    }

    /**
     * `modify()` with null for a malformed modifier (a warning + false on PHP 8.2, a DateMalformedStringException on
     * PHP >= 8.3).
     */
    private static function modify(DateTimeImmutable $from, string $modifier): ?DateTimeImmutable
    {
        // PHP 8.2 reports a malformed modifier as a warning: turn it into the PHP >= 8.3 exception, one path for both.
        // Scoped to the modify() call: the previous handler is restored in `finally` (nothing persists on a worker).
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $result = $from->modify($modifier);
        } catch (Throwable) {
            $result = false;
        } finally {
            restore_error_handler();
        }

        return $result instanceof DateTimeImmutable ? $result : null;
    }
}
