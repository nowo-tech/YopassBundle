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
 *
 * {@see getPage()} / {@see getPageSize()} / {@see getOffset()} expose the requested page so an override can query
 * only that page (page size 0 = no pagination: return every share).
 */
final class ShareListQueryEvent extends Event
{
    /** @var list<SecureShare>|null */
    private ?array $overrideShares = null;

    private ?int $overrideTotal = null;

    private readonly int $page;

    private readonly int $pageSize;

    /**
     * Creates the event for the viewer, the subject used for list queries and the requested page.
     *
     * @param int $pageSize Shares per page (0 = no pagination)
     * @param int $page 1-based page number
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private object $listSubject,
        int $pageSize = 0,
        int $page = 1,
    ) {
        $this->pageSize = max(0, $pageSize);
        $this->page     = max(1, $page);
    }

    /**
     * Returns the requested 1-based page number (always 1 when the list is not paginated).
     */
    public function getPage(): int
    {
        return $this->pageSize > 0 ? $this->page : 1;
    }

    /**
     * Returns the number of shares per page (0 = no pagination).
     */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * Returns the 0-based offset of the requested page (0 when the list is not paginated).
     */
    public function getOffset(): int
    {
        return ($this->getPage() - 1) * $this->pageSize;
    }

    /**
     * Whether the manage list is paginated (list_page_size > 0).
     */
    public function isPaginated(): bool
    {
        return $this->pageSize > 0;
    }

    /**
     * Returns the user viewing the list.
     */
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

    /**
     * Replaces the subject passed to the repository creator queries.
     */
    public function setListSubject(object $listSubject): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->listSubject = $listSubject;
    }

    /**
     * Whether a listener provided shares that replace the repository query.
     */
    public function hasOverride(): bool
    {
        return $this->overrideShares !== null;
    }

    /**
     * Returns the listener-provided shares that replace the repository query, if any.
     *
     * @return list<SecureShare>
     */
    public function getOverrideShares(): array
    {
        return $this->overrideShares ?? [];
    }

    /**
     * Returns the listener-provided total that replaces the repository count, if any.
     */
    public function getOverrideTotal(): int
    {
        return $this->overrideTotal ?? 0;
    }

    /**
     * Skip the default repository query and use the provided shares instead.
     *
     * When {@see isPaginated()}, $shares should be the requested page only and $total the overall count.
     *
     * @param list<SecureShare> $shares
     */
    public function overrideList(array $shares, int $total): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->overrideShares = $shares;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->overrideTotal = $total;
    }
}
