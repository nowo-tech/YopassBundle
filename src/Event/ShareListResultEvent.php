<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use Nowo\YopassBundle\Entity\SecureShare;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after shares are loaded for the manage list.
 *
 * Listeners may filter, reorder, or adjust the total count (e.g. hide shares
 * the viewer should not see even when they share a team or role).
 */
final class ShareListResultEvent extends Event
{
    /**
     * @param list<SecureShare> $shares
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private array $shares,
        private int $total,
    ) {
    }

    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * @return list<SecureShare>
     */
    public function getShares(): array
    {
        return $this->shares;
    }

    /**
     * @param list<SecureShare> $shares
     */
    public function setShares(array $shares): void
    {
        $this->shares = $shares;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): void
    {
        $this->total = $total;
    }
}
