<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use Nowo\YopassBundle\Doctrine\HostCreatorReference;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareRevokedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Revokes shares from the manage UI and dispatches {@see YopassEvents::SHARE_REVOKED} (metadata only).
 *
 * Application code: since 2.1.0 call {@see \Nowo\YopassBundle\Share\ShareCommandsInterface::revoke()} (by share id).
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
     * Revokes the share, flushes, then dispatches {@see YopassEvents::SHARE_REVOKED}.
     *
     * @param string|null $revokedById 2.1.0: the acting user's id when no security user is known (id-based commands)
     */
    public function revoke(SecureShare $share, ?UserInterface $revokedBy = null, ?string $revokedById = null): void
    {
        $share->revoke();
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->shareRepository->persist($share);
        $this->shareRepository->flush();

        $this->eventDispatcher?->dispatch(ShareRevokedEvent::fromShare($share, $revokedBy, $this->hostCreators?->forHost($share->getCreator()), $revokedById), YopassEvents::SHARE_REVOKED);
    }
}
