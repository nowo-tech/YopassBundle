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
    public function __construct(
        private readonly UserInterface $user,
        private readonly SecureShare $share,
        private readonly ShareAccessAction $action,
        private bool $granted,
    ) {
    }

    public function getUser(): UserInterface
    {
        return $this->user;
    }

    public function getShare(): SecureShare
    {
        return $this->share;
    }

    public function getAction(): ShareAccessAction
    {
        return $this->action;
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function grant(): void
    {
        $this->granted = true;
    }

    public function deny(): void
    {
        $this->granted = false;
    }
}
