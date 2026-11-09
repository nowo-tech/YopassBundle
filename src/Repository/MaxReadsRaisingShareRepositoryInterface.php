<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Service\ShareExtender;

/**
 * Optional capability of a {@see ShareRepositoryInterface}: raise the max reads of a share atomically, adding the
 * difference to the stored remaining reads (`reads_left = reads_left + delta`), so reads consumed by another worker
 * between loading the share and saving it are never given back (JS-L1). {@see ShareExtender} uses it when the
 * repository implements it; otherwise it saves the share as before.
 */
interface MaxReadsRaisingShareRepositoryInterface
{
    /**
     * Raises max reads from the share's current value to `$maxReads` when the stored share is not revoked and still
     * has that max reads value; returns false when nothing was updated (revoked or changed meanwhile).
     */
    public function raiseMaxReads(SecureShare $share, int $maxReads): bool;
}
