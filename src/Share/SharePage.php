<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Share;

/**
 * One page of {@see ShareView}s with the total number of matching shares (2.1.0).
 */
final readonly class SharePage
{
    /**
     * @param list<ShareView> $items the shares of the page, in the requested order
     * @param int $total every matching share (all pages)
     * @param int $limit the page size asked for
     * @param int $offset the offset asked for
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $limit,
        public int $offset,
    ) {
    }

    /**
     * The ids of the page's shares, in order.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = [];
        foreach ($this->items as $item) {
            $ids[] = $item->id;
        }

        return $ids;
    }
}
