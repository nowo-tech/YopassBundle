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
     * Returns the most recent access log entries for a share.
     *
     * @return list<ShareAccessLog>
     */
    public function findByShare(SecureShare $share, int $limit = 50): array;

    /**
     * Schedules an access log entry for persistence.
     */
    public function persist(ShareAccessLog $log): void;

    /**
     * Writes pending access log changes to storage.
     */
    public function flush(): void;
}
