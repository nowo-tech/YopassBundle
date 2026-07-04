<?php

declare(strict_types=1);

namespace App\YopassExamples\AccessControl;

use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * Role-based per-share access: auditors may preview but not revoke/delete.
 */
#[AsEventListener(event: YopassEvents::SHARE_ACCESS_CHECK, priority: -10)]
final class RoleBasedShareAccessListener
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function __invoke(ShareAccessCheckEvent $event): void
    {
        if ($event->isGranted()) {
            return;
        }

        if (!$this->security->isGranted('ROLE_YOPASS_AUDITOR')) {
            return;
        }

        if (!in_array($event->getAction(), [ShareAccessAction::View, ShareAccessAction::Preview], true)) {
            $event->deny();

            return;
        }

        if (!$this->canAuditorSeeShare($event->getUser(), $event->getShare()->getId())) {
            return;
        }

        $event->grant();
    }

    private function canAuditorSeeShare(UserInterface $user, string $shareId): bool
    {
        // Example: auditors only see shares flagged for compliance review in your app.
        return true;
    }
}
