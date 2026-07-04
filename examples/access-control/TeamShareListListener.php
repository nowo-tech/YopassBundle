<?php

declare(strict_types=1);

namespace App\YopassExamples\AccessControl;

use App\Entity\User;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareListQueryEvent;
use Nowo\YopassBundle\Event\ShareListResultEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

use function count;

/**
 * Lists shares created by every member of the viewer's team.
 *
 * Uses SHARE_LIST_QUERY to replace the default creator-only query.
 * Admins with ROLE_YOPASS_TEAM_ADMIN see the whole team; others only their own shares.
 */
final class TeamShareListListener
{
    public function __construct(
        private readonly ShareTeamRepository $teams,
        private readonly ShareRepositoryInterface $shareRepository,
    ) {
    }

    #[AsEventListener(event: YopassEvents::SHARE_LIST_QUERY)]
    public function onQuery(ShareListQueryEvent $event): void
    {
        $viewer = $event->getViewer();
        if (!$viewer instanceof User) {
            return;
        }

        $teamId = $viewer->getTeamId();
        if ($teamId === null) {
            return;
        }

        if (!$this->teams->viewerCanAccessTeamShares($viewer, $teamId)) {
            return;
        }

        $creators = $this->teams->listTeamMemberCreators($viewer, $teamId);
        $shares   = [];

        foreach ($creators as $creator) {
            foreach ($this->shareRepository->findByCreator($creator) as $share) {
                $shares[] = $share;
            }
        }

        usort($shares, static fn (SecureShare $a, SecureShare $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

        $event->overrideList($shares, count($shares));
    }
}

/**
 * Post-filter example: hide revoked shares from non-admin team members.
 */
final class TeamShareListFilterListener
{
    public function __construct(
        private readonly ShareTeamRepository $teams,
    ) {
    }

    #[AsEventListener(event: YopassEvents::SHARE_LIST_RESULT)]
    public function onResult(ShareListResultEvent $event): void
    {
        $viewer = $event->getViewer();
        if (!$viewer instanceof User || $this->teams->canDeleteTeamShares($viewer)) {
            return;
        }

        $filtered = array_values(array_filter(
            $event->getShares(),
            static fn (SecureShare $share): bool => $share->getRevokedAt() === null,
        ));

        $event->setShares($filtered);
        $event->setTotal(count($filtered));
    }
}
