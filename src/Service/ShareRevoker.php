<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Doctrine\HostCreatorReference;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareRevokedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\RevokingShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Share\ShareCommandsInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Revokes shares from the manage UI and dispatches {@see YopassEvents::SHARE_REVOKED} (metadata only).
 *
 * Application code: since 2.1.0 call {@see ShareCommandsInterface::revoke()} (by share id).
 */
final readonly class ShareRevoker
{
    /**
     * Creates the revoker with the repository and an optional event dispatcher (no event without one).
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?HostCreatorReference $hostCreators = null,
    ) {
    }

    /**
     * Revokes the share and dispatches {@see YopassEvents::SHARE_REVOKED} after the write; returns whether this call
     * revoked it.
     *
     * 2.1.1 (REQ-RUNTIME-003, audit YD1-L1): with a repository that implements
     * {@see RevokingShareRepositoryInterface} (the Doctrine ORM one) the revoke is one conditional write
     * (`WHERE id = ? AND revoked_at IS NULL`) and the affected-row count decides: of two concurrent revokes only one
     * writes and dispatches the event, and a share deleted meanwhile is not revoked and dispatches nothing (false).
     * Other repositories (MongoDB ODM, `database.driver: custom`) revoke the loaded share and flush: a share already
     * revoked in memory is left unchanged (false); two revokes that both loaded the share before either wrote may
     * both return true and dispatch the event (see docs/CONCURRENCY.md, residual risks).
     *
     * @param string|null $revokedById 2.1.0: the acting user's id when no security user is known (id-based commands)
     *
     * @return bool whether this call revoked the share (2.1.1; before, nothing was returned)
     */
    public function revoke(SecureShare $share, ?UserInterface $revokedBy = null, ?string $revokedById = null): bool
    {
        if ($this->shareRepository instanceof RevokingShareRepositoryInterface) {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            if (!$this->shareRepository->revokeIfActive($share)) {
                return false;
            }
        } else {
            if ($share->getRevokedAt() instanceof DateTimeImmutable) {
                return false;
            }

            $share->revoke();
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->shareRepository->persist($share);
            $this->shareRepository->flush();
        }

        $this->eventDispatcher?->dispatch(ShareRevokedEvent::fromShare($share, $revokedBy, $this->hostCreators?->forHost($share->getCreator()), $revokedById), YopassEvents::SHARE_REVOKED);

        return true;
    }
}
