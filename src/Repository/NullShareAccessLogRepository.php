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
    public function findByShare(SecureShare $share, int $limit = 50): array
    {
        return [];
    }

    public function persist(ShareAccessLog $log): void
    {
    }

    public function flush(): void
    {
    }
}
