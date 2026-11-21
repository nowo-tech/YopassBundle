<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Service\ShareRevoker;

/**
 * Optional capability of a {@see ShareRepositoryInterface} (2.1.1, REQ-RUNTIME-003 rule 4, audit YD1-L1): revoke a share
 * with one conditional write (`revoked_at = now, reads_left = 0` where the id matches and the share is not revoked yet),
 * so of two concurrent revokes only one writes and reports it, and a share deleted meanwhile is never reported revoked.
 * {@see ShareRevoker} uses it when the repository implements it; otherwise it revokes the entity and flushes.
 * Implemented by the Doctrine ORM and MongoDB ODM repositories.
 */
interface RevokingShareRepositoryInterface
{
    /**
     * Revokes the stored share when it exists and is not revoked yet; returns whether this call revoked it (false: it
     * was revoked by another call, or it no longer exists). A managed instance is refreshed after a successful write.
     */
    public function revokeIfActive(SecureShare $share): bool;
}
