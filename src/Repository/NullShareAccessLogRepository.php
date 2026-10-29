<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;

/**
 * No-op access log storage when logging is disabled or the driver is unsupported.
 */
final class NullShareAccessLogRepository implements ShareAccessLogRepositoryInterface
{
    /**
     * Returns no entries (access logging disabled).
     */
    public function findByShare(SecureShare $share, int $limit = 50): array
    {
        return [];
    }

    /**
     * Discards the entry (access logging disabled).
     */
    public function persist(ShareAccessLog $log): void
    {
    }

    /**
     * Does nothing (access logging disabled).
     */
    public function flush(): void
    {
    }
}
