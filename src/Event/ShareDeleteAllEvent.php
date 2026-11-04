<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareDeleter;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before the manage "delete all" action removes shares ({@see YopassEvents::SHARE_DELETE_ALL}).
 *
 * By default every share whose creator is {@see getSubject()} (the viewer) is removed through
 * {@see ShareRepositoryInterface::removeAllByCreator()}. Listeners may:
 * - narrow the removal with {@see setSubject()}: only the viewer or another instance of the configured creator class
 *   (`nowo_yopass.user_class`) is accepted, any other object makes {@see ShareDeleter}
 *   throw a LogicException before anything is removed (a tenant / team entity would be matched by its id against
 *   creator ids and delete another user's shares),
 * - perform a context-aware removal (tenant, team, …) with their own query and report it with {@see markHandled()}
 *   (the built-in query is skipped, whatever the subject),
 * - or {@see veto()} the action (nothing is removed; the user gets an error flash). Veto before handling: once
 *   {@see markHandled()} was called, {@see veto()} is ignored (the removal already happened) and logged.
 */
final class ShareDeleteAllEvent extends Event
{
    private bool $vetoRequested = false;

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
     *
     * Ignored once a listener called {@see markHandled()} (the shares are already gone): the outcome stays "handled",
     * the bulk-deleted audit event still fires and {@see isVetoIgnored()} reports the late veto.
     */
    public function veto(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->vetoRequested = true;

        if (!$this->isHandled()) {
            $this->stopPropagation();
        }
    }

    /**
     * Whether a listener cancelled the removal (and no listener removed the shares itself).
     */
    public function isVetoed(): bool
    {
        return $this->vetoRequested && !$this->isHandled();
    }

    /**
     * Whether a veto was requested although a listener already removed the shares ({@see markHandled()}).
     */
    public function isVetoIgnored(): bool
    {
        return $this->vetoRequested && $this->isHandled();
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
