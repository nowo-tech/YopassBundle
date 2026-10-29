<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Exception;

use RuntimeException;
use Throwable;

/**
 * Share extension rejected (revoked share, invalid options, no-op, etc.).
 */
final class ShareExtendException extends RuntimeException
{
    /**
     * Creates the exception with a stable reason code (e.g. "revoked", "invalid_expiration").
     */
    public function __construct(
        public readonly string $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }
}
