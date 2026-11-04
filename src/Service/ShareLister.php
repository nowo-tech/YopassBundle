<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use LogicException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareListQueryEvent;
use Nowo\YopassBundle\Event\ShareListResultEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Support\CreatorSubjectGuard;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;

/**
 * Loads shares for the manage list, dispatching events so applications can customize queries and filtering.
 */
final readonly class ShareLister
{
    /**
     * Creates the lister with the repository, the event dispatcher and the configured creator class
     * ({@code nowo_yopass.user_class}, accepted as a list subject besides the viewer).
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        private EventDispatcherInterface $eventDispatcher,
        private ?string $creatorClass = null,
    ) {
    }

    /**
     * Lists the viewer's shares (one page) letting listeners alter the query and the result.
     *
     * A list subject other than the viewer or a {@code nowo_yopass.user_class} instance is refused with a
     * LogicException unless a listener supplied the result ({@see ShareListQueryEvent::overrideList()}).
     *
     * @throws LogicException When a listener set an unsupported list subject without overriding the list
     *
     * @return array{shares: list<SecureShare>, total: int}
     */
    public function list(UserInterface $viewer, int $pageSize, int $page): array
    {
        $queryEvent = new ShareListQueryEvent($viewer, $viewer, $pageSize, $page);
        $this->eventDispatcher->dispatch($queryEvent, YopassEvents::SHARE_LIST_QUERY);

        if (!$queryEvent->hasOverride()) {
            CreatorSubjectGuard::assertSupported(
                $queryEvent->getListSubject(),
                $viewer,
                $this->creatorClass,
                'Load tenant / team scoped shares with your own query and call ShareListQueryEvent::overrideList(), or use a custom ShareRepositoryInterface.',
            );
        }

        if ($queryEvent->hasOverride()) {
            $shares = $queryEvent->getOverrideShares();
            $total  = $queryEvent->getOverrideTotal();
        } elseif ($pageSize > 0) {
            $subject = $queryEvent->getListSubject();
            $total   = $this->shareRepository->countByCreator($subject);
            $shares  = $this->shareRepository->findByCreatorPaginated($subject, $pageSize, $queryEvent->getOffset());
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
