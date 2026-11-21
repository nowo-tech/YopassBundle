<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use LogicException;
use Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface;
use Nowo\YopassBundle\Doctrine\HostCreatorReference;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\ShareDeleteAllEvent;
use Nowo\YopassBundle\Event\ShareDeletedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareSummaryRepositoryInterface;
use Nowo\YopassBundle\Share\ShareCommandsInterface;
use Nowo\YopassBundle\Support\CreatorSubjectGuard;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Deletes shares from the manage UI and dispatches the lifecycle events (metadata only, never secret material).
 *
 * Application code: since 2.1.0 call {@see ShareCommandsInterface} (`delete()`,
 * `deleteByIds()`, `deleteAllOf()`, by ids).
 */
final readonly class ShareDeleter
{
    /**
     * Creates the deleter.
     *
     * @param ShareRepositoryInterface $shareRepository Share persistence
     * @param EventDispatcherInterface|null $eventDispatcher Lifecycle events (none without a dispatcher)
     * @param string|null $creatorClass Configured creator class ({@code nowo_yopass.user_class}) accepted as a
     *                                  delete-all subject besides the viewer
     * @param LoggerInterface|null $logger Logs an ignored late veto (never share content)
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?string $creatorClass = null,
        private ?LoggerInterface $logger = null,
        private ?CreatorReferenceResolverInterface $creators = null,
        private ?HostCreatorReference $hostCreators = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Deletes one share and dispatches {@see YopassEvents::SHARE_DELETED}.
     *
     * @param string|null $deletedById 2.1.0: the acting user's id when no security user is known (id-based commands)
     */
    public function delete(SecureShare $share, ?UserInterface $deletedBy = null, ?string $deletedById = null): void
    {
        // Metadata is captured before removal; the event never references the entity (ciphertext).
        $event = ShareDeletedEvent::fromShare($share, $deletedBy, $this->hostCreators?->forHost($share->getCreator()), $deletedById, $this->clock?->now());

        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->shareRepository->remove($share);
        $this->shareRepository->flush();

        $this->eventDispatcher?->dispatch($event, YopassEvents::SHARE_DELETED);
    }

    /**
     * Deletes the share with this id (2.1.0, id-based commands) and dispatches {@see YopassEvents::SHARE_DELETED}.
     *
     * With a repository that deletes by id ({@see ShareSummaryRepositoryInterface}, the Doctrine ORM one) the removal is
     * one conditional statement: of two concurrent deletes of the same share only one removes the row, returns true and
     * dispatches the event (REQ-RUNTIME-003). Other repositories remove the loaded share and flush.
     *
     * @return bool whether this call deleted the share (false: no share with this id)
     */
    public function deleteById(string $shareId, ?string $deletedById = null): bool
    {
        $share = $this->shareRepository->find($shareId);
        if (!$share instanceof SecureShare || $share->getId() !== $shareId) {
            return false;
        }

        $event = ShareDeletedEvent::fromShare($share, null, $this->hostCreators?->forHost($share->getCreator()), $deletedById, $this->clock?->now());

        if ($this->shareRepository instanceof ShareSummaryRepositoryInterface) {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            if ($this->shareRepository->removeByIds([$shareId]) === 0) {
                return false;
            }
        } else {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->shareRepository->remove($share);
            $this->shareRepository->flush();
        }

        $this->eventDispatcher?->dispatch($event, YopassEvents::SHARE_DELETED);

        return true;
    }

    /**
     * Deletes every share of the viewer (or of the subject a {@see YopassEvents::SHARE_DELETE_ALL} listener set).
     *
     * A subject other than the viewer or a {@code nowo_yopass.user_class} instance is refused with a LogicException
     * before anything is removed, unless a listener removed the shares itself ({@see ShareDeleteAllEvent::markHandled()}).
     *
     * @throws LogicException When a listener set an unsupported subject without handling the removal
     *
     * @return int|null Number of removed shares, or null when a listener vetoed the removal
     */
    public function deleteAll(UserInterface $viewer): ?int
    {
        $event = new ShareDeleteAllEvent($viewer, $viewer);
        $this->eventDispatcher?->dispatch($event, YopassEvents::SHARE_DELETE_ALL);

        if ($event->isVetoed()) {
            return null;
        }

        if ($event->isHandled()) {
            $removed = $event->getHandledCount();

            if ($event->isVetoIgnored()) {
                $this->logger?->warning('nowo_yopass: a share_delete_all listener called veto() after markHandled(); the veto was ignored because the shares were already removed.', [
                    'removed' => $removed,
                ]);
            }
        } else {
            CreatorSubjectGuard::assertSupported(
                $event->getSubject(),
                $viewer,
                $this->creatorClass,
                'Remove tenant / team scoped shares with your own query and call ShareDeleteAllEvent::markHandled(), or use a custom ShareRepositoryInterface.',
            );

            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $removed = $this->shareRepository->removeAllByCreator($this->creator($event->getSubject()));

            if ($removed > 0) {
                $this->shareRepository->flush();
            }
        }

        if ($removed > 0) {
            $this->eventDispatcher?->dispatch(
                new ShareBulkDeletedEvent(ShareBulkDeletedEvent::REASON_DELETE_ALL, $event->getSubject(), $removed, $viewer, null, $this->clock?->now()),
                YopassEvents::SHARES_BULK_DELETED,
            );
        }

        return $removed;
    }

    /**
     * The creator to query for a user: refused (AccessDeniedException) unless it is accepted (JX-Y6, every driver).
     */
    private function creator(object $user): object
    {
        return $this->creators?->reference($user) ?? $user;
    }
}
