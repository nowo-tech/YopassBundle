<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;

/**
 * Persistence port for {@see SecureShare} records across relational and document stores.
 */
interface ShareRepositoryInterface
{
    /**
     * Finds a share by id.
     */
    public function find(string $id): ?SecureShare;

    /**
     * Atomically consumes one read when the share is available.
     *
     * Returns the updated share on success, or null when not found or not consumable.
     */
    public function consumeReadIfAvailable(string $id): ?SecureShare;

    /**
     * Returns all shares created by the given user.
     *
     * @return list<SecureShare>
     */
    public function findByCreator(object $creator): array;

    /**
     * Counts the shares created by the given user.
     */
    public function countByCreator(object $creator): int;

    /**
     * Returns one page of shares created by the given user.
     *
     * @return list<SecureShare>
     */
    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array;

    /**
     * Deletes the shares of the given user created before a date; returns the number removed.
     */
    public function removeByCreatorOlderThan(object $creator, DateTimeImmutable $before): int;

    /**
     * Deletes every share of the given user; returns the number removed.
     */
    public function removeAllByCreator(object $creator): int;

    /**
     * Deletes all shares created before a date; returns the number removed.
     */
    public function removeOlderThan(DateTimeImmutable $before): int;

    /**
     * Schedules a share for persistence.
     */
    public function persist(SecureShare $share): void;

    /**
     * Schedules a share for removal.
     */
    public function remove(SecureShare $share): void;

    /**
     * Writes pending share changes to storage.
     */
    public function flush(): void;
}
