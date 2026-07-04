<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use Nowo\YopassBundle\Entity\SecureShare;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before loading shares for the manage list.
 *
 * By default the bundle queries shares where {@see getListSubject()} is the creator.
 * Listeners may change the subject (e.g. team entity) or call {@see overrideList()}
 * to skip the built-in repository query and supply a custom result set.
 */
final class ShareListQueryEvent extends Event
{
    /** @var list<SecureShare>|null */
    private ?array $overrideShares = null;

    private ?int $overrideTotal = null;

    public function __construct(
        private readonly UserInterface $viewer,
        private object $listSubject,
    ) {
    }

    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Entity/object passed to ShareRepositoryInterface creator queries.
     */
    public function getListSubject(): object
    {
        return $this->listSubject;
    }

    public function setListSubject(object $listSubject): void
    {
        $this->listSubject = $listSubject;
    }

    public function hasOverride(): bool
    {
        return $this->overrideShares !== null;
    }

    /**
     * @return list<SecureShare>
     */
    public function getOverrideShares(): array
    {
        return $this->overrideShares ?? [];
    }

    public function getOverrideTotal(): int
    {
        return $this->overrideTotal ?? 0;
    }

    /**
     * Skip the default repository query and use the provided shares instead.
     *
     * @param list<SecureShare> $shares
     */
    public function overrideList(array $shares, int $total): void
    {
        $this->overrideShares = $shares;
        $this->overrideTotal  = $total;
    }
}
