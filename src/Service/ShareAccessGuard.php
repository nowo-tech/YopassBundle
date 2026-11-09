<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Checks per-share manage access, dispatching an event so applications can extend creator-only rules.
 */
final readonly class ShareAccessGuard
{
    /**
     * Creates the guard with the event dispatcher and (1.16) the accepted security-user classes.
     */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ?CreatorClassPolicy $creatorClasses = null,
    ) {
    }

    /**
     * Whether the user may perform the action on the share (owner by default, overridable via ShareAccessCheckEvent).
     */
    public function canManage(UserInterface $user, SecureShare $share, ShareAccessAction $action): bool
    {
        // JX-Y1 (1.16): the viewer is the owner only when it is a user_class instance (or a listed accepted class) with
        // a usable id equal to the creator's; Admin#5 never owns Customer#5's share. Listeners may still grant.
        $granted = UserIdResolver::isSameUser($share->getCreator(), $user)
            && (!$this->creatorClasses instanceof CreatorClassPolicy || $this->creatorClasses->idOf($user) !== null);

        $event = new ShareAccessCheckEvent($user, $share, $action, $granted);
        $this->eventDispatcher->dispatch($event, YopassEvents::SHARE_ACCESS_CHECK);

        return $event->isGranted();
    }
}
