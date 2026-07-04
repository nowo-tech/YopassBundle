<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Nowo\YopassBundle\Entity\SecureShare;

/**
 * Persistence port for {@see SecureShare} records across relational and document stores.
 */
interface ShareRepositoryInterface
{
    public function find(string $id): ?SecureShare;

    /**
     * @return list<SecureShare>
     */
    public function findByCreator(object $creator): array;

    public function countByCreator(object $creator): int;

    /**
     * @return list<SecureShare>
     */
    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array;

    public function removeByCreatorOlderThan(object $creator, \DateTimeImmutable $before): int;

    public function removeAllByCreator(object $creator): int;

    public function removeOlderThan(\DateTimeImmutable $before): int;

    public function persist(SecureShare $share): void;

    public function remove(SecureShare $share): void;

    public function flush(): void;
}
