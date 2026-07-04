<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

/**
 * Enables file payloads in the manage UI when registered via {@see Configuration::file_handler}.
 */
interface ShareFileHandlerInterface
{
    /**
     * Maximum raw file size in bytes before client-side encryption.
     */
    public function getMaxFileBytes(): int;
}
