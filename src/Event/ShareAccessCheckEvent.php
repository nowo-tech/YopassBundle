<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Share\ShareAccessGuardInterface;
use Nowo\YopassBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a manage route checks access to a single share.
 *
 * Default grant is creator ownership. Listeners may grant team, role, or
 * individual access without the bundle storing groups or permissions itself.
 *
 * Since 2.1.0 the check also runs by ids ({@see ShareAccessGuardInterface::canManageId()}):
 * listeners should decide on {@see getUserId()}, {@see getShareId()} and {@see getCreatorId()}. For an id-based check
 * {@see getUser()} is a stand-in built from the {@see UserRef} (not the application's user class).
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
        private readonly ?UserRef $userRef = null,
    ) {
    }

    /**
     * Returns the user requesting access.
     *
     * @deprecated since 2.1.0, use {@see self::getUserId()} / {@see self::getUserRef()}; removed in 3.0
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Returns the share being checked.
     *
     * @deprecated since 2.1.0, use {@see self::getShareId()} / {@see self::getCreatorId()}; removed in 3.0
     */
    public function getShare(): SecureShare
    {
        return $this->share;
    }

    /**
     * The user reference of an id-based check (null when the check was made with a security user). Since 2.1.0.
     */
    public function getUserRef(): ?UserRef
    {
        return $this->userRef;
    }

    /**
     * The id of the user requesting access: the {@see UserRef} id (null when it is only an identifier), else the
     * security user's `getId()`. Since 2.1.0.
     */
    public function getUserId(): ?string
    {
        if ($this->userRef instanceof UserRef) {
            return $this->userRef->idIsIdentifier ? null : $this->userRef->id;
        }

        return UserIdResolver::getId($this->user);
    }

    /**
     * The id of the share being checked. Since 2.1.0.
     */
    public function getShareId(): string
    {
        return $this->share->getId();
    }

    /**
     * The id of the share's creator (null when the creator exposes none). Since 2.1.0.
     */
    public function getCreatorId(): ?string
    {
        return $this->share->getCreatorId();
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
