<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use Nowo\YopassBundle\Entity\SecureShare;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a manage route checks access to a single share.
 *
 * Default grant is creator ownership. Listeners may grant team, role, or
 * individual access without the bundle storing groups or permissions itself.
 */
final class ShareAccessCheckEvent extends Event
{
    /**
     * Creates the event for a user, a share and the requested action.
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly SecureShare $share,
        private readonly ShareAccessAction $action,
        private bool $granted,
    ) {
    }

    /**
     * Returns the user requesting access.
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Returns the share being checked.
     */
    public function getShare(): SecureShare
    {
        return $this->share;
    }

    /**
     * Returns the requested action.
     */
    public function getAction(): ShareAccessAction
    {
        return $this->action;
    }

    /**
     * Whether a listener granted access.
     */
    public function isGranted(): bool
    {
        return $this->granted;
    }

    /**
     * Grants access to the share for the requested action.
     */
    public function grant(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = true;
    }

    /**
     * Denies access to the share for the requested action.
     */
    public function deny(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = false;
    }
}
