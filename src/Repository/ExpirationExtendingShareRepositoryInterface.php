<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Service\ShareExtender;

/**
 * Optional capability of a {@see ShareRepositoryInterface} (1.21.0, REQ-RUNTIME-003 rule 4): move the expiration of a
 * share forward atomically, with one conditional write (`expires_at = :new` where not revoked and the stored expiration
 * is still earlier), so two concurrent extensions never move it back (the later of the two always wins, whatever the
 * commit order) and a share revoked meanwhile is not extended. {@see ShareExtender} uses it when the repository
 * implements it; otherwise it changes the entity and flushes (last write wins). Implemented by the Doctrine ORM
 * repository.
 */
interface ExpirationExtendingShareRepositoryInterface
{
    /**
     * Sets the stored expiration to `$expiresAt` when the share is not revoked and its stored expiration is earlier;
     * returns false when nothing was updated (revoked, or an equal or later expiration was stored meanwhile). A managed
     * instance is refreshed afterwards.
     */
    public function extendExpiration(SecureShare $share, DateTimeImmutable $expiresAt): bool;
}
