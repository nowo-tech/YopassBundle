<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;

/**
 * Persists and queries public link open events.
 */
interface ShareAccessLogRepositoryInterface
{
    /**
     * @return list<ShareAccessLog>
     */
    public function findByShare(SecureShare $share, int $limit = 50): array;

    public function persist(ShareAccessLog $log): void;

    public function flush(): void;
}
