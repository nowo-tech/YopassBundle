<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use DateTimeImmutable;
use Nowo\YopassBundle\Share\ShareQueryInterface;
use Nowo\YopassBundle\Share\ShareView;

/**
 * Id-based reads and deletes a repository can serve natively (2.1.0), behind {@see ShareQueryInterface} and the id
 * commands: views are built from scalar columns, so the ciphertext is never loaded. Implemented by the Doctrine ORM
 * repository; for any other repository the bundle falls back to {@see ShareRepositoryInterface::find()} and
 * {@see CreatorIdShareRepositoryInterface} / `findByCreator()`.
 *
 * Both reads return only exact matches (byte for byte, whatever the column collation matched), in no particular order.
 */
interface ShareSummaryRepositoryInterface
{
    /**
     * The shares of these creators.
     *
     * @param list<string> $creatorIds
     *
     * @return list<ShareView>
     */
    public function viewsByCreatorIds(array $creatorIds, DateTimeImmutable $now): array;

    /**
     * The shares with these ids.
     *
     * @param list<string> $shareIds
     *
     * @return list<ShareView>
     */
    public function viewsByIds(array $shareIds, DateTimeImmutable $now): array;

    /**
     * Deletes the shares with these ids (no flush needed, no events) and returns the number this call removed: of two
     * concurrent calls for the same share, only one counts it.
     *
     * @param list<string> $shareIds
     */
    public function removeByIds(array $shareIds): int;
}
