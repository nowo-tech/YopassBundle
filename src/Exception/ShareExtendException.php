<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Exception;

use RuntimeException;

/**
 * Share extension rejected (revoked share, invalid options, no-op, etc.).
 */
final class ShareExtendException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
