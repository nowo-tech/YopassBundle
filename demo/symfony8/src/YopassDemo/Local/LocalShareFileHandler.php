<?php

declare(strict_types=1);

namespace App\YopassDemo\Local;

use Nowo\YopassBundle\Service\ShareFileHandlerInterface;

/**
 * Enables the file tab in the demo using configurable local storage limits.
 */
final class LocalShareFileHandler implements ShareFileHandlerInterface
{
    public function __construct(
        private readonly int $maxFileBytes,
    ) {
    }

    public function getMaxFileBytes(): int
    {
        return $this->maxFileBytes;
    }
}
