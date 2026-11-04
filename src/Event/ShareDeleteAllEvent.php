<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before the manage "delete all" action removes shares ({@see YopassEvents::SHARE_DELETE_ALL}).
 *
 * By default every share whose creator is {@see getSubject()} (the viewer) is removed through
 * {@see ShareRepositoryInterface::removeAllByCreator()}. Listeners may:
 * - scope the removal with {@see setSubject()} (e.g. the tenant / team object the host's repository understands),
 * - perform a context-aware removal themselves and report it with {@see markHandled()} (the built-in query is
 *   skipped),
 * - or {@see veto()} the action (nothing is removed; the user gets an error flash).
 */
final class ShareDeleteAllEvent extends Event
{
    private bool $vetoed = false;

    private ?int $handledCount = null;

    /**
     * Creates the event for the viewer and the subject passed to removeAllByCreator().
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private object $subject,
    ) {
    }

    /**
     * Returns the user who requested the removal.
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Returns the object passed to removeAllByCreator() (defaults to the viewer).
     */
    public function getSubject(): object
    {
        return $this->subject;
    }

    /**
     * Replaces the object passed to removeAllByCreator().
     */
    public function setSubject(object $subject): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->subject = $subject;
    }

    /**
     * Cancels the removal; no share is deleted. Stops propagation.
     */
    public function veto(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->vetoed = true;
        $this->stopPropagation();
    }

    /**
     * Whether a listener cancelled the removal.
     */
    public function isVetoed(): bool
    {
        return $this->vetoed;
    }

    /**
     * Reports that a listener removed the shares itself (the built-in query is skipped).
     */
    public function markHandled(int $removed): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->handledCount = max(0, $removed);
    }

    /**
     * Whether a listener removed the shares itself.
     */
    public function isHandled(): bool
    {
        return $this->handledCount !== null;
    }

    /**
     * Returns the number of shares a listener reported as removed (0 when not handled).
     */
    public function getHandledCount(): int
    {
        return $this->handledCount ?? 0;
    }
}
