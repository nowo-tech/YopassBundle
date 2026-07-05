<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareListQueryEvent;
use Nowo\YopassBundle\Event\ShareListResultEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;

/**
 * Loads shares for the manage list, dispatching events so applications can customize queries and filtering.
 */
final readonly class ShareLister
{
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return array{shares: list<SecureShare>, total: int}
     */
    public function list(UserInterface $viewer, int $pageSize, int $page): array
    {
        $queryEvent = new ShareListQueryEvent($viewer, $viewer);
        $this->eventDispatcher->dispatch($queryEvent, YopassEvents::SHARE_LIST_QUERY);

        if ($queryEvent->hasOverride()) {
            $shares = $queryEvent->getOverrideShares();
            $total  = $queryEvent->getOverrideTotal();
        } elseif ($pageSize > 0) {
            $subject = $queryEvent->getListSubject();
            $total   = $this->shareRepository->countByCreator($subject);
            $offset  = max(0, ($page - 1) * $pageSize);
            $shares  = $this->shareRepository->findByCreatorPaginated($subject, $pageSize, $offset);
        } else {
            $subject = $queryEvent->getListSubject();
            $shares  = $this->shareRepository->findByCreator($subject);
            $total   = count($shares);
        }

        $resultEvent = new ShareListResultEvent($viewer, $shares, $total);
        $this->eventDispatcher->dispatch($resultEvent, YopassEvents::SHARE_LIST_RESULT);

        return [
            'shares' => $resultEvent->getShares(),
            'total'  => $resultEvent->getTotal(),
        ];
    }
}
