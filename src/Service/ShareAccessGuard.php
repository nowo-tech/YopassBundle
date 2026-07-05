<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

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
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function canManage(UserInterface $user, SecureShare $share, ShareAccessAction $action): bool
    {
        $granted = UserIdResolver::isSameUser($share->getCreator(), $user);

        $event = new ShareAccessCheckEvent($user, $share, $action, $granted);
        $this->eventDispatcher->dispatch($event, YopassEvents::SHARE_ACCESS_CHECK);

        return $event->isGranted();
    }
}
