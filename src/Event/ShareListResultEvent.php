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
     * Creates the event with the viewer, the listed shares and the total count.
     *
     * @param list<SecureShare> $shares
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private array $shares,
        private int $total,
    ) {
    }

    /**
     * Returns the user viewing the list.
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Returns the listed shares.
     *
     * @return list<SecureShare>
     */
    public function getShares(): array
    {
        return $this->shares;
    }

    /**
     * Replaces the listed shares.
     *
     * @param list<SecureShare> $shares
     */
    public function setShares(array $shares): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->shares = $shares;
    }

    /**
     * Returns the total number of shares (before pagination).
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Replaces the total number of shares.
     */
    public function setTotal(int $total): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->total = $total;
    }
}
