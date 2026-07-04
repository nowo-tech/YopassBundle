<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

/**
 * Default file handler (512 KiB). Register explicitly and set `nowo_yopass.file_handler` to its service id.
 */
final class DefaultShareFileHandler implements ShareFileHandlerInterface
{
    private const MAX_FILE_BYTES = 512 * 1024;

    public function getMaxFileBytes(): int
    {
        return self::MAX_FILE_BYTES;
    }
}
