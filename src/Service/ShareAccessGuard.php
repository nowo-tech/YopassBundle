<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Security\UserRefSecurityUser;
use Nowo\YopassBundle\Share\ShareAccessGuardInterface;
use Nowo\YopassBundle\Share\ShareCriteria;
use Nowo\YopassBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function hash_equals;

/**
 * Checks per-share manage access, dispatching an event so applications can extend creator-only rules.
 */
final readonly class ShareAccessGuard implements ShareAccessGuardInterface
{
    /**
     * Creates the guard with the event dispatcher, (1.16) the accepted security-user classes and (2.1.0) the share
     * repository for the id-based check.
     */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ?CreatorClassPolicy $creatorClasses = null,
        private ?ShareRepositoryInterface $shares = null,
    ) {
    }

    /**
     * 2.1.0 (DDD-host checklist B8): the same check by ids. The owner default compares the {@see UserRef} id with the
     * share's creator id exactly (constant time); a reference whose id is only its identifier is never the owner. The
     * {@see ShareAccessCheckEvent} is dispatched with the reference ({@see ShareAccessCheckEvent::getUserRef()}).
     */
    public function canManageId(UserRef $user, string $shareId, ShareAccessAction $action): bool
    {
        if (!$this->shares instanceof ShareRepositoryInterface || !ShareCriteria::isUsableShareId($shareId)) {
            return false;
        }

        $share = $this->shares->find($shareId);
        if (!$share instanceof SecureShare || $share->getId() !== $shareId) {
            return false;
        }

        $creatorId = $share->getCreatorId();
        $granted   = !$user->idIsIdentifier && $creatorId !== null && hash_equals($creatorId, $user->id);

        $event = new ShareAccessCheckEvent(new UserRefSecurityUser($user), $share, $action, $granted, $user);
        $this->eventDispatcher->dispatch($event, YopassEvents::SHARE_ACCESS_CHECK);

        return $event->isGranted();
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
