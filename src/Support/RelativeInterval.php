<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Support;

use DateTimeImmutable;
use ErrorException;
use Throwable;

use function ltrim;
use function restore_error_handler;
use function set_error_handler;

/**
 * A `shares.expiration_options` interval (PHP relative datetime modifier such as "1 hour" or "7 days", applied with a
 * leading "+" as ShareExtender does).
 */
final class RelativeInterval
{
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
