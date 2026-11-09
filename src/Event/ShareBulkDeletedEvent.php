<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use Nowo\YopassBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after shares were removed in bulk ({@see YopassEvents::SHARES_BULK_DELETED}): the manage "delete all"
 * action or a retention purge. Only dispatched when at least one share was removed.
 *
 * Bulk removals run as a single query, so the payload is the scope and the count only: no share ids and never any
 * ciphertext, key, password or decrypted content.
 */
final class ShareBulkDeletedEvent extends Event
{
    /** The manage "delete all" action. */
    public const REASON_DELETE_ALL = 'delete_all';

    /** Retention purge (manage list visit or the nowo:yopass:purge-old-shares command). */
    public const REASON_RETENTION = 'retention';

    /**
     * Creates the event with the reason, the scope subject, the removed count and the acting user.
     *
     * @param self::REASON_* $reason
     */
    public function __construct(
        private readonly string $reason,
        private readonly ?object $subject,
        private readonly int $removed,
        private readonly ?UserInterface $viewer = null,
    ) {
    }

    /**
     * Returns why the shares were removed (one of the REASON_* constants).
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Returns the creator / scope subject the removal was limited to, or null for a global retention purge.
     */
    public function getSubject(): ?object
    {
        return $this->subject;
    }

    /**
     * Returns the number of removed shares.
     */
    public function getRemoved(): int
    {
        return $this->removed;
    }

    /**
     * Returns the user who triggered the removal, if any (null for the console purge).
     */
    public function getViewer(): ?UserInterface
    {
        return $this->viewer;
    }

    /**
     * Returns the id of the subject whose shares were removed (null for a global purge). Since 1.16.
     */
    public function getSubjectId(): ?string
    {
        return $this->subject !== null ? UserIdResolver::getId($this->subject) : null;
    }

    /**
     * Returns the id of the viewer who triggered the removal (null for the purge command). Since 1.16.
     */
    public function getViewerId(): ?string
    {
        return $this->viewer instanceof UserInterface ? UserIdResolver::getId($this->viewer) : null;
    }
}
