<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use InvalidArgumentException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Ui\ShareListColumn;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

use function array_values;
use function sprintf;

/**
 * Dispatched after the manage list is loaded ({@see YopassEvents::SHARE_LIST_COLUMNS}): hosts add extra columns
 * (e.g. "Created by", "Shared with") without overriding the template. Columns are appended after the built-in ones;
 * their cell values are plain text escaped by Twig.
 */
final class ShareListColumnsEvent extends Event
{
    /** @var array<string, ShareListColumn> */
    private array $columns = [];

    /**
     * @param list<SecureShare> $shares Rows of the current page
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private readonly array $shares,
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
     * Returns the rows of the current page (for batch lookups in the host).
     *
     * @return list<SecureShare>
     */
    public function getShares(): array
    {
        return $this->shares;
    }

    /**
     * Adds an extra column (keys must be unique).
     */
    public function addColumn(ShareListColumn $column): void
    {
        if (isset($this->columns[$column->key])) {
            throw new InvalidArgumentException(sprintf('The Yopass list column "%s" was already added.', $column->key));
        }

        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->columns[$column->key] = $column;
    }

    /**
     * Returns the extra columns in insertion order.
     *
     * @return list<ShareListColumn>
     */
    public function getColumns(): array
    {
        return array_values($this->columns);
    }
}
