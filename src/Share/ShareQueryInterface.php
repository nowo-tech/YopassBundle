<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Share;

use InvalidArgumentException;

/**
 * Read port over the bundle's shares (2.1.0, DDD-host checklist B7): what an application lists or counts, by creator
 * id(s) and / or share ids, with a state filter, sorting and paging, so it never writes DQL or SQL over the bundle's
 * tables. Returns {@see ShareView}s (never the ciphertext). Service id: this interface.
 *
 * No access check is made here: the application decides who may read what (see {@see ShareAccessGuardInterface}).
 */
interface ShareQueryInterface
{
    /** Largest page {@see self::page()} accepts. */
    public const MAX_PAGE_SIZE = 1000;

    /**
     * The share with this id, or null when there is none (or the id is not a usable share id).
     */
    public function find(string $shareId): ?ShareView;

    /**
     * How many shares match.
     */
    public function count(ShareCriteria $criteria): int;

    /**
     * One page of the matching shares, in the criteria's order, with the total.
     *
     * @param int $limit 1 to {@see self::MAX_PAGE_SIZE}
     * @param int $offset 0 or more
     *
     * @throws InvalidArgumentException on a limit or offset out of range
     */
    public function page(ShareCriteria $criteria, int $limit, int $offset = 0): SharePage;

    /**
     * The ids of every matching share, in the criteria's order (for a scoped delete, an export, a sync).
     *
     * @return list<string>
     */
    public function ids(ShareCriteria $criteria): array;
}
